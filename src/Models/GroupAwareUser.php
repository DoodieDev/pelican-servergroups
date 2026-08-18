<?php

namespace PelicanServerGroups\Models;

use App\Enums\SubuserPermission;
use App\Models\Server;
use App\Models\Subuser;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use PelicanServerGroups\Services\ServerGroupService;

class GroupAwareUser extends User
{
    protected $table = 'users';

    public function accessibleServers(): Builder
    {
        return ServerGroupService::includeGroupServers(parent::accessibleServers(), $this);
    }

    public function directAccessibleServers(): Builder
    {
        return ServerGroupService::includeGroupServers(parent::directAccessibleServers(), $this);
    }

    public function canAccessTenant(Model $tenant): bool
    {
        if ($tenant instanceof Server && ServerGroupService::hasGroupAccess($this, $tenant)) {
            return true;
        }

        return parent::canAccessTenant($tenant);
    }

    public function can($abilities, mixed $arguments = []): bool
    {
        if ($arguments instanceof Server && ServerGroupService::hasGroupAccess($this, $arguments)) {
            if ($abilities === 'view') {
                return true;
            }

            $permission = static::permissionValue($abilities);

            if ($permission !== null && in_array($permission, ServerGroupService::permissionsFor($this, $arguments), true)) {
                return true;
            }
        }

        return parent::can($abilities, $arguments);
    }

    private static function permissionValue(mixed $abilities): ?string
    {
        if ($abilities instanceof SubuserPermission) {
            return $abilities->value;
        }

        if (is_string($abilities) && Subuser::doesPermissionExist($abilities)) {
            return $abilities;
        }

        return null;
    }
}
