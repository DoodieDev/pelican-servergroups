<?php

namespace PelicanServerGroups\Services;

use App\Enums\SubuserPermission;
use App\Models\Server;
use App\Models\Subuser;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use LogicException;
use PelicanServerGroups\Models\ServerGroup;
use PelicanServerGroups\Models\ServerGroupMember;
use PelicanServerGroups\Models\ServerGroupUser;

final class ServerGroupService
{
    /**
     * Return servers available to a root administrator for global group management.
     *
     * @return Builder<Server>
     */
    public static function accessibleServers(User $user): Builder
    {
        Gate::forUser($user)->authorize('viewAny', ServerGroup::class);

        return Server::query()
            ->select('servers.*')
            ->whereIn('servers.node_id', $user->accessibleNodes()->select('nodes.id'))
            ->orderBy('servers.name')
            ->orderBy('servers.id');
    }

    /**
     * @return array<int, int>
     */
    public static function accessibleServerIds(User $user): array
    {
        return array_map(
            static fn (mixed $id): int => (int) $id,
            static::accessibleServers($user)->pluck('servers.id')->all(),
        );
    }

    /**
     * @return array<int, int>
     */
    public static function memberIds(ServerGroup $group, User $user): array
    {
        Gate::forUser($user)->authorize('view', $group);

        return array_map(
            static fn (mixed $id): int => (int) $id,
            ServerGroupMember::query()
                ->where('group_id', $group->getKey())
                ->orderBy('position')
                ->orderBy('server_id')
                ->pluck('server_id')
                ->all(),
        );
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, ServerGroupUser>
     */
    public static function userAccess(ServerGroup $group, User $user): \Illuminate\Database\Eloquent\Collection
    {
        Gate::forUser($user)->authorize('view', $group);

        return ServerGroupUser::query()
            ->where('group_id', $group->getKey())
            ->with('user')
            ->orderBy('user_id')
            ->get();
    }

    /**
     * Replace the memberships visible to the root administrator.
     *
     * @param array<int, mixed> $serverIds
     */
    public static function replaceMembers(ServerGroup $group, array $serverIds, User $user): void
    {
        static::ensurePersisted($group);
        Gate::forUser($user)->authorize('update', $group);

        $accessibleIds = static::accessibleServerIds($user);
        $requestedIds = array_values(array_unique(array_map(
            static fn (mixed $id): int => (int) $id,
            $serverIds,
        )));
        $selectedIds = array_values(array_intersect($requestedIds, $accessibleIds));
        $groupId = (int) $group->getKey();
        $affectedGroupIds = ServerGroupMember::query()
            ->where(static function (Builder $query) use ($groupId, $selectedIds): void {
                $query->where('group_id', $groupId);

                if (count($selectedIds) > 0) {
                    $query->orWhereIn('server_id', $selectedIds);
                }
            })
            ->pluck('group_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->push($groupId)
            ->unique()
            ->values()
            ->all();

        DB::transaction(function () use ($accessibleIds, $groupId, $selectedIds): void {
            if (count($accessibleIds) > 0) {
                $removedMembers = ServerGroupMember::query()
                    ->where('group_id', $groupId)
                    ->whereIn('server_id', $accessibleIds);

                if (count($selectedIds) > 0) {
                    $removedMembers->whereNotIn('server_id', $selectedIds);
                }

                $removedMembers->delete();
            }

            if (count($selectedIds) === 0) {
                return;
            }

            ServerGroupMember::query()
                ->whereIn('server_id', $selectedIds)
                ->where('group_id', '!=', $groupId)
                ->delete();

            foreach ($selectedIds as $position => $serverId) {
                ServerGroupMember::query()->updateOrCreate(
                    ['server_id' => $serverId],
                    [
                        'group_id' => $groupId,
                        'position' => $position,
                    ],
                );
            }
        });

        ServerGroup::query()
            ->whereIn('id', $affectedGroupIds)
            ->orderBy('id')
            ->get()
            ->each(static function (ServerGroup $affectedGroup) use ($user): void {
                ServerGroupSubuserSynchronizer::synchronizeGroup($affectedGroup, $user);
            });
    }

    /**
     * Replace the per-user permissions for a group.
     *
     * @param array<int|string, array<int, mixed>> $userAccess
     */
    public static function replaceUserAccess(ServerGroup $group, array $userAccess, User $user): void
    {
        static::ensurePersisted($group);
        Gate::forUser($user)->authorize('update', $group);

        $normalized = [];

        foreach ($userAccess as $userId => $permissions) {
            $userId = (int) $userId;

            if ($userId <= 0 || !is_array($permissions)) {
                continue;
            }

            $normalized[$userId] = static::normalizePermissions($permissions);
        }

        $validUserIds = count($normalized) > 0
            ? User::query()
                ->whereIn('id', array_keys($normalized))
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all()
            : [];
        $groupId = (int) $group->getKey();

        DB::transaction(function () use ($groupId, $normalized, $validUserIds): void {
            $existing = ServerGroupUser::query()->where('group_id', $groupId);

            if (count($validUserIds) > 0) {
                $existing->whereNotIn('user_id', $validUserIds);
            }

            $existing->delete();

            foreach ($validUserIds as $userId) {
                ServerGroupUser::query()->updateOrCreate(
                    [
                        'group_id' => $groupId,
                        'user_id' => $userId,
                    ],
                    [
                        'permissions' => $normalized[$userId] ?? [],
                    ],
                );
            }
        });

        ServerGroupSubuserSynchronizer::synchronizeGroup($group, $user);
    }

    public static function deleteGroup(ServerGroup $group, User $user): void
    {
        static::ensurePersisted($group);
        Gate::forUser($user)->authorize('delete', $group);

        DB::transaction(function () use ($group, $user): void {
            ServerGroupSubuserSynchronizer::detachGroup($group, $user);
            $group->delete();
        });
    }

    public static function synchronizeAll(User $user): void
    {
        ServerGroupSubuserSynchronizer::synchronizeAll($user);
    }

    public static function detachAll(User $user): void
    {
        ServerGroupSubuserSynchronizer::detachAll($user);
    }

    /**
     * @param array<int, mixed> $permissions
     * @return array<int, string>
     */
    public static function normalizePermissions(array $permissions, bool $includeWebsocket = true): array
    {
        $validPermissions = array_fill_keys(Subuser::allPermissionKeys(), true);
        $normalized = [];

        foreach ($permissions as $permission) {
            if ($permission instanceof SubuserPermission) {
                $permission = $permission->value;
            }

            if (!is_string($permission) || !isset($validPermissions[$permission])) {
                continue;
            }

            $normalized[] = $permission;
        }

        if ($includeWebsocket) {
            $normalized[] = SubuserPermission::WebsocketConnect->value;
        }
        $normalized = array_values(array_unique($normalized));
        sort($normalized);

        return $normalized;
    }

    /**
     * Add grouping fields without changing the core Server model or multiplying rows.
     *
     * @param Builder<Server> $query
     * @return Builder<Server>
     */
    public static function applyGroupingData(Builder $query): Builder
    {
        $columns = $query->getQuery()->columns ?? [];

        if (in_array('server_group_order.server_group_id', $columns, true)) {
            return $query;
        }

        $membershipQuery = DB::table('pelican_server_group_members as members')
            ->join('pelican_server_groups as groups', 'groups.id', '=', 'members.group_id')
            ->select([
                'members.server_id',
                'groups.sort_order as group_sort_order',
                'groups.id as server_group_id',
                'groups.name as server_group_name',
                'groups.color as server_group_color',
                'members.position',
            ]);

        return $query
            ->leftJoinSub($membershipQuery, 'server_group_order', function (JoinClause $join): void {
                $join->on('servers.id', '=', 'server_group_order.server_id');
            })
            ->addSelect([
                'server_group_order.server_group_id',
                'server_group_order.server_group_name',
                'server_group_order.server_group_color',
            ]);
    }

    public static function applyOrdering(Builder $query, string $direction = 'desc'): Builder
    {
        return static::applyGroupOrdering($query, $direction);
    }

    public static function applyGroupOrdering(Builder $query, string $direction = 'desc'): Builder
    {
        $direction = strtolower($direction) === 'asc' ? 'asc' : 'desc';

        return static::applyGroupingData($query)
            ->orderByRaw("COALESCE(server_group_order.group_sort_order, -1) {$direction}")
            ->orderBy("server_group_order.server_group_id", $direction)
            ->orderBy('server_group_order.position')
            ->orderBy('servers.name')
            ->orderBy('servers.id');
    }

    private static function ensurePersisted(ServerGroup $group): void
    {
        if (!$group->exists || $group->getKey() === null) {
            throw new LogicException('The server group must be persisted before its members or grants can be changed.');
        }
    }
}
