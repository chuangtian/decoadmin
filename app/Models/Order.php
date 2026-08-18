<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'organization_id', 'store_id', 'shopify_order_id', 'order_number', 'email',
    'financial_status', 'fulfillment_status', 'currency', 'total_price', 'subtotal_price',
    'total_tax', 'processed_at', 'created_at_shopify', 'synced_at',
])]
class Order extends Model
{
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
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
            'shopify_order_id' => 'string',
            'total_price' => 'decimal:4',
            'subtotal_price' => 'decimal:4',
            'total_tax' => 'decimal:4',
            'processed_at' => 'datetime',
            'created_at_shopify' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }
}
