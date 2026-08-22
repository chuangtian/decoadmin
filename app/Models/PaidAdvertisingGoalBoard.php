<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'organization_id', 'store_id', 'name', 'type',
    'feishu_app_token', 'feishu_table_id', 'feishu_view_id',
    'sync_status', 'last_synced_at', 'last_error', 'created_by',
])]
#[Hidden(['feishu_app_token', 'feishu_table_id', 'feishu_view_id'])]
class PaidAdvertisingGoalBoard extends Model
{
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function records(): HasMany
    {
        return $this->hasMany(PaidAdvertisingGoalRecord::class, 'goal_board_id');
    }

    public function fields(): HasMany
    {
        return $this->hasMany(PaidAdvertisingGoalField::class, 'goal_board_id');
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
            'feishu_app_token' => 'encrypted',
            'feishu_table_id' => 'encrypted',
            'feishu_view_id' => 'encrypted',
            'last_synced_at' => 'datetime',
        ];
    }
}
