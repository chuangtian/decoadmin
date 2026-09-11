<?php

namespace App\Domain\ReferralAffiliate\Models;

use Illuminate\Database\Eloquent\Model;

class AffiliatePayoutAllocation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [];
    }
}
