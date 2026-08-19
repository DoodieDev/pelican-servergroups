<?php

namespace PelicanServerGroups\Services;

use App\Models\Server;
use App\Models\Subuser;
use App\Models\User;
use App\Services\Subusers\SubuserCreationService;
use App\Services\Subusers\SubuserDeletionService;
use App\Services\Subusers\SubuserUpdateService;
use Illuminate\Support\Facades\Gate;
use PelicanServerGroups\Models\ServerGroup;
use PelicanServerGroups\Models\ServerGroupMember;
use PelicanServerGroups\Models\ServerGroupSubuser;
use PelicanServerGroups\Models\ServerGroupUser;

final class ServerGroupSubuserSynchronizer
{
    public static function synchronizeGroup(ServerGroup $group, User $actor): void
    {
        Gate::forUser($actor)->authorize('update', $group);

        $memberIds = ServerGroupMember::query()
            ->where('group_id', $group->getKey())
            ->orderBy('position')
            ->orderBy('server_id')
            ->pluck('server_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        $grants = ServerGroupUser::query()
            ->where('group_id', $group->getKey())
            ->orderBy('user_id')
            ->get();

        $desired = [];
        $userIds = [];
        $serverIds = [];

        foreach ($grants as $grant) {
            $userId = (int) $grant->user_id;
            $permissions = ServerGroupService::normalizePermissions($grant->permissions ?? []);
            $userIds[$userId] = $userId;

            foreach ($memberIds as $serverId) {
                $serverIds[$serverId] = $serverId;
                $desired[static::pairKey($userId, $serverId)] = [
                    'user_id' => $userId,
                    'server_id' => $serverId,
                    'permissions' => $permissions,
                ];
            }
        }

        $mappings = ServerGroupSubuser::query()
            ->where('group_id', $group->getKey())
            ->get()
            ->keyBy(static fn (ServerGroupSubuser $mapping): string => static::pairKey($mapping->user_id, $mapping->server_id));

        foreach ($mappings as $key => $mapping) {
            if (!array_key_exists($key, $desired)) {
                static::detachMapping($mapping);
            }
        }

        if (count($desired) === 0) {
            return;
        }

        $users = User::query()
            ->whereIn('id', array_values($userIds))
            ->get()
            ->keyBy(static fn (User $user): int => (int) $user->getKey());
        $servers = Server::query()
            ->whereIn('id', array_values($serverIds))
            ->get()
            ->keyBy(static fn (Server $server): int => (int) $server->getKey());

        foreach ($desired as $key => $access) {
            $user = $users->get($access['user_id']);
            $server = $servers->get($access['server_id']);

            if (!$user instanceof User || !$server instanceof Server) {
                continue;
            }

            static::synchronizePair(
                $group,
                $user,
                $server,
                $access['permissions'],
                $mappings->get($key),
            );
        }
    }

    public static function detachGroup(ServerGroup $group, User $actor): void
    {
        Gate::forUser($actor)->authorize('delete', $group);

        ServerGroupSubuser::query()
            ->where('group_id', $group->getKey())
            ->orderBy('id')
            ->get()
            ->each(static function (ServerGroupSubuser $mapping): void {
                static::detachMapping($mapping);
            });
    }

    public static function synchronizeAll(User $actor): void
    {
        Gate::forUser($actor)->authorize('viewAny', ServerGroup::class);

        ServerGroup::query()
            ->orderBy('id')
            ->get()
            ->each(static function (ServerGroup $group) use ($actor): void {
                static::synchronizeGroup($group, $actor);
            });
    }

    public static function detachAll(User $actor): void
    {
        Gate::forUser($actor)->authorize('viewAny', ServerGroup::class);

        ServerGroupSubuser::query()
            ->orderBy('id')
            ->get()
            ->each(static function (ServerGroupSubuser $mapping): void {
                static::detachMapping($mapping);
            });
    }

    private static function synchronizePair(
        ServerGroup $group,
        User $user,
        Server $server,
        array $groupPermissions,
        ?ServerGroupSubuser $mapping,
    ): void {
        if ((int) $server->owner_id === (int) $user->getKey()) {
            if ($mapping instanceof ServerGroupSubuser) {
                if ($mapping->created_by_plugin) {
                    static::detachMapping($mapping);
                } else {
                    $mapping->delete();
                }
            }

            return;
        }

        $subuser = Subuser::query()
            ->where('user_id', $user->getKey())
            ->where('server_id', $server->getKey())
            ->first();

        if (!$subuser instanceof Subuser) {
            $mapping?->delete();

            $subuser = app(SubuserCreationService::class)->handle(
                $server,
                $user->email,
                $groupPermissions,
            );

            ServerGroupSubuser::query()->create([
                'group_id' => $group->getKey(),
                'user_id' => $user->getKey(),
                'server_id' => $server->getKey(),
                'subuser_id' => $subuser->getKey(),
                'group_permissions' => $groupPermissions,
                'original_permissions' => null,
                'created_by_plugin' => true,
            ]);

            return;
        }

        if (!$mapping instanceof ServerGroupSubuser) {
            $mapping = new ServerGroupSubuser([
                'group_id' => $group->getKey(),
                'user_id' => $user->getKey(),
                'server_id' => $server->getKey(),
                'subuser_id' => $subuser->getKey(),
                'original_permissions' => static::storedPermissions($subuser->permissions),
                'created_by_plugin' => false,
            ]);
        }

        $originalPermissions = $mapping->original_permissions ?? [];
        $desiredPermissions = $mapping->created_by_plugin
            ? $groupPermissions
            : ServerGroupService::normalizePermissions(array_merge($originalPermissions, $groupPermissions));
        $currentPermissions = static::storedPermissions($subuser->permissions);

        if ($currentPermissions !== $desiredPermissions) {
            $subuser->setAttribute('permissions', $currentPermissions);
            app(SubuserUpdateService::class)->handle($subuser, $server, $desiredPermissions);
        }

        $mapping->fill([
            'subuser_id' => $subuser->getKey(),
            'group_permissions' => $groupPermissions,
        ]);
        $mapping->save();
    }

    private static function detachMapping(ServerGroupSubuser $mapping): void
    {
        $subuser = Subuser::query()->find($mapping->subuser_id);
        $server = Server::query()->find($mapping->server_id);

        if (!$subuser instanceof Subuser || !$server instanceof Server) {
            $mapping->delete();

            return;
        }

        if ($mapping->created_by_plugin) {
            app(SubuserDeletionService::class)->handle($subuser, $server);
        } else {
            $subuser->setAttribute('permissions', static::storedPermissions($subuser->permissions));
            app(SubuserUpdateService::class)->handle(
                $subuser,
                $server,
                $mapping->original_permissions ?? [],
            );
        }

        $mapping->delete();
    }

    /**
     * Preserve direct permissions without adding the group-only websocket default.
     *
     * @param mixed $permissions
     * @return array<int, string>
     */
    private static function storedPermissions(mixed $permissions): array
    {
        if (!is_array($permissions)) {
            return [];
        }

        $permissions = array_values(array_filter(
            array_map(
                static fn (mixed $permission): mixed => $permission instanceof \BackedEnum ? $permission->value : $permission,
                $permissions,
            ),
            static fn (mixed $permission): bool => is_string($permission),
        ));
        $permissions = array_values(array_unique($permissions));
        sort($permissions);

        return $permissions;
    }

    private static function pairKey(int $userId, int $serverId): string
    {
        return $userId . ':' . $serverId;
    }
}
