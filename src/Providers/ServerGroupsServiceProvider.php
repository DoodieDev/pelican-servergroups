<?php

namespace PelicanServerGroups\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use PelicanServerGroups\Models\ServerGroup;
use PelicanServerGroups\Policies\ServerGroupPolicy;

class ServerGroupsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Gate::policy(ServerGroup::class, ServerGroupPolicy::class);
    }
}
