<?php

namespace DecoReviews\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Reward extends Model
{
    protected $table = 'deco_review_rewards';

    protected $guarded = ['id'];

    protected $hidden = ['code', 'code_hash', 'shopify_discount_id'];

    protected function casts(): array
    {
        return ['code' => 'encrypted', 'value' => 'decimal:2', 'due_at' => 'datetime', 'issued_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(RewardDelivery::class);
    }
}
