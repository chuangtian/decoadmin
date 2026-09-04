<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'organization_id', 'store_id', 'shopify_product_id', 'title', 'handle', 'status',
    'vendor', 'product_type', 'description', 'tags', 'created_at_shopify', 'published_at_shopify',
    'online_store_url', 'featured_image_url', 'featured_image_alt', 'featured_image_width',
    'featured_image_height', 'synced_at',
])]
class Product extends Model
{
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function reputationMentionMatches(): HasMany
    {
        return $this->hasMany(ReputationMentionProductMatch::class);
    }

    public function collections(): BelongsToMany
    {
        return $this->belongsToMany(ProductCollection::class, 'product_collection_memberships')
            ->withPivot(['organization_id', 'store_id', 'shopify_product_id'])
            ->withTimestamps();
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
            'shopify_product_id' => 'string',
            'tags' => 'array',
            'created_at_shopify' => 'datetime',
            'published_at_shopify' => 'datetime',
            'featured_image_width' => 'integer',
            'featured_image_height' => 'integer',
            'synced_at' => 'datetime',
        ];
    }
}
