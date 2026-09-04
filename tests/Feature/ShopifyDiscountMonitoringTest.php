<?php

namespace Tests\Feature;

use App\Jobs\DeliverStoreAlertNotificationJob;
use App\Models\Organization;
use App\Models\ShopifyConnection;
use App\Models\ShopifyDiscountMonitor;
use App\Models\Store;
use App\Models\StoreAlert;
use App\Models\StoreNotificationSetting;
use App\Models\User;
use App\Services\Discounts\ShopifyDiscountMonitorService;
use App\Services\StoreAlertNotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class ShopifyDiscountMonitoringTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_first_read_is_a_quiet_baseline_then_relevant_changes_create_one_alert_with_before_and_after(): void
    {
        Queue::fake();
        [$store, $monitor] = $this->monitor();
        Http::fakeSequence("https://{$store->shopify_domain}/*")
            ->push($this->detail('LABORDAY50', 'ACTIVE', null, '2026-09-01T00:00:00Z'))
            ->push($this->detail('AUTUMN50', 'EXPIRED', '2026-09-05T00:00:00Z', '2026-09-04T00:00:00Z'));
        $service = app(ShopifyDiscountMonitorService::class);

        $baseline = $service->scanStore($store);
        $this->assertSame(1, $baseline['snapshots']);
        $this->assertSame(0, $baseline['alerts']);
        $this->assertDatabaseCount('store_alerts', 0);

        $changed = $service->scanStore($store);
        $this->assertSame(1, $changed['alerts']);
        $this->assertDatabaseCount('shopify_discount_snapshots', 2);
        $alert = StoreAlert::query()->sole();
        $this->assertSame('discount', $alert->type);
        $this->assertStringContainsString('折扣码：LABORDAY50 → AUTUMN50', $alert->message);
        $this->assertStringContainsString('结束时间：无结束时间 →', $alert->message);
        $this->assertStringContainsString('状态：active → expired', $alert->message);
        $this->assertSame($monitor->shopify_discount_id, data_get($alert->context, 'shopify_discount_id'));
        Queue::assertPushed(DeliverStoreAlertNotificationJob::class, 1);
    }

    public function test_countdown_milestones_and_post_expiry_are_each_created_once(): void
    {
        Queue::fake();
        CarbonImmutable::setTestNow('2026-09-01T00:00:00Z');
        [$store] = $this->monitor();
        $endsAt = '2026-09-09T00:00:00Z';
        Http::fake(["https://{$store->shopify_domain}/*" => Http::response($this->detail('FALL50', 'ACTIVE', $endsAt, '2026-09-01T00:00:00Z'))]);
        $service = app(ShopifyDiscountMonitorService::class);

        $this->assertSame(0, $service->scanStore($store)['alerts']);
        foreach ([
            '2026-09-02T00:01:00Z' => 'discount_expiry_7d_',
            '2026-09-06T00:01:00Z' => 'discount_expiry_3d_',
            '2026-09-08T00:01:00Z' => 'discount_expiry_24h_',
            '2026-09-09T00:01:00Z' => 'discount_expiry_expired_',
        ] as $time => $codePrefix) {
            CarbonImmutable::setTestNow($time);
            $this->assertSame(1, $service->scanStore($store)['alerts']);
            $this->assertSame(0, $service->scanStore($store)['alerts']);
            $this->assertTrue(StoreAlert::query()->where('code', 'like', $codePrefix.'%')->exists());
        }
        $this->assertDatabaseCount('store_alerts', 4);
        Queue::assertPushed(DeliverStoreAlertNotificationJob::class, 4);
    }

    public function test_discount_alert_uses_the_existing_store_feishu_configuration(): void
    {
        [$store] = $this->monitor();
        StoreNotificationSetting::query()->create([
            'organization_id' => $store->organization_id,
            'store_id' => $store->id,
            'feishu_enabled' => true,
            'feishu_webhook_url' => 'https://open.feishu.cn/open-apis/bot/v2/hook/discount-test',
            'notify_discount_monitor' => true,
        ]);
        $alert = StoreAlert::query()->create([
            'organization_id' => $store->organization_id,
            'store_id' => $store->id,
            'fingerprint' => hash('sha256', (string) Str::uuid()),
            'type' => 'discount',
            'severity' => 'warning',
            'code' => 'discount_changed_test',
            'title' => '重点折扣设置发生变化',
            'message' => '折扣码：OLD50 → NEW50',
            'status' => 'open',
            'delivery_status' => 'pending',
            'occurred_at' => now(),
        ]);
        Http::fake(['open.feishu.cn/*' => Http::response(['code' => 0], 200)]);

        app(StoreAlertNotificationService::class)->deliver($alert);

        $this->assertSame('sent', $alert->fresh()->delivery_status);
        Http::assertSent(fn ($request): bool => str_contains((string) data_get($request->data(), 'content.text'), '折扣码：OLD50 → NEW50'));
    }

    /** @return array{Store, ShopifyDiscountMonitor} */
    private function monitor(): array
    {
        config(['shopify.api_version' => '2026-07']);
        $organization = Organization::query()->create(['name' => 'Discount Monitor Org', 'code' => 'discount-monitor']);
        $store = $organization->stores()->create([
            'name' => 'Monitored Store',
            'shopify_domain' => 'monitored-store.myshopify.com',
            'status' => 'active',
            'currency' => 'USD',
            'timezone' => 'America/Los_Angeles',
        ]);
        ShopifyConnection::query()->create([
            'store_id' => $store->id,
            'shop_domain' => $store->shopify_domain,
            'access_token_encrypted' => 'read-only-monitor-token',
            'scopes' => ['read_products', 'read_discounts'],
            'api_version' => '2026-07',
            'status' => 'connected',
        ]);
        $actor = User::factory()->create();
        $monitor = ShopifyDiscountMonitor::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'shopify_discount_id' => 'gid://shopify/DiscountCodeNode/1789315613037',
            'is_enabled' => true,
            'baseline_pending' => true,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        return [$store, $monitor];
    }

    /** @return array<string, mixed> */
    private function detail(string $code, string $status, ?string $endsAt, string $updatedAt): array
    {
        return ['data' => ['node' => [
            'id' => 'gid://shopify/DiscountCodeNode/1789315613037',
            'codeDiscount' => [
                '__typename' => 'DiscountCodeBasic',
                'title' => 'Labor Day',
                'summary' => '50% off all products',
                'status' => $status,
                'startsAt' => '2026-08-01T00:00:00Z',
                'endsAt' => $endsAt,
                'updatedAt' => $updatedAt,
                'discountClasses' => ['ORDER'],
                'codes' => ['nodes' => [['code' => $code]]],
                'usageLimit' => null,
                'asyncUsageCount' => 0,
                'appliesOncePerCustomer' => false,
                'combinesWith' => ['orderDiscounts' => false, 'productDiscounts' => false, 'shippingDiscounts' => false],
                'context' => ['__typename' => 'DiscountBuyerSelectionAll', 'all' => 'ALL'],
                'customerGets' => [
                    'value' => ['percentage' => 0.5],
                    'items' => ['__typename' => 'AllDiscountItems', 'allItems' => true],
                ],
                'minimumRequirement' => null,
            ],
        ]]];
    }
}
