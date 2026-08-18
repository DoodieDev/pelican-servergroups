<?php

namespace PelicanServerGroups\Services;

use App\Enums\SubuserPermission;
use App\Models\Server;
use App\Models\Subuser;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use PelicanServerGroups\Models\ServerGroup;
use PelicanServerGroups\Models\ServerGroupMember;
use PelicanServerGroups\Models\ServerGroupUser;

final class ServerGroupService
{
    /**
     * Return only servers on nodes available to the authenticated administrator.
     *
     * @return Builder<Server>
     */
    public static function accessibleServers(?User $user = null): Builder
    {
        $user ??= auth()->user();

        if (!$user instanceof User || !$user->isAdmin()) {
            return Server::query()->whereKey(-1);
        }

        return Server::query()
            ->select('servers.*')
            ->whereIn('servers.node_id', $user->accessibleNodes()->select('nodes.id'))
            ->orderBy('servers.name')
            ->orderBy('servers.id');
    }

    /**
     * @return array<int, int>
     */
    public static function accessibleServerIds(?User $user = null): array
    {
        return array_map(
            static fn (mixed $id): int => (int) $id,
            static::accessibleServers($user)->pluck('servers.id')->all(),
        );
    }

    /**
     * @return array<int, int>
     */
    public static function memberIds(ServerGroup $group): array
    {
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
    public static function userAccess(ServerGroup $group): \Illuminate\Database\Eloquent\Collection
    {
        return ServerGroupUser::query()
            ->where('group_id', $group->getKey())
            ->with('user')
            ->orderBy('user_id')
            ->get();
    }

    /**
     * Replace the memberships visible to the current administrator.
     * Memberships outside that administrator's node scope are left untouched.
     *
     * @param array<int, mixed> $serverIds
     */
    public static function replaceMembers(ServerGroup $group, array $serverIds, ?User $user = null): void
    {
        $user ??= auth()->user();

        if (!$user instanceof User || !$user->isAdmin()) {
            return;
        }

        $accessibleIds = static::accessibleServerIds($user);
        $requestedIds = array_values(array_unique(array_map(
            static fn (mixed $id): int => (int) $id,
            $serverIds,
        )));
        $selectedIds = array_values(array_intersect($requestedIds, $accessibleIds));
        $groupId = (int) $group->getKey();

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
    }

    /**
     * Replace the per-user permissions for a group.
     *
     * @param array<int|string, array<int, mixed>> $userAccess
     */
    public static function replaceUserAccess(ServerGroup $group, array $userAccess, ?User $user = null): void
    {
        $user ??= auth()->user();

        if (!$user instanceof User || !$user->isAdmin()) {
            return;
        }

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
    }

    public static function includeGroupServers(Builder $query, User $user): Builder
    {
        $baseQuery = clone $query;
        $baseQuery->select('servers.id');

        return Server::query()
            ->select('servers.*')
            ->where(static function (Builder $query) use ($baseQuery, $user): void {
                $query
                    ->whereIn('servers.id', $baseQuery)
                    ->orWhereIn('servers.id', static::groupServerIdsQuery($user));
            });
    }

    public static function hasGroupAccess(User $user, Server $server): bool
    {
        return Context::remember(
            "server-groups.users.{$user->getKey()}.servers.{$server->getKey()}.access",
            static fn (): bool => ServerGroupUser::query()
                ->where('user_id', $user->getKey())
                ->whereHas('group.members', static fn (Builder $query) => $query->where('server_id', $server->getKey()))
                ->exists(),
        );
    }

    /**
     * @return array<int, string>
     */
    public static function permissionsFor(User $user, Server $server): array
    {
        return Context::remember(
            "server-groups.users.{$user->getKey()}.servers.{$server->getKey()}.permissions",
            static function () use ($user, $server): array {
                $permissions = [];
                $accessRecords = ServerGroupUser::query()
                    ->where('user_id', $user->getKey())
                    ->whereHas('group.members', static fn (Builder $query) => $query->where('server_id', $server->getKey()))
                    ->get();

                if ($accessRecords->isEmpty()) {
                    return [];
                }

                foreach ($accessRecords as $access) {
                    $permissions = array_merge($permissions, $access->permissions ?? []);
                }

                return static::normalizePermissions($permissions);
            },
        );
    }

    /**
     * @param array<int, mixed> $permissions
     * @return array<int, string>
     */
    public static function normalizePermissions(array $permissions): array
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

        $normalized[] = SubuserPermission::WebsocketConnect->value;
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

    /**
     * @return Builder<ServerGroupMember>
     */
    private static function groupServerIdsQuery(User $user): Builder
    {
        return ServerGroupMember::query()
            ->whereIn(
                'group_id',
                ServerGroupUser::query()
                    ->select('group_id')
                    ->where('user_id', $user->getKey()),
            )
            ->select('server_id');
    }
}
