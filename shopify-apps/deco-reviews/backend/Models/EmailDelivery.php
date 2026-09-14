<?php

namespace DecoReviews\Models;

use Illuminate\Database\Eloquent\Model;

class EmailDelivery extends Model
{
    protected $table = 'deco_review_email_deliveries';

    protected $guarded = ['id'];

    protected $hidden = ['recipient', 'recipient_hash', 'dedupe_key'];

    protected function casts(): array
    {
        return ['recipient' => 'encrypted', 'due_at' => 'datetime', 'sent_at' => 'datetime'];
    }

    public function review()
    {
        return $this->belongsTo(Review::class);
    }
}
