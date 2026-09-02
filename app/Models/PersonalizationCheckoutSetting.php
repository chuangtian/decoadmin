<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable([
    'uuid', 'organization_id', 'store_id', 'component_id', 'thank_you_component_id', 'collection_id', 'shopify_collection_id',
    'maximum_recommendations', 'enabled', 'trust_items', 'settings', 'updated_by',
])]
class PersonalizationCheckoutSetting extends Model
{
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(PersonalizationRecommendationComponent::class, 'component_id');
    }

    public function thankYouComponent(): BelongsTo
    {
        return $this->belongsTo(PersonalizationRecommendationComponent::class, 'thank_you_component_id');
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(ProductCollection::class, 'collection_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    protected static function booted(): void
    {
        static::creating(function (PersonalizationCheckoutSetting $setting): void {
            $setting->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'shopify_collection_id' => 'string',
            'maximum_recommendations' => 'integer',
            'trust_items' => 'array',
            'settings' => 'array',
        ];
    }
}
