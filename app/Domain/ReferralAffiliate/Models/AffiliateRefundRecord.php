<?php

namespace App\Domain\ReferralAffiliate\Models;

use App\Models\Concerns\ScopesToOrganizationStore;
use Illuminate\Database\Eloquent\Model;

class AffiliateRefundRecord extends Model
{
    use ScopesToOrganizationStore;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['line_snapshot' => 'array', 'adjustment_minor' => 'integer', 'refunded_at' => 'datetime'];
    }
}
