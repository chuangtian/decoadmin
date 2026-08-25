<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable([
    'uuid', 'organization_id', 'store_id', 'claim_id', 'normalized_email', 'shopify_discount_id',
    'code', 'status', 'usage_count', 'usage_limit', 'idempotency_key', 'generated_at', 'expires_at',
    'last_synced_at', 'email_sent_at', 'email_failed_at',
])]
class StudentDiscountCode extends Model
{
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(StudentDiscountClaim::class, 'claim_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function refreshStatus(): self
    {
        $this->status = $this->usage_count >= $this->usage_limit
            ? 'used_up'
            : ($this->expires_at->isPast()
                ? 'expired'
                : ($this->usage_count > 0 ? 'partially_used' : 'unused'));

        return $this;
    }

    public function isReusable(): bool
    {
        return $this->usage_count < $this->usage_limit && $this->expires_at->isFuture();
    }

    protected static function booted(): void
    {
        static::creating(function (StudentDiscountCode $code): void {
            $code->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'usage_count' => 'integer',
            'usage_limit' => 'integer',
            'generated_at' => 'datetime',
            'expires_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'email_sent_at' => 'datetime',
            'email_failed_at' => 'datetime',
        ];
    }
}
