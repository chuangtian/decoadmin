<?php

namespace App\Domain\ReferralAffiliate\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class AffiliatePayoutBatch extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['cutoff_at' => 'datetime', 'paid_at' => 'datetime', 'total_minor' => 'integer'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(AffiliatePayoutItem::class, 'batch_id');
    }

    protected static function booted(): void
    {
        static::creating(fn ($m) => $m->public_id ??= (string) Str::ulid());
    }
}
