<?php

namespace App\Domain\ReferralAffiliate\Models;

use App\Models\Concerns\ScopesToOrganizationStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AffiliateLedgerEntry extends Model
{
    public function conversion(): BelongsTo
    {
        return $this->belongsTo(AffiliateConversion::class, 'conversion_id');
    }

    use ScopesToOrganizationStore;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(fn ($record) => $record->public_id ??= (string) Str::ulid());
        static::updating(function ($record): void {
            if ($record->isDirty(['organization_id', 'store_id', 'membership_id', 'conversion_id', 'idempotency_key', 'type', 'currency', 'amount_minor', 'created_by', 'reason', 'metadata'])) {
                throw new \LogicException('Ledger financial facts are immutable; append an adjustment.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Ledger entries cannot be deleted.'));
    }

    protected function casts(): array
    {
        return ['metadata' => 'array', 'amount_minor' => 'integer', 'available_at' => 'datetime'];
    }
}
