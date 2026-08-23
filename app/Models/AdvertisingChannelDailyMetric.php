<?php

namespace App\Models;

use App\Models\Concerns\ScopesToOrganizationStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id', 'store_id', 'advertising_channel_account_id', 'provider',
    'external_account_id', 'metric_date', 'spend', 'attributed_sales', 'impressions',
    'clicks', 'conversions', 'raw_payload', 'synced_at',
])]
class AdvertisingChannelDailyMetric extends Model
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

    public function account(): BelongsTo
    {
        return $this->belongsTo(AdvertisingChannelAccount::class, 'advertising_channel_account_id');
    }

    protected function casts(): array
    {
        return [
            'metric_date' => 'date',
            'spend' => 'decimal:6',
            'attributed_sales' => 'decimal:6',
            'impressions' => 'integer',
            'clicks' => 'integer',
            'conversions' => 'decimal:6',
            'raw_payload' => 'array',
            'synced_at' => 'datetime',
        ];
    }
}
