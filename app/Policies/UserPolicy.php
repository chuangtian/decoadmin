<?php

namespace App\Policies;

use App\Models\User;
use App\Support\CurrentOrganization;

class UserPolicy
{
    public function __construct(private CurrentOrganization $currentOrganization) {}

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'users.view');
    }

    public function view(User $user, User $target): bool
    {
        return $this->allows($user, 'users.view') && $this->contains($target);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'users.create');
    }

    public function update(User $user, User $target): bool
    {
        return $this->allows($user, 'users.update') && $this->contains($target);
    }

    public function delete(User $user, User $target): bool
    {
        return $user->isNot($target) && $this->allows($user, 'users.delete') && $this->contains($target);
    }

    public function assignRole(User $user, User $target): bool
    {
        return $this->allows($user, 'users.assign_role') && $this->contains($target);
    }

    private function allows(User $user, string $permission): bool
    {
        $organization = $this->currentOrganization->get();

        return $organization && $user->hasPermission($permission, $organization);
    }

    private function contains(User $target): bool
    {
        $organization = $this->currentOrganization->get();

        return $organization && $target->organizations()->whereKey($organization->getKey())->exists();
    }
}
