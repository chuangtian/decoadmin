<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id', 'store_id', 'report_key', 'period_from', 'period_to',
    'timezone', 'source', 'schema_version', 'payload', 'fetched_at', 'expires_at',
])]
class AnalyticsSnapshot extends Model
{
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function scopeForOrganization(Builder $query, Organization|int $organization): void
    {
        $query->where('organization_id', $organization instanceof Organization ? $organization->getKey() : $organization);
    }

    public function scopeForStore(Builder $query, Store|int $store): void
    {
        $query->where('store_id', $store instanceof Store ? $store->getKey() : $store);
    }

    protected function casts(): array
    {
        return [
            'period_from' => 'date',
            'period_to' => 'date',
            'schema_version' => 'integer',
            'payload' => 'array',
            'fetched_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
