<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'product_id', 'shopify_variant_id', 'title', 'sku', 'price', 'compare_at_price',
    'available_for_sale', 'selected_options', 'image_url', 'image_alt', 'image_width',
    'image_height', 'inventory_item_id',
])]
class ProductVariant extends Model
{
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function inventoryItem(): HasOne
    {
        return $this->hasOne(InventoryItem::class, 'variant_id');
    }

    protected function casts(): array
    {
        return [
            'shopify_variant_id' => 'string',
            'inventory_item_id' => 'string',
            'price' => 'decimal:4',
            'compare_at_price' => 'decimal:4',
            'available_for_sale' => 'boolean',
            'selected_options' => 'array',
            'image_width' => 'integer',
            'image_height' => 'integer',
        ];
    }
}
