<?php

namespace App\Models\Concerns;

use App\Models\Organization;
use App\Models\Store;
use Illuminate\Database\Eloquent\Builder;

trait ScopesToOrganizationStore
{
    public function scopeForOrganization(Builder $query, Organization|int $organization): void
    {
        $query->where('organization_id', $organization instanceof Organization ? $organization->getKey() : $organization);
    }

    public function scopeForStore(Builder $query, Store|int $store): void
    {
        $query->where('store_id', $store instanceof Store ? $store->getKey() : $store);
    }
}
