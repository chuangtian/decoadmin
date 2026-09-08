<?php

namespace App\Domain\ReferralAffiliate\Models;

use App\Models\Concerns\ScopesToOrganizationStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AffiliateAttributionChange extends Model
{
    use ScopesToOrganizationStore;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(fn ($r) => $r->public_id ??= (string) Str::ulid());
        static::updating(fn () => throw new \LogicException('Attribution change history is immutable.'));
        static::deleting(fn () => throw new \LogicException('Attribution change history cannot be deleted.'));
    }

    public function fromMembership()
    {
        return $this->belongsTo(AffiliateProgramMembership::class, 'from_membership_id');
    }

    public function toMembership()
    {
        return $this->belongsTo(AffiliateProgramMembership::class, 'to_membership_id');
    }

    protected function casts(): array
    {
        return ['previous_snapshot' => 'array', 'new_snapshot' => 'array'];
    }
}
