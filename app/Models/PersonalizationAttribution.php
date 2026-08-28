<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'store_id', 'order_id', 'checkout_event_id', 'click_event_id', 'component_id', 'strategy_id', 'product_id', 'shopify_product_id', 'placement', 'model', 'window_days', 'status', 'currency', 'gross_revenue', 'refund_amount', 'attributed_revenue', 'clicked_at', 'ordered_at', 'reconciled_at'])]
class PersonalizationAttribution extends Model
{
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function checkoutEvent(): BelongsTo
    {
        return $this->belongsTo(PersonalizationEvent::class, 'checkout_event_id');
    }

    public function clickEvent(): BelongsTo
    {
        return $this->belongsTo(PersonalizationEvent::class, 'click_event_id');
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(PersonalizationRecommendationComponent::class);
    }

    public function strategy(): BelongsTo
    {
        return $this->belongsTo(PersonalizationRecommendationStrategy::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    protected function casts(): array
    {
        return [
            'gross_revenue' => 'decimal:4',
            'refund_amount' => 'decimal:4',
            'attributed_revenue' => 'decimal:4',
            'clicked_at' => 'datetime',
            'ordered_at' => 'datetime',
            'reconciled_at' => 'datetime',
        ];
    }
}
