<?php

namespace Tests\Feature;

use App\Models\AdvertisingChannelAccount;
use App\Models\AdvertisingChannelDailyMetric;
use App\Models\Customer;
use App\Models\MetaAdAccount;
use App\Models\MetaAdInsight;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Services\AnalyticsOperatingMetricsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalyticsOperatingMetricsServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_store_scoped_operating_metrics_from_real_ad_and_shopify_behavior_data(): void
    {
        $organization = Organization::query()->create(['name' => 'Metrics Org', 'code' => 'metrics-org']);
        $store = $organization->stores()->create([
            'name' => 'Metrics Store', 'shopify_domain' => 'metrics-store.myshopify.com',
            'status' => 'active', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);
        $other = $organization->stores()->create([
            'name' => 'Other Store', 'shopify_domain' => 'other-metrics-store.myshopify.com',
            'status' => 'active', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);

        foreach (['google' => 100, 'tiktok' => 50, 'bing' => 25, 'criteo' => 25] as $provider => $spend) {
            $account = $this->adAccount($organization->id, $store, $provider);
            $this->adMetric($organization->id, $store, $account, $provider, '2026-08-01', $spend);
            $this->adMetric($organization->id, $store, $account, $provider, '2026-07-30', $spend / 2);
        }
        $otherAccount = $this->adAccount($organization->id, $other, 'google');
        $this->adMetric($organization->id, $other, $otherAccount, 'google', '2026-08-01', 9999);
        $this->metaMetric($organization->id, $store, '2026-08-01', 50);
        $this->metaMetric($organization->id, $store, '2026-07-30', 25);

        $product = Product::query()->create([
            'organization_id' => $organization->id, 'store_id' => $store->id,
            'shopify_product_id' => '100', 'title' => 'Bike', 'handle' => 'bike', 'status' => 'active',
            'synced_at' => now(),
        ]);
        ProductVariant::query()->create([
            'product_id' => $product->id, 'shopify_variant_id' => '101', 'title' => 'Default', 'sku' => 'BIKE-1', 'price' => 1000,
        ]);
        Customer::query()->create([
            'organization_id' => $organization->id, 'store_id' => $store->id,
            'shopify_customer_id' => '200', 'first_name' => 'Ada', 'last_name' => 'Lovelace',
            'created_at_shopify' => now(), 'updated_at_shopify' => now(), 'synced_at' => now(),
        ]);

        $overview = [
            'period' => ['from' => '2026-08-01', 'to' => '2026-08-02'],
            'summary' => ['net_sales' => 1000],
            'comparisons' => ['previous' => ['net_sales' => ['baseline' => 500]]],
            'trend' => [
                ['date' => '2026-08-01', 'net_sales' => 600],
                ['date' => '2026-08-02', 'net_sales' => 400],
            ],
        ];
        $behavior = [
            'available' => true,
            'metrics' => [
                'sessions' => $this->behaviorMetric(100, 80),
                'conversion_rate' => $this->behaviorMetric(10, 8),
                'add_to_cart' => $this->behaviorMetric(50, 40),
                'checkout' => $this->behaviorMetric(25, 20),
            ],
            'trend' => [
                ['date' => '2026-08-01', 'sessions' => 60, 'conversion_rate' => 10, 'add_to_cart' => 30, 'checkout' => 15],
                ['date' => '2026-08-02', 'sessions' => 40, 'conversion_rate' => 10, 'add_to_cart' => 20, 'checkout' => 10],
            ],
            'error' => null,
        ];

        $result = app(AnalyticsOperatingMetricsService::class)->forStore($store, $overview, $behavior);

        $this->assertTrue($result['advertising']['complete']);
        $this->assertSame(5, $result['advertising']['available_channels']);
        $this->assertSame(250.0, $result['metrics']['ad_spend']['value']);
        $this->assertSame(125.0, $result['metrics']['ad_spend']['comparison']['baseline']);
        $this->assertSame(4.0, $result['metrics']['roi']['value']);
        $this->assertSame(100.0, $result['metrics']['sessions']['value']);
        $this->assertSame(50.0, $result['metrics']['add_to_cart']['value']);
        $this->assertSame(25.0, $result['metrics']['checkout']['value']);
        $this->assertSame(5.0, $result['metrics']['add_to_cart_cost']['value']);
        $this->assertSame(10.0, $result['metrics']['checkout_cost']['value']);
        $this->assertSame(1, $result['catalog']['sku_count']);
        $this->assertSame(1, $result['catalog']['customer_count']);
    }

    private function adAccount(int $organizationId, Store $store, string $provider): AdvertisingChannelAccount
    {
        return AdvertisingChannelAccount::query()->create([
            'organization_id' => $organizationId, 'store_id' => $store->id, 'provider' => $provider,
            'external_account_id' => "{$provider}-{$store->id}", 'name' => ucfirst($provider), 'status' => 'active',
            'currency' => 'USD', 'timezone' => 'UTC', 'raw_payload' => [], 'last_seen_at' => now(), 'synced_at' => now(),
        ]);
    }

    private function adMetric(int $organizationId, Store $store, AdvertisingChannelAccount $account, string $provider, string $date, float $spend): void
    {
        AdvertisingChannelDailyMetric::query()->create([
            'organization_id' => $organizationId, 'store_id' => $store->id,
            'advertising_channel_account_id' => $account->id, 'provider' => $provider,
            'external_account_id' => $account->external_account_id, 'metric_date' => $date,
            'spend' => $spend, 'attributed_sales' => $spend * 2, 'raw_payload' => [], 'synced_at' => now(),
        ]);
    }

    private function metaMetric(int $organizationId, Store $store, string $date, float $spend): void
    {
        $account = MetaAdAccount::query()->firstOrCreate([
            'organization_id' => $organizationId, 'store_id' => $store->id, 'meta_account_id' => 'act_'.$store->id,
        ], [
            'name' => 'Facebook', 'account_status' => 1, 'currency' => 'USD', 'timezone_name' => 'UTC',
            'raw_payload' => [], 'last_seen_at' => now(), 'synced_at' => now(),
        ]);
        MetaAdInsight::query()->create([
            'organization_id' => $organizationId, 'store_id' => $store->id, 'meta_ad_account_id' => $account->id,
            'level' => 'account', 'entity_id' => $account->meta_account_id, 'account_external_id' => $account->meta_account_id,
            'date_start' => $date, 'date_stop' => $date, 'granularity' => 'day',
            'spend' => $spend, 'purchase_value' => $spend * 2, 'synced_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function behaviorMetric(float $current, float $baseline): array
    {
        return [
            'value' => $current,
            'comparison' => [
                'current' => $current, 'baseline' => $baseline, 'change' => $current - $baseline,
                'change_percent' => round(($current - $baseline) / $baseline * 100, 2),
            ],
        ];
    }
}
