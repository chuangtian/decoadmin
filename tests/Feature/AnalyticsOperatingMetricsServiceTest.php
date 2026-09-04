<?php

namespace Tests\Feature;

use App\Models\AdvertisingChannelAccount;
use App\Models\AdvertisingChannelDailyMetric;
use App\Models\Customer;
use App\Models\MetaAdAccount;
use App\Models\MetaAdInsight;
use App\Models\Order;
use App\Models\OrderItem;
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

    public function test_it_uses_whole_bike_orders_for_order_value_and_conversion_metrics(): void
    {
        $organization = Organization::query()->create(['name' => 'Bike Metrics Org', 'code' => 'bike-metrics-org']);
        $store = $organization->stores()->create([
            'name' => 'Macfox Bike', 'shopify_domain' => 'macfoxebike.myshopify.com',
            'status' => 'active', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);
        $products = collect([
            'macfox-x1', 'macfox-x7', 'x1s-x-bs-zay', 'macfox-x2', 'macfox-m16-ebike',
        ])->mapWithKeys(fn (string $handle, int $index): array => [$handle => Product::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'shopify_product_id' => (string) (100 + $index),
            'title' => $handle,
            'handle' => $handle,
            'status' => 'active',
            'synced_at' => now(),
        ])]);
        $this->bikeOrder($organization->id, $store, $products['macfox-x1'], 1000, '2026-08-01 12:00:00');
        $this->bikeOrder($organization->id, $store, $products['macfox-x7'], 800, '2026-07-31 12:00:00');

        $overview = [
            'period' => [
                'from' => '2026-08-01', 'to' => '2026-08-01',
                'include_test' => false, 'include_cancelled' => true,
            ],
            'comparison' => [
                'mode' => 'previous', 'label' => '上一周期',
                'period' => ['from' => '2026-07-31', 'to' => '2026-07-31'],
            ],
            'summary' => ['net_sales' => 1100],
            'comparisons' => ['previous' => ['net_sales' => ['baseline' => 900]]],
            'trend' => [['date' => '2026-08-01', 'net_sales' => 1100]],
        ];
        $behavior = [
            'available' => true,
            'metrics' => [
                'sessions' => $this->behaviorMetric(100, 50),
                'conversion_rate' => $this->behaviorMetric(10, 8),
                'add_to_cart' => $this->behaviorMetric(50, 40),
                'checkout' => $this->behaviorMetric(25, 20),
            ],
            'trend' => [[
                'date' => '2026-08-01', 'sessions' => 100, 'conversion_rate' => 10,
                'add_to_cart' => 50, 'checkout' => 25,
            ]],
            'error' => null,
        ];

        $result = app(AnalyticsOperatingMetricsService::class)->forStore($store, $overview, $behavior);

        $this->assertTrue($result['whole_bike']['available']);
        $this->assertSame(1.0, $result['metrics']['whole_bike_orders']['value']);
        $this->assertSame(1000.0, $result['metrics']['whole_bike_average_order_value']['value']);
        $this->assertSame(1.0, $result['metrics']['whole_bike_conversion_rate']['value']);
        $this->assertSame(2.0, $result['metrics']['whole_bike_conversion_rate']['comparison']['baseline']);
        $this->assertSame(-50.0, $result['metrics']['whole_bike_conversion_rate']['comparison']['change_percent']);
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

    private function bikeOrder(int $organizationId, Store $store, Product $product, float $netSales, string $createdAt): void
    {
        $order = Order::query()->create([
            'organization_id' => $organizationId,
            'store_id' => $store->id,
            'shopify_order_id' => (string) fake()->unique()->numberBetween(1000, 999999),
            'order_number' => '#'.fake()->unique()->numberBetween(1000, 999999),
            'currency' => 'USD',
            'total_price' => $netSales,
            'subtotal_price' => $netSales,
            'net_sales' => $netSales,
            'total_tax' => 0,
            'created_at_shopify' => $createdAt,
            'synced_at' => now(),
        ]);
        OrderItem::query()->create([
            'order_id' => $order->id,
            'shopify_line_item_id' => (string) fake()->unique()->numberBetween(1000, 999999),
            'product_id' => $product->id,
            'shopify_product_id' => $product->shopify_product_id,
            'title' => $product->title,
            'quantity' => 1,
            'current_quantity' => 1,
            'price' => $netSales,
            'attributed_sales' => $netSales,
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
