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

    public function create(User $user, Store $store, ?string $type = null): bool
    {
        if (! $this->allowsForStore($user, $store, 'sync.run')) {
            return false;
        }

        $typePermission = match ($type) {
            'products' => 'products.sync',
            'orders' => 'orders.sync',
            'customers' => 'customers.sync',
            'inventory' => 'inventory.sync',
            default => null,
        };

        if ($typePermission !== null) {
            $organization = $this->currentOrganization->get();

            return $organization && $user->hasPermission($typePermission, $organization, $store);
        }

        return true;
    }

    public function retry(User $user, SyncJob $syncJob): bool
    {
        return $this->allowsForStore($user, $syncJob->store, 'sync.retry')
            && $syncJob->organization_id === $this->currentOrganization->get()?->getKey();
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
