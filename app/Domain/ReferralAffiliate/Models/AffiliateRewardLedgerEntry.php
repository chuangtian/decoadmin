<?php

namespace App\Domain\ReferralAffiliate\Models;

use App\Models\Concerns\ScopesToOrganizationStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AffiliateRewardLedgerEntry extends Model
{
    use ScopesToOrganizationStore;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(fn ($row) => $row->public_id ??= (string) Str::ulid());
        static::updating(fn () => throw new \LogicException('Reward history is immutable.'));
        static::deleting(fn () => throw new \LogicException('Reward history cannot be deleted.'));
    }

    protected function casts(): array
    {
        return ['rule_snapshot' => 'array', 'occurred_at' => 'datetime'];
    }
}
