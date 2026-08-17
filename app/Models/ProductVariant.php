<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'product_id', 'shopify_variant_id', 'title', 'sku', 'price', 'inventory_item_id',
])]
class ProductVariant extends Model
{
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    protected function casts(): array
    {
        return [
            'shopify_variant_id' => 'string',
            'inventory_item_id' => 'string',
            'price' => 'decimal:4',
        ];
    }
}
