<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable([
    'uuid', 'organization_id', 'store_id', 'fingerprint', 'type', 'severity', 'source_type',
    'source_id', 'code', 'title', 'message', 'context', 'status', 'delivery_status',
    'delivery_error', 'occurred_at', 'notified_at',
    'acknowledged_at', 'acknowledged_by',
    'resolved_at',
])]
class StoreAlert extends Model
{
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    protected static function booted(): void
    {
        static::creating(function (StoreAlert $alert): void {
            $alert->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'occurred_at' => 'datetime',
            'notified_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }
}
