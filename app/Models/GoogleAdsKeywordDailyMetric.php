<?php

namespace App\Models;

use App\Models\Concerns\ScopesToOrganizationStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'organization_id', 'store_id', 'advertising_channel_account_id', 'external_account_id', 'metric_date',
    'dimension_key', 'criterion_id', 'keyword', 'normalized_keyword', 'match_type', 'status', 'campaign_id',
    'campaign_name', 'ad_group_id', 'ad_group_name', 'spend', 'revenue', 'impressions', 'clicks',
    'conversions', 'raw_payload', 'synced_at',
])]
class GoogleAdsKeywordDailyMetric extends Model
{
    use ScopesToOrganizationStore;

    protected function casts(): array
    {
        return ['metric_date' => 'date', 'spend' => 'decimal:6', 'revenue' => 'decimal:6', 'impressions' => 'integer', 'clicks' => 'integer', 'conversions' => 'decimal:6', 'raw_payload' => 'array', 'synced_at' => 'datetime'];
    }
}
