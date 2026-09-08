<?php

namespace App\Domain\ReferralAffiliate\Models;

use App\Domain\ReferralAffiliate\Enums\MembershipStatus;
use App\Models\Concerns\ScopesToOrganizationStore;
use App\Models\Organization;
use App\Models\Store;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'organization_id', 'store_id', 'program_id', 'promoter_id', 'status', 'tier_key', 'shopify_customer_id', 'commission_override', 'approved_at', 'approved_by', 'suspended_at'])]
class AffiliateProgramMembership extends Model
{
    use ScopesToOrganizationStore;

    protected static function booted(): void
    {
        static::creating(fn (AffiliateProgramMembership $membership) => $membership->public_id ??= (string) Str::ulid());
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(AffiliateProgram::class, 'program_id');
    }

    public function promoter(): BelongsTo
    {
        return $this->belongsTo(AffiliatePromoter::class, 'promoter_id');
    }

    protected function casts(): array
    {
        return ['status' => MembershipStatus::class, 'shopify_customer_id' => 'string', 'commission_override' => 'array', 'approved_at' => 'datetime', 'suspended_at' => 'datetime'];
    }
}
