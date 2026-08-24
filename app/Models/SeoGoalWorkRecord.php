<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id', 'store_id', 'source_key', 'source_row', 'actual_date',
    'cooperation_month', 'cooperation_month_number', 'name', 'url_present', 'included', 'synced_at',
])]
class SeoGoalWorkRecord extends Model
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
            'source_row' => 'integer',
            'cooperation_month_number' => 'integer',
            'actual_date' => 'date',
            'url_present' => 'boolean',
            'included' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }
}
