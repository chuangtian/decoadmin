<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id', 'store_id', 'shopify_customer_id', 'first_name', 'last_name',
    'email', 'phone', 'state', 'verified_email', 'orders_count', 'total_spent',
    'created_at_shopify', 'updated_at_shopify', 'synced_at',
])]
class Customer extends Model
{
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function scopeForOrganization(Builder $query, Organization|int $organization): void
    {
        $query->where('organization_id', $organization instanceof Organization ? $organization->getKey() : $organization);
    }

    public function scopeForStore(Builder $query, Store|int $store): void
    {
        $query->where('store_id', $store instanceof Store ? $store->getKey() : $store);
    }

    protected function casts(): array
    {
        return [
            'shopify_customer_id' => 'string',
            'verified_email' => 'boolean',
            'orders_count' => 'integer',
            'total_spent' => 'decimal:4',
            'created_at_shopify' => 'datetime',
            'updated_at_shopify' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }
}
