<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'organization_id', 'store_id', 'shopify_order_id', 'shopify_customer_id', 'order_number', 'email',
    'financial_status', 'fulfillment_status', 'sales_channel', 'shopify_order_app_id', 'sales_channel_name',
    'pos_location_id', 'pos_location_name', 'pos_staff_id', 'pos_staff_name', 'currency', 'total_price', 'subtotal_price',
    'net_sales', 'discount_total', 'refund_total', 'shipping_total', 'total_tax', 'is_test',
    'processed_at', 'cancelled_at', 'created_at_shopify', 'synced_at',
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
            'shopify_customer_id' => 'string',
            'shopify_order_app_id' => 'string',
            'pos_location_id' => 'string',
            'pos_staff_id' => 'string',
            'total_price' => 'decimal:4',
            'subtotal_price' => 'decimal:4',
            'net_sales' => 'decimal:4',
            'discount_total' => 'decimal:4',
            'refund_total' => 'decimal:4',
            'shipping_total' => 'decimal:4',
            'total_tax' => 'decimal:4',
            'is_test' => 'boolean',
            'processed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'created_at_shopify' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }
}
