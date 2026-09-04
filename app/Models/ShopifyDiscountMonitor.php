<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'organization_id', 'store_id', 'shopify_discount_id', 'is_enabled', 'baseline_pending',
    'countdown_state', 'last_snapshot_hash', 'last_checked_at', 'last_seen_at', 'last_error',
    'last_error_at', 'created_by', 'updated_by',
])]
class ShopifyDiscountMonitor extends Model
{
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(ShopifyDiscountSnapshot::class, 'monitor_id');
    }

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'baseline_pending' => 'boolean',
            'countdown_state' => 'array',
            'last_checked_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'last_error_at' => 'datetime',
        ];
    }
}
