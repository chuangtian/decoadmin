<?php

namespace App\Models;

use App\Models\Concerns\ScopesToOrganizationStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable([
    'uuid', 'organization_id', 'store_id', 'reputation_mention_id', 'origin', 'source_key',
    'description', 'severity', 'source', 'recommended_action', 'status', 'occurred_at',
    'resolved_at', 'created_by', 'updated_by',
])]
class ReputationRisk extends Model
{
    use ScopesToOrganizationStore;

    public function mention(): BelongsTo
    {
        return $this->belongsTo(ReputationMention::class, 'reputation_mention_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected static function booted(): void
    {
        static::creating(function (ReputationRisk $risk): void {
            $risk->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'resolved_at' => 'datetime'];
    }
}
