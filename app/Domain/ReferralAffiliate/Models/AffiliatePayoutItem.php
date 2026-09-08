<?php

namespace App\Domain\ReferralAffiliate\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AffiliatePayoutItem extends Model
{
    public function membership(): BelongsTo
    {
        return $this->belongsTo(AffiliateProgramMembership::class, 'membership_id');
    }

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer'];
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(AffiliatePayoutAllocation::class, 'item_id');
    }
}
