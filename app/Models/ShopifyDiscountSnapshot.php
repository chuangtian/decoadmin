<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'monitor_id', 'organization_id', 'store_id', 'shopify_discount_id', 'content_hash',
    'snapshot', 'captured_at',
])]
class ShopifyDiscountSnapshot extends Model
{
    public function monitor(): BelongsTo
    {
        return $this->belongsTo(ShopifyDiscountMonitor::class, 'monitor_id');
    }

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'captured_at' => 'datetime',
        ];
    }
}
