<?php

namespace App\Domain\ReferralAffiliate\Models;

use App\Models\Concerns\ScopesToOrganizationStore;
use Illuminate\Database\Eloquent\Model;

class AffiliateConversionLine extends Model
{
    use ScopesToOrganizationStore;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['rule_snapshot' => 'array', 'quantity' => 'integer', 'base_minor' => 'integer', 'commission_minor' => 'integer'];
    }
}
