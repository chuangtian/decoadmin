<?php

namespace App\Models;

use App\Enums\PersonalizationProductOverrideType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id', 'store_id', 'strategy_id', 'product_id', 'shopify_product_id',
    'shopify_product_gid', 'shopify_variant_gid', 'type', 'position', 'minimum_quantity', 'selected_at',
])]
class PersonalizationStrategyProductOverride extends Model
{
    public function strategy(): BelongsTo
    {
        return $this->belongsTo(PersonalizationRecommendationStrategy::class, 'strategy_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    protected function casts(): array
    {
        return [
            'type' => PersonalizationProductOverrideType::class,
            'position' => 'integer',
            'minimum_quantity' => 'integer',
            'selected_at' => 'datetime',
        ];
    }
}
