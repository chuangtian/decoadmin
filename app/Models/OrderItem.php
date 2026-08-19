<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'order_id', 'shopify_line_item_id', 'product_id', 'variant_id', 'shopify_product_id',
    'shopify_variant_id', 'title', 'quantity', 'current_quantity', 'price', 'attributed_sales',
    'shopify_staff_id', 'staff_name',
])]
class OrderItem extends Model
{
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    protected function casts(): array
    {
        return [
            'shopify_line_item_id' => 'string',
            'shopify_product_id' => 'string',
            'shopify_variant_id' => 'string',
            'shopify_staff_id' => 'string',
            'quantity' => 'integer',
            'current_quantity' => 'integer',
            'price' => 'decimal:4',
            'attributed_sales' => 'decimal:4',
        ];
    }
}
