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
