<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['organization_id', 'store_id', 'event_source_id', 'event_id', 'event_name', 'client_id_hash', 'session_id_hash', 'payload_hash', 'component_id', 'strategy_id', 'placement', 'shopify_order_id', 'occurred_at', 'received_at'])]
class PersonalizationEvent extends Model
{
    public function source(): BelongsTo
    {
        return $this->belongsTo(PersonalizationEventSource::class, 'event_source_id');
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(PersonalizationRecommendationComponent::class, 'component_id');
    }

    public function strategy(): BelongsTo
    {
        return $this->belongsTo(PersonalizationRecommendationStrategy::class, 'strategy_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(PersonalizationEventProduct::class, 'event_id');
    }

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }
}
