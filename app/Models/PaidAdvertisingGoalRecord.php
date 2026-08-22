<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id', 'store_id', 'goal_board_id', 'source_key', 'source_record_id',
    'fields_encrypted', 'source_created_at', 'source_updated_at', 'synced_at',
])]
#[Hidden(['fields_encrypted'])]
class PaidAdvertisingGoalRecord extends Model
{
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function goalBoard(): BelongsTo
    {
        return $this->belongsTo(PaidAdvertisingGoalBoard::class, 'goal_board_id');
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
            'fields_encrypted' => 'encrypted:array',
            'source_created_at' => 'datetime',
            'source_updated_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }
}
