<?php

namespace App\Domain\ReferralAffiliate\Models;

use App\Models\Concerns\ScopesToOrganizationStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AffiliateReward extends Model
{
    use ScopesToOrganizationStore;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(function ($row) {
            $row->public_id ??= (string) Str::ulid();
            $row->code ??= 'DECO-R-'.Str::upper(Str::random(12));
        });
        static::updating(function ($row) {
            if ($row->isDirty(['organization_id', 'store_id', 'membership_id', 'conversion_id', 'dedupe_key', 'threshold', 'customer_id', 'code', 'rule_snapshot'])) {
                throw new \LogicException('Reward grant facts are immutable.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Reward grants cannot be deleted.'));
    }

    public function history()
    {
        return $this->hasMany(AffiliateRewardLedgerEntry::class, 'reward_id')->orderBy('id');
    }

    public function membership()
    {
        return $this->belongsTo(AffiliateProgramMembership::class, 'membership_id');
    }

    public function conversion()
    {
        return $this->belongsTo(AffiliateConversion::class, 'conversion_id');
    }

    protected function casts(): array
    {
        return ['rule_snapshot' => 'array', 'available_at' => 'datetime', 'issued_at' => 'datetime', 'expires_at' => 'datetime', 'last_synced_at' => 'datetime', 'threshold' => 'integer', 'attempts' => 'integer'];
    }
}
