<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Product;
use App\Models\ShopifyConnection;
use App\Models\ShopifyProductMonitor;
use App\Models\StoreAlert;
use App\Models\StoreNotificationSetting;
use App\Models\User;
use App\Services\Shopify\Products\ShopifyProductMonitorReader;
use App\Services\Shopify\Products\ShopifyProductMonitorService;
use App\Services\StoreAlertNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ShopifyProductMonitoringTest extends TestCase
{
    use RefreshDatabase;

    private function context(): array
    {
        Queue::fake();
        config(['inertia.ssr.enabled' => false]);
        Http::preventStrayRequests();
        $org = Organization::create(['name' => 'Monitor', 'code' => 'monitor']);
        $store = $org->stores()->create(['name' => 'Test', 'shopify_domain' => 'monitor-test.myshopify.com', 'status' => 'active']);
        $product = Product::create(['organization_id' => $org->id, 'store_id' => $store->id, 'shopify_product_id' => '123', 'title' => 'Bike', 'handle' => 'bike', 'status' => 'active', 'synced_at' => now()]);
        $actor = User::factory()->create(['metadata' => ['is_super_admin' => true]]);

        return [$store, $product, $actor];
    }

    private function snapshot(int $quantity = 20, string $status = 'ACTIVE', bool $published = true): array
    {
        return ['id' => '123', 'title' => 'Bike', 'missing' => false, 'status' => $status, 'published' => $published,
            'variants' => ['v1' => ['title' => 'Red', 'sku' => 'RED', 'tracked' => true,
                'levels' => ['l1' => ['name' => 'Warehouse', 'available' => $quantity], 'l2' => ['name' => 'Other warehouse', 'available' => 99]]]]];
    }

    public function test_only_priority_products_are_read_and_repeated_stock_incidents_are_not_lost(): void
    {
        [$store, $product, $actor] = $this->context();
        $reader = $this->mock(ShopifyProductMonitorReader::class);
        $service = app(ShopifyProductMonitorService::class);
        $this->assertSame(0, $service->scanStore($store)['checked']);
        $reader->shouldReceive('read')->times(7)->withArgs(fn ($s, $id) => $s->id === $store->id && $id === '123')
            ->andReturn($this->snapshot(20), $this->snapshot(9), $this->snapshot(8), $this->snapshot(0), $this->snapshot(20), $this->snapshot(0), $this->snapshot(0));
        $monitor = $service->configure($store, $actor, $product, true, 10);
        foreach ([0, 1, 0, 1, 1, 1, 0] as $expected) {
            $this->assertSame($expected, $service->scanStore($store)['alerts']);
        }
        $this->assertDatabaseCount('store_alerts', 4);
        $this->assertStringContainsString('正常（20） → 低库存（9）', StoreAlert::first()->message);
        $generation = $monitor->fresh()->generation;
        $service->configure($store, $actor, $product, true, 10);
        $this->assertSame($generation, $monitor->fresh()->generation);
        $service->configure($store, $actor, $product, false, 10);
        $this->assertSame(0, $service->scanStore($store)['checked']);
    }

    public function test_both_status_directions_publication_and_missing_objects_are_alerted(): void
    {
        [$store, $product, $actor] = $this->context();
        $this->mock(ShopifyProductMonitorReader::class)->shouldReceive('read')->times(6)
            ->andReturn($this->snapshot(), $this->snapshot(20, 'DRAFT', false), $this->snapshot(), ['id' => '123', 'missing' => true], ['id' => '123', 'missing' => true], $this->snapshot());
        $service = app(ShopifyProductMonitorService::class);
        $service->configure($store, $actor, $product, true, 10);
        foreach ([0, 1, 1, 1, 0, 1] as $expected) {
            $this->assertSame($expected, $service->scanStore($store)['alerts']);
        }
        $this->assertStringContainsString('销售中 → 草稿', StoreAlert::first()->message);
        $this->assertStringContainsString('已发布 → 已取消发布', StoreAlert::first()->message);
    }

    public function test_failed_read_preserves_baseline_and_does_not_generate_zero_inventory(): void
    {
        [$store, $product, $actor] = $this->context();
        $reader = $this->mock(ShopifyProductMonitorReader::class);
        $reader->shouldReceive('read')->once()->andReturn($this->snapshot());
        $reader->shouldReceive('read')->once()->andThrow(new \RuntimeException('Bearer secret'));
        $service = app(ShopifyProductMonitorService::class);
        $monitor = $service->configure($store, $actor, $product, true, 10);
        $service->scanStore($store);
        $this->assertSame(1, $service->scanStore($store)['failed']);
        $this->assertSame($this->snapshot(), $monitor->fresh()->snapshot);
        $this->assertStringNotContainsString('secret', $monitor->fresh()->last_error);
        $this->assertDatabaseCount('store_alerts', 0);
    }

    public function test_cancellation_during_read_discards_snapshot_and_pending_delivery_is_cancelled(): void
    {
        [$store, $product, $actor] = $this->context();
        $service = app(ShopifyProductMonitorService::class);
        $monitor = $service->configure($store, $actor, $product, true, 10);
        $this->mock(ShopifyProductMonitorReader::class)->shouldReceive('read')->once()->andReturnUsing(function () use ($service, $store, $actor, $product) {
            $service->configure($store, $actor, $product, false, 10);

            return $this->snapshot();
        });
        // Resolve again after binding the reader mock.
        app(ShopifyProductMonitorService::class)->scanStore($store);
        $this->assertNull($monitor->fresh()->snapshot);
        $alert = StoreAlert::create(['organization_id' => $store->organization_id, 'store_id' => $store->id,
            'type' => 'product', 'source_type' => ShopifyProductMonitor::class, 'source_id' => $monitor->id,
            'code' => 'test', 'title' => 'test', 'message' => 'test', 'fingerprint' => 'test', 'severity' => 'warning',
            'status' => 'open', 'delivery_status' => 'pending', 'occurred_at' => now(), 'context' => ['generation' => 1]]);
        app(StoreAlertNotificationService::class)->deliver($alert);
        $this->assertSame('skipped', $alert->fresh()->delivery_status);
        Http::assertNothingSent();
    }

    public function test_configuration_is_authorized_scoped_and_visible_on_product_list(): void
    {
        [$store, $product, $actor] = $this->context();
        $session = ['current_store_id' => $store->id, 'current_organization_id' => $store->organization_id];
        $this->actingAs($actor)->withSession($session)->patch(route('products.monitor.update', $product->id), ['is_enabled' => true, 'low_stock_threshold' => 7])->assertRedirect();
        $this->get(route('products.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('products.data.0.monitor.is_enabled', true)->where('canManageMonitor', true));
        $other = $store->organization->stores()->create(['name' => 'Other', 'shopify_domain' => 'other.myshopify.com']);
        $this->withSession([...$session, 'current_store_id' => $other->id])->patch(route('products.monitor.update', $product->id), ['is_enabled' => false, 'low_stock_threshold' => 7])->assertNotFound();
        $viewer = User::factory()->create();
        $store->organization->users()->attach($viewer, ['status' => 'active']);
        $store->members()->attach($viewer, ['status' => 'active']);
        $this->actingAs($viewer)->withSession($session)->patch(route('products.monitor.update', $product->id), ['is_enabled' => false, 'low_stock_threshold' => 7])->assertForbidden();
        $this->assertTrue(ShopifyProductMonitor::sole()->is_enabled);
        Http::assertNothingSent();
    }

    public function test_feishu_uses_matching_store_settings_and_can_be_disabled(): void
    {
        [$store, $product, $actor] = $this->context();
        $this->mock(ShopifyProductMonitorReader::class)->shouldReceive('read')->andReturn($this->snapshot(), $this->snapshot(0));
        $service = app(ShopifyProductMonitorService::class);
        $service->configure($store, $actor, $product, true, 10);
        $service->scanStore($store);
        $service->scanStore($store);
        StoreNotificationSetting::create(['organization_id' => $store->organization_id, 'store_id' => $store->id,
            'feishu_enabled' => true, 'feishu_webhook_url' => 'https://open.feishu.cn/open-apis/bot/v2/hook/test', 'notify_product_monitor' => true]);
        Http::fake(['https://open.feishu.cn/*' => Http::response(['code' => 0])]);
        $alert = StoreAlert::sole();
        app(StoreAlertNotificationService::class)->deliver($alert);
        app(StoreAlertNotificationService::class)->deliver($alert->fresh());
        Http::assertSentCount(1);
        $this->assertSame('sent', $alert->fresh()->delivery_status);
    }

    public function test_reader_paginates_variants_and_locations_and_only_issues_queries(): void
    {
        [$store] = $this->context();
        ShopifyConnection::create(['store_id' => $store->id, 'shop_domain' => $store->shopify_domain,
            'status' => 'connected', 'api_version' => '2026-07', 'access_token_encrypted' => 'fake', 'scopes' => ['read_products', 'read_inventory', 'read_locations']]);
        Http::fake(function ($request) {
            $this->assertStringNotContainsString('mutation', $request['query']);
            $after = $request['variables']['after'];
            if (str_contains($request['query'], 'MonitorProduct')) {
                return Http::response(['data' => ['product' => ['id' => 'gid://shopify/Product/123', 'title' => 'Bike', 'status' => 'ACTIVE',
                    'updatedAt' => '2026-09-01T00:00:00Z', 'publishedAt' => null, 'variants' => ['nodes' => [['id' => $after ? 'v2' : 'v1', 'title' => 'Red', 'sku' => '',
                        'inventoryItem' => ['id' => 'gid://shopify/InventoryItem/1', 'tracked' => ! $after]]], 'pageInfo' => ['hasNextPage' => ! $after, 'endCursor' => 'next']]]]]);
            }

            return Http::response(['data' => ['inventoryItem' => ['inventoryLevels' => ['nodes' => [['location' => ['id' => $after ? 'l2' : 'l1', 'name' => 'Warehouse', 'isActive' => true],
                'quantities' => [['name' => 'available', 'quantity' => $after ? 0 : 100]]]], 'pageInfo' => ['hasNextPage' => ! $after, 'endCursor' => 'next']]]]]);
        });
        $snapshot = app(ShopifyProductMonitorReader::class)->read($store, '123');
        $this->assertCount(2, $snapshot['variants']);
        $this->assertCount(2, $snapshot['variants']['v1']['levels']);
        $this->assertSame(0, $snapshot['variants']['v1']['levels']['l2']['available']);
        $this->assertFalse($snapshot['variants']['v2']['tracked']);
        Http::assertSentCount(4);
    }
}
