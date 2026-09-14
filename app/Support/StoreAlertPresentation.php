<?php

namespace App\Support;

use App\Models\StoreAlert;

final class StoreAlertPresentation
{
    public static function title(StoreAlert $alert): string
    {
        $syncType = (string) data_get($alert->context, 'sync_type');
        if (str_starts_with($syncType, 'advertising_channel:') && str_starts_with($alert->title, 'Shopify')) {
            $label = match (substr($syncType, strlen('advertising_channel:'))) {
                'google' => 'Google Ads',
                'tiktok' => 'TikTok Ads',
                'bing' => 'Bing Ads',
                'criteo' => 'Criteo',
                default => '广告渠道',
            };

            return $label.' 广告数据同步失败';
        }

        if ($syncType !== 'meta_ads') {
            return $alert->title;
        }

        return match ($alert->code) {
            'sync_stalled' => 'Meta Ads 同步任务长时间无进展',
            'sync_max_attempts_reached' => 'Meta Ads 同步连续失败',
            default => str_starts_with($alert->title, 'Shopify') ? 'Meta Ads 数据同步失败' : $alert->title,
        };
    }
}
