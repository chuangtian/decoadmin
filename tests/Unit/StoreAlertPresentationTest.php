<?php

namespace Tests\Unit;

use App\Models\StoreAlert;
use App\Support\StoreAlertPresentation;
use PHPUnit\Framework\TestCase;

class StoreAlertPresentationTest extends TestCase
{
    public function test_legacy_meta_and_advertising_alert_titles_are_presented_with_the_correct_source(): void
    {
        $meta = new StoreAlert(['title' => 'Shopify 数据同步失败', 'code' => 'meta_ads_shard_failed', 'context' => ['sync_type' => 'meta_ads']]);
        $google = new StoreAlert(['title' => 'Shopify 数据同步失败', 'code' => 'advertising_channel_sync_failed', 'context' => ['sync_type' => 'advertising_channel:google']]);

        $this->assertSame('Meta Ads 数据同步失败', StoreAlertPresentation::title($meta));
        $this->assertSame('Google Ads 广告数据同步失败', StoreAlertPresentation::title($google));
    }
}
