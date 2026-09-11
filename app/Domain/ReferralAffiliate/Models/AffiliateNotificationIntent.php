<?php

namespace App\Domain\ReferralAffiliate\Models;

use Illuminate\Database\Eloquent\Model;

class AffiliateNotificationIntent extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['message_encrypted' => 'encrypted:array', 'sent_at' => 'datetime'];
    }
}
