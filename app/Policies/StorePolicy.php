<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use App\Support\CurrentOrganization;

class StorePolicy
{
    public function __construct(private CurrentOrganization $currentOrganization) {}

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'store.view');
    }

    public function view(User $user, Store $store): bool
    {
        return $this->allows($user, 'store.view', $store);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'store.create');
    }

    public function update(User $user, Store $store): bool
    {
        return $this->allows($user, 'store.update', $store);
    }

    public function delete(User $user, Store $store): bool
    {
        return $this->allows($user, 'store.delete', $store);
    }

    public function connect(User $user, Store $store): bool
    {
        return $this->allows($user, 'store.connect', $store);
    }

    public function disconnect(User $user, Store $store): bool
    {
        return $this->allows($user, 'store.disconnect', $store);
    }

    private function allows(User $user, string $permission, ?Store $store = null): bool
    {
        $organization = $this->currentOrganization->get() ?? $store?->organization;

        return $organization instanceof Organization
            && $user->hasPermission($permission, $organization, $store);
    }
}
