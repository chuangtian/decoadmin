<?php

namespace App\Policies;

use App\Models\App;
use App\Models\User;
use App\Support\CurrentOrganization;

class AppPolicy
{
    public function __construct(private CurrentOrganization $currentOrganization) {}

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'apps.view');
    }

    public function view(User $user, App $app): bool
    {
        return $this->allows($user, 'apps.view') && $this->contains($user, $app);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'apps.create');
    }

    public function update(User $user, App $app): bool
    {
        return $this->allows($user, 'apps.update') && $this->contains($user, $app);
    }

    public function install(User $user, App $app): bool
    {
        return $this->allows($user, 'apps.install') && $this->contains($user, $app);
    }

    public function configure(User $user, App $app): bool
    {
        return $this->allows($user, 'apps.configure') && $this->contains($user, $app);
    }

    public function uninstall(User $user, App $app): bool
    {
        return $this->allows($user, 'apps.uninstall') && $this->contains($user, $app);
    }

    private function allows(User $user, string $permission): bool
    {
        $organization = $this->currentOrganization->get();

        return $organization && $user->hasPermission($permission, $organization);
    }

    private function contains(User $user, App $app): bool
    {
        return $app->organization_id === null
            ? $user->isSuperAdmin()
            : $app->organization_id === $this->currentOrganization->get()?->getKey();
    }
}
