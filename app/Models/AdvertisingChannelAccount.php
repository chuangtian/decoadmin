<?php

namespace App\Models;

use App\Models\Concerns\ScopesToOrganizationStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'organization_id', 'store_id', 'provider', 'external_account_id', 'name', 'status',
    'currency', 'timezone', 'raw_payload', 'last_seen_at', 'synced_at',
])]
class AdvertisingChannelAccount extends Model
{
    use ScopesToOrganizationStore;

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function dailyMetrics(): HasMany
    {
        return $this->hasMany(AdvertisingChannelDailyMetric::class);
    }

    protected function casts(): array
    {
        return [
            'raw_payload' => 'array',
            'last_seen_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }
}
