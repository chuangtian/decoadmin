<?php

namespace App\Domain\ReferralAffiliate\Models;

use App\Models\Concerns\ScopesToOrganizationStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AffiliateRiskFlag extends Model
{
    use ScopesToOrganizationStore;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(fn ($record) => $record->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return ['evidence' => 'array', 'reviewed_at' => 'datetime'];
    }
}
