<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'inventory_item_id', 'location_id', 'shopify_location_id', 'available',
    'updated_at_shopify', 'synced_at',
])]
class InventoryLevel extends Model
{
    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    protected function casts(): array
    {
        return [
            'shopify_location_id' => 'string',
            'available' => 'integer',
            'updated_at_shopify' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }
}
