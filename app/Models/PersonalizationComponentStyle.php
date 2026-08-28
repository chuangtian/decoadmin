<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'store_id', 'component_id', 'layout', 'desktop_columns', 'mobile_columns', 'show_image', 'show_vendor', 'show_price', 'show_compare_at_price', 'show_add_to_cart', 'tokens'])]
class PersonalizationComponentStyle extends Model
{
    public function component(): BelongsTo
    {
        return $this->belongsTo(PersonalizationRecommendationComponent::class, 'component_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    protected function casts(): array
    {
        return [
            'desktop_columns' => 'integer',
            'mobile_columns' => 'integer',
            'show_image' => 'boolean',
            'show_vendor' => 'boolean',
            'show_price' => 'boolean',
            'show_compare_at_price' => 'boolean',
            'show_add_to_cart' => 'boolean',
            'tokens' => 'array',
        ];
    }
}
