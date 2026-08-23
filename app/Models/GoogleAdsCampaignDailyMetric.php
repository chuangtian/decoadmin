<?php

namespace App\Models;

use App\Models\Concerns\ScopesToOrganizationStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id', 'store_id', 'advertising_channel_account_id', 'external_account_id',
    'campaign_id', 'campaign_name', 'campaign_status', 'advertising_channel_type', 'metric_date',
    'spend', 'impressions', 'clicks', 'conversions', 'conversions_value',
    'conversion_value_by_conversion_date', 'all_conversions', 'all_conversions_value',
    'all_conversions_value_by_conversion_date', 'raw_payload', 'synced_at',
])]
class GoogleAdsCampaignDailyMetric extends Model
{
    use ScopesToOrganizationStore;

    public function account(): BelongsTo
    {
        return $this->belongsTo(AdvertisingChannelAccount::class, 'advertising_channel_account_id');
    }

    protected function casts(): array
    {
        return [
            'metric_date' => 'date',
            'spend' => 'decimal:6',
            'impressions' => 'integer',
            'clicks' => 'integer',
            'conversions' => 'decimal:6',
            'conversions_value' => 'decimal:6',
            'conversion_value_by_conversion_date' => 'decimal:6',
            'all_conversions' => 'decimal:6',
            'all_conversions_value' => 'decimal:6',
            'all_conversions_value_by_conversion_date' => 'decimal:6',
            'raw_payload' => 'array',
            'synced_at' => 'datetime',
        ];
    }
}
