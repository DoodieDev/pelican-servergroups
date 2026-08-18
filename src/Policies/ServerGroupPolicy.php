<?php

namespace PelicanServerGroups\Policies;

use App\Models\User;
use PelicanServerGroups\Models\ServerGroup;

class ServerGroupPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isRootAdministrator($user);
    }

    public function view(User $user, ServerGroup $group): bool
    {
        return $this->isRootAdministrator($user);
    }

    public function create(User $user): bool
    {
        return $this->isRootAdministrator($user);
    }

    public function update(User $user, ServerGroup $group): bool
    {
        return $this->isRootAdministrator($user);
    }

    public function delete(User $user, ServerGroup $group): bool
    {
        return $this->isRootAdministrator($user);
    }

    public function deleteAny(User $user): bool
    {
        return $this->isRootAdministrator($user);
    }

    public function reorder(User $user): bool
    {
        return $this->isRootAdministrator($user);
    }

    public function forceDelete(User $user, ServerGroup $group): bool
    {
        return $this->isRootAdministrator($user);
    }

    public function forceDeleteAny(User $user): bool
    {
        return $this->isRootAdministrator($user);
    }

    public function restore(User $user, ServerGroup $group): bool
    {
        return $this->isRootAdministrator($user);
    }

    public function restoreAny(User $user): bool
    {
        return $this->isRootAdministrator($user);
    }

    public function replicate(User $user, ServerGroup $group): bool
    {
        return $this->isRootAdministrator($user);
    }

    private function isRootAdministrator(User $user): bool
    {
        return $user->isRootAdmin();
    }
}
