<?php

namespace DecoReviews\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RewardDelivery extends Model
{
    protected $table = 'deco_review_reward_deliveries';

    protected $guarded = ['id'];

    protected $hidden = ['recipient', 'recipient_hash', 'dedupe_key'];

    protected function casts(): array
    {
        return ['recipient' => 'encrypted', 'due_at' => 'datetime', 'sent_at' => 'datetime'];
    }

    public function reward(): BelongsTo
    {
        return $this->belongsTo(Reward::class);
    }
}
