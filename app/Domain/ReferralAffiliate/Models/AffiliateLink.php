<?php

namespace App\Domain\ReferralAffiliate\Models;

use App\Models\Concerns\ScopesToOrganizationStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'organization_id', 'store_id', 'membership_id', 'referral_code', 'normalized_referral_code', 'target_path', 'status', 'utm'])]
class AffiliateLink extends Model
{
    use ScopesToOrganizationStore, SoftDeletes;

    protected static function booted(): void
    {
        static::creating(fn (AffiliateLink $link) => $link->public_id ??= (string) Str::ulid());
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(AffiliateProgramMembership::class, 'membership_id');
    }

    public function clicks(): HasMany
    {
        return $this->hasMany(AffiliateClick::class, 'link_id');
    }

    protected function casts(): array
    {
        return ['utm' => 'array'];
    }
}
