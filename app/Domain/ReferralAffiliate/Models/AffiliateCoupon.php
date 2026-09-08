<?php

namespace App\Domain\ReferralAffiliate\Models;

use App\Models\Concerns\ScopesToOrganizationStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'organization_id', 'store_id', 'membership_id', 'code', 'normalized_code', 'shopify_discount_id', 'shopify_code_id', 'status', 'last_error', 'starts_at', 'ends_at', 'last_synced_at'])]
class AffiliateCoupon extends Model
{
    use ScopesToOrganizationStore, SoftDeletes;

    protected static function booted(): void
    {
        static::creating(fn (AffiliateCoupon $coupon) => $coupon->public_id ??= (string) Str::ulid());
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(AffiliateProgramMembership::class, 'membership_id');
    }

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'last_synced_at' => 'datetime'];
    }
}
