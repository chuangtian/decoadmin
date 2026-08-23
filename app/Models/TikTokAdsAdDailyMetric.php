<?php

namespace App\Models;

use App\Models\Concerns\ScopesToOrganizationStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id', 'store_id', 'advertising_channel_account_id', 'external_account_id',
    'campaign_id', 'campaign_name', 'adgroup_id', 'ad_id', 'ad_name', 'ad_text', 'ad_texts',
    'ad_format', 'video_id', 'metric_date', 'spend', 'attributed_sales', 'impressions', 'clicks',
    'conversions', 'video_play_actions', 'video_watched_2s', 'average_video_play', 'raw_payload', 'synced_at',
])]
class TikTokAdsAdDailyMetric extends Model
{
    use ScopesToOrganizationStore;

    protected $table = 'tiktok_ads_ad_daily_metrics';

    public function account(): BelongsTo
    {
        return $this->belongsTo(AdvertisingChannelAccount::class, 'advertising_channel_account_id');
    }

    protected function casts(): array
    {
        return [
            'metric_date' => 'date',
            'ad_texts' => 'array',
            'spend' => 'decimal:6',
            'attributed_sales' => 'decimal:6',
            'impressions' => 'integer',
            'clicks' => 'integer',
            'conversions' => 'decimal:6',
            'video_play_actions' => 'integer',
            'video_watched_2s' => 'integer',
            'average_video_play' => 'decimal:6',
            'raw_payload' => 'array',
            'synced_at' => 'datetime',
        ];
    }
}
