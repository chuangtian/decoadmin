<?php

namespace DecoReviews\Models;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Database\Eloquent\Model;

class Invitation extends Model
{
    protected $table = 'deco_review_invitations';

    protected $guarded = ['id'];

    protected $hidden = ['email', 'email_hash'];

    protected function casts(): array
    {
        return ['email' => 'encrypted', 'due_at' => 'datetime', 'sent_at' => 'datetime', 'reminder_sent_at' => 'datetime',
            'media_reminder_sent_at' => 'datetime', 'expires_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
