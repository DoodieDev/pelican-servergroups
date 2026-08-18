<?php

namespace PelicanServerGroups\Services;

use App\Models\Server;
use App\Models\User;
use App\Services\Servers\GetUserPermissionsService;

class GroupAwareGetUserPermissionsService extends GetUserPermissionsService
{
    public function __construct(private GetUserPermissionsService $baseService) {}

    /**
     * @return string[]
     */
    public function handle(Server $server, User $user): array
    {
        return array_values(array_unique(array_merge(
            $this->baseService->handle($server, $user),
            ServerGroupService::permissionsFor($user, $server),
        )));
    }
}
