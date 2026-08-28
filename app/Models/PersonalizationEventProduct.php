<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['event_id', 'product_id', 'shopify_product_id', 'shopify_variant_id', 'rank'])]
class PersonalizationEventProduct extends Model
{
    public function event(): BelongsTo
    {
        return $this->belongsTo(PersonalizationEvent::class, 'event_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
