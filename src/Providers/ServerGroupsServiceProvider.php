<?php

namespace PelicanServerGroups\Providers;

use App\Http\Middleware\Api\Client\Server\AuthenticateServerAccess;
use App\Services\Servers\GetUserPermissionsService;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Passkeys\Passkeys;
use PelicanServerGroups\Http\Middleware\GroupAwareAuthenticateServerAccess;
use PelicanServerGroups\Models\GroupAwareUser;
use PelicanServerGroups\Models\ServerGroup;
use PelicanServerGroups\Policies\ServerGroupPolicy;
use PelicanServerGroups\Services\GroupAwareGetUserPermissionsService;

class ServerGroupsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Gate::policy(ServerGroup::class, ServerGroupPolicy::class);

        config()->set('auth.providers.users.model', GroupAwareUser::class);

        $this->app->extend(
            GetUserPermissionsService::class,
            static fn (GetUserPermissionsService $service): GroupAwareGetUserPermissionsService => new GroupAwareGetUserPermissionsService($service),
        );

        $this->app->bind(AuthenticateServerAccess::class, GroupAwareAuthenticateServerAccess::class);
    }

    public function boot(): void
    {
        $this->app->booted(static function (): void {
            Passkeys::useUserModel(GroupAwareUser::class);
            Relation::morphMap([
                'user' => GroupAwareUser::class,
            ]);
        });
    }
}
