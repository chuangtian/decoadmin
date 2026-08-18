<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;
use App\Support\CurrentOrganization;

class RolePolicy
{
    public function __construct(private CurrentOrganization $currentOrganization) {}

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'roles.view');
    }

    public function view(User $user, Role $role): bool
    {
        return $this->allows($user, 'roles.view') && $this->contains($role);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'roles.create');
    }

    public function update(User $user, Role $role): bool
    {
        return $this->allows($user, 'roles.update') && $this->contains($role);
    }

    public function delete(User $user, Role $role): bool
    {
        return ! $role->is_system && $this->allows($user, 'roles.delete') && $this->contains($role);
    }

    public function assign(User $user, Role $role): bool
    {
        return $this->allows($user, 'roles.assign') && $this->contains($role);
    }

    private function allows(User $user, string $permission): bool
    {
        $organization = $this->currentOrganization->get();

        return $organization && $user->hasPermission($permission, $organization);
    }

    private function contains(Role $role): bool
    {
        return $role->organization_id === $this->currentOrganization->get()?->getKey();
    }
}
