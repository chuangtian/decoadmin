<?php

namespace App\Domain\ReferralAffiliate\Models;

use Illuminate\Database\Eloquent\Model;

class AffiliatePortalToken extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime'];
    }
}
