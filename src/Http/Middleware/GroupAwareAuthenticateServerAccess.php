<?php

namespace PelicanServerGroups\Http\Middleware;

use App\Http\Middleware\Api\Client\Server\AuthenticateServerAccess;
use App\Models\Server;
use App\Models\Subuser;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use PelicanServerGroups\Services\ServerGroupService;

class GroupAwareAuthenticateServerAccess extends AuthenticateServerAccess
{
    public function handle(Request $request, Closure $next): mixed
    {
        $user = $request->user();
        $server = $request->route()->parameter('server');

        if (!$user instanceof User || !$server instanceof Server || !ServerGroupService::hasGroupAccess($user, $server)) {
            return parent::handle($request, $next);
        }

        $wasLoaded = $server->relationLoaded('subusers');
        $originalSubusers = $wasLoaded ? $server->getRelation('subusers') : null;

        try {
            // Never mutate a collection that belongs to the server's original relation state.
            $subusers = $wasLoaded ? clone $originalSubusers : $server->subusers;

            if (!$subusers->contains('user_id', $user->getKey())) {
                $virtualSubuser = new Subuser([
                    'user_id' => $user->getKey(),
                    'server_id' => $server->getKey(),
                    'permissions' => ServerGroupService::permissionsFor($user, $server),
                ]);
                $virtualSubuser->exists = false;
                $subusers->push($virtualSubuser);
                $server->setRelation('subusers', $subusers);
            }

            return parent::handle($request, $next);
        } finally {
            if ($wasLoaded) {
                $server->setRelation('subusers', $originalSubusers);
            } else {
                $server->unsetRelation('subusers');
            }
        }
    }
}
