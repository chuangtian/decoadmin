<?php

namespace App\Domain\ReferralAffiliate\Models;

use App\Models\Concerns\ScopesToOrganizationStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class AffiliateConversion extends Model
{
    use ScopesToOrganizationStore;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(fn ($record) => $record->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return ['order_snapshot' => 'array', 'rule_snapshot' => 'array', 'attribution_snapshot' => 'array', 'ordered_at' => 'datetime', 'available_at' => 'datetime', 'shopify_updated_at' => 'datetime', 'is_test' => 'boolean', 'refunded_base_minor' => 'integer', 'base_minor' => 'integer', 'commission_minor' => 'integer', 'reversed_minor' => 'integer'];
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(AffiliateProgramMembership::class, 'membership_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(AffiliateConversionLine::class, 'conversion_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(AffiliateLedgerEntry::class, 'conversion_id');
    }

    public function risks(): HasMany
    {
        return $this->hasMany(AffiliateRiskFlag::class, 'conversion_id');
    }
}
