<?php

namespace App\Domain\ReferralAffiliate\Models;

use App\Models\Concerns\ScopesToOrganizationStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'organization_id', 'store_id', 'link_id', 'membership_id', 'visitor_token', 'referrer_host', 'ip_hash', 'ua_hash', 'occurred_at'])]
#[Hidden(['visitor_token', 'ip_hash', 'ua_hash'])]
class AffiliateClick extends Model
{
    use ScopesToOrganizationStore;

    protected static function booted(): void
    {
        static::creating(fn (AffiliateClick $click) => $click->public_id ??= (string) Str::ulid());
    }

    public function link(): BelongsTo
    {
        return $this->belongsTo(AffiliateLink::class, 'link_id');
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(AffiliateProgramMembership::class, 'membership_id');
    }

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }
}
