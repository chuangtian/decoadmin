<?php

namespace Tests\Feature;

use App\Jobs\DeliverStoreAlertNotificationJob;
use App\Models\Location;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Role;
use App\Models\ShopifyConnection;
use App\Models\Store;
use App\Models\StoreAlert;
use App\Models\StoreNotificationSetting;
use App\Models\SyncJob;
use App\Models\User;
use App\Services\StoreOperationalAlertService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ShopifyOperationsDashboardAlertsTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_uses_only_current_store_metrics(): void
    {
        [$user, $organization, $store] = $this->context('organization-admin');
        $other = $organization->stores()->create(['name' => 'EU', 'shopify_domain' => 'eu-dashboard.myshopify.com', 'status' => 'active']);
        $this->order($organization, $store, '1001', '125.50');
        $this->order($organization, $other, '2001', '999.00');
        Product::query()->create(['organization_id' => $organization->id, 'store_id' => $store->id, 'shopify_product_id' => 'p1', 'title' => 'Bike', 'handle' => 'bike', 'status' => 'active', 'synced_at' => now()]);

        $this->actingAs($user)->withSession($this->contextSession($organization, $store))->get(route('dashboard'))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Index')
            ->where('dashboard.store.id', $store->id)
            ->where('dashboard.summary.orders', 1)
            ->where('dashboard.summary.sales', '125.5')
            ->where('dashboard.summary.products', 1)
            ->has('dashboard.sales_trend', 7));
    }

    public function test_order_fulfillment_filter_and_locations_are_store_scoped(): void
    {
        [$user, $organization, $store] = $this->context('organization-admin');
        $fulfilled = $this->order($organization, $store, '1001', '10.00', 'fulfilled');
        $this->order($organization, $store, '1002', '20.00');
        $other = $organization->stores()->create(['name' => 'EU', 'shopify_domain' => 'eu-scope.myshopify.com', 'status' => 'active']);
        $visible = Location::query()->create(['organization_id' => $organization->id, 'store_id' => $store->id, 'shopify_location_id' => 'l1', 'name' => 'US Warehouse', 'active' => true, 'synced_at' => now()]);
        Location::query()->create(['organization_id' => $organization->id, 'store_id' => $other->id, 'shopify_location_id' => 'l2', 'name' => 'Hidden Warehouse', 'active' => true, 'synced_at' => now()]);

        $this->actingAs($user)->withSession($this->contextSession($organization, $store))->get(route('orders.index', ['fulfillment_status' => 'fulfilled']))
            ->assertInertia(fn (Assert $page) => $page->where('orders.data.0.id', $fulfilled->id)->has('orders.data', 1));
        $this->actingAs($user)->withSession($this->contextSession($organization, $store))->get(route('locations.index'))
            ->assertInertia(fn (Assert $page) => $page->where('locations.data.0.id', $visible->id)->has('locations.data', 1));
    }

    public function test_alert_scan_is_deduplicated_sanitized_and_queued(): void
    {
        Queue::fake();
        [, $organization, $store] = $this->context('organization-admin');
        ShopifyConnection::query()->create(['store_id' => $store->id, 'shop_domain' => $store->shopify_domain, 'access_token_encrypted' => 'token', 'token_type' => 'offline', 'scopes' => [], 'api_version' => '2026-07', 'status' => 'connected']);
        SyncJob::query()->create(['uuid' => (string) Str::uuid(), 'organization_id' => $organization->id, 'store_id' => $store->id, 'type' => 'products', 'direction' => 'pull', 'status' => 'failed', 'last_error' => 'access_token=secret-token', 'failed_at' => now()]);
        $service = app(StoreOperationalAlertService::class);

        $this->assertSame(1, $service->scan($store)['created']);
        $this->assertSame(0, $service->scan($store)['created']);
        $alert = StoreAlert::query()->sole();
        $this->assertStringNotContainsString('secret-token', $alert->message);
        $this->assertSame('pending', $alert->delivery_status);
        Queue::assertPushed(DeliverStoreAlertNotificationJob::class, 1);
    }

    public function test_alert_actions_enforce_current_store_and_permissions(): void
    {
        [$admin, $organization, $store] = $this->context('organization-admin');
        $other = $organization->stores()->create(['name' => 'EU', 'shopify_domain' => 'eu-alert.myshopify.com', 'status' => 'active']);
        $alert = $this->alert($organization, $other);
        $this->actingAs($admin)->withSession($this->contextSession($organization, $store))->post(route('alerts.acknowledge', $alert))->assertNotFound();

        [$viewer,$viewerOrganization,$viewerStore] = $this->context('viewer', 'viewer-org');
        $viewerAlert = $this->alert($viewerOrganization, $viewerStore);
        $this->actingAs($viewer)->withSession($this->contextSession($viewerOrganization, $viewerStore))->post(route('alerts.acknowledge', $viewerAlert))->assertForbidden();
    }

    public function test_store_notification_settings_are_optional_encrypted_and_audited_without_secrets(): void
    {
        [$user,$organization,$store] = $this->context('organization-admin');
        $this->actingAs($user)->withSession($this->contextSession($organization, $store))->put(route('stores.notifications.update', $store), [
            'mail_enabled' => true, 'mail_host' => 'smtp.example.com', 'mail_port' => 587, 'mail_encryption' => 'tls', 'mail_username' => 'shop@example.com', 'mail_password' => 'mail-secret', 'mail_from_address' => 'shop@example.com', 'mail_from_name' => 'Shop', 'mail_recipients' => ['ops@example.com'],
            'feishu_enabled' => true, 'feishu_webhook_url' => 'https://open.feishu.cn/open-apis/bot/v2/hook/private-token', 'feishu_secret' => 'feishu-secret', 'notify_sync_failed' => true, 'notify_webhook_failed' => true, 'notify_connection_unhealthy' => true,
        ])->assertRedirect()->assertSessionHas('success');

        $setting = StoreNotificationSetting::query()->sole();
        $this->assertSame('mail-secret', $setting->mail_password);
        $raw = DB::table('store_notification_settings')->where('id', $setting->id)->first();
        $this->assertStringNotContainsString('mail-secret', (string) $raw->mail_password);
        $this->assertStringNotContainsString('private-token', (string) $raw->feishu_webhook_url);
        $audit = DB::table('audit_logs')->where('action', 'store_notification_settings_updated')->first();
        $this->assertStringNotContainsString('mail-secret', json_encode($audit, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('feishu-secret', json_encode($audit, JSON_THROW_ON_ERROR));
    }

    private function context(string $roleSlug, string $code = 'deco'): array
    {
        $this->seed(PermissionSeeder::class);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $organization = Organization::query()->create(['name' => strtoupper($code), 'code' => $code]);
        $organization->users()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $store = $organization->stores()->create(['name' => 'US', 'shopify_domain' => "{$code}-us.myshopify.com", 'status' => 'active', 'currency' => 'USD', 'timezone' => 'America/New_York']);
        $store->members()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $this->seed(RoleSeeder::class);
        $role = Role::query()->whereBelongsTo($organization)->where('slug', $roleSlug)->firstOrFail();
        $user->roles()->attach($role, ['organization_id' => $organization->id, 'store_id' => null]);

        return [$user, $organization, $store];
    }

    private function order(Organization $organization, Store $store, string $id, string $total, ?string $fulfillment = null): Order
    {
        return Order::query()->create(['organization_id' => $organization->id, 'store_id' => $store->id, 'shopify_order_id' => $id, 'order_number' => "#{$id}", 'financial_status' => 'paid', 'fulfillment_status' => $fulfillment, 'currency' => 'USD', 'total_price' => $total, 'subtotal_price' => $total, 'total_tax' => '0', 'processed_at' => now(), 'created_at_shopify' => now(), 'synced_at' => now()]);
    }

    private function alert(Organization $organization, Store $store): StoreAlert
    {
        return StoreAlert::query()->create(['organization_id' => $organization->id, 'store_id' => $store->id, 'fingerprint' => hash('sha256', (string) Str::uuid()), 'type' => 'sync', 'severity' => 'error', 'code' => 'sync_failed', 'title' => '同步失败', 'message' => '失败', 'status' => 'open', 'occurred_at' => now()]);
    }

    private function contextSession(Organization $organization, Store $store): array
    {
        return ['current_organization_id' => $organization->id, 'current_store_id' => $store->id];
    }
}
