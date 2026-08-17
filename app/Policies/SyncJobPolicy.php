<?php

namespace App\Policies;

use App\Models\Store;
use App\Models\SyncJob;
use App\Models\User;
use App\Support\CurrentOrganization;

class SyncJobPolicy
{
    public function __construct(private CurrentOrganization $currentOrganization) {}

    public function viewAny(User $user): bool
    {
        $organization = $this->currentOrganization->get();

        return $organization && $user->hasPermission('sync.view', $organization);
    }

    public function view(User $user, SyncJob $syncJob): bool
    {
        return $this->allowsForStore($user, $syncJob->store, 'sync.view')
            && $syncJob->organization_id === $this->currentOrganization->get()?->getKey();
    }

    public function create(User $user, Store $store): bool
    {
        return $this->allowsForStore($user, $store, 'sync.run');
    }

    private function allowsForStore(User $user, ?Store $store, string $permission): bool
    {
        $organization = $this->currentOrganization->get();

        return $organization
            && $store
            && $store->organization_id === $organization->getKey()
            && $user->hasPermission($permission, $organization, $store);
    }
}
