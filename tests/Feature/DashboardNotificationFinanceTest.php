<?php

namespace Tests\Feature;

use App\Jobs\DeliverStoreAlertNotificationJob;
use App\Models\FinanceCategory;
use App\Models\FinanceEntry;
use App\Models\InventoryItem;
use App\Models\InventoryLevel;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreAlert;
use App\Models\StoreNotificationSetting;
use App\Models\User;
use App\Services\StoreAlertNotificationService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Tests\TestCase;

class DashboardNotificationFinanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_analytics_uses_real_current_store_data(): void
    {
        [$user, $organization, $store] = $this->context('organization-admin');
        $order = $this->order($organization, $store, '1001', '125.50');
        OrderItem::query()->create([
            'order_id' => $order->id,
            'shopify_line_item_id' => 'line-1001',
            'title' => 'Electric Bike',
            'quantity' => 2,
            'price' => 50,
        ]);
        $location = Location::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'shopify_location_id' => 'location-1',
            'name' => 'US Warehouse',
            'active' => true,
            'synced_at' => now(),
        ]);
        $inventory = InventoryItem::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'shopify_inventory_item_id' => 'inventory-1',
            'sku' => 'LOW-001',
            'tracked' => true,
            'synced_at' => now(),
        ]);
        InventoryLevel::query()->create([
            'inventory_item_id' => $inventory->id,
            'location_id' => $location->id,
            'shopify_location_id' => 'location-1',
            'available' => 3,
            'synced_at' => now(),
        ]);

        $this->actingAs($user)->withSession($this->contextSession($organization, $store))
            ->get(route('analytics.sales', ['days' => 30]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Analytics/Sales')
                ->where('analytics.summary.orders', 1)
                ->where('analytics.summary.sales', '125.5')
                ->has('analytics.trend', 30)
                ->where('analytics.top_products.0.title', 'Electric Bike')
                ->where('analytics.top_products.0.units', 2)
                ->where('analytics.low_stock.0.sku', 'LOW-001')
                ->where('analytics.low_stock.0.available', 3));
    }

    public function test_store_comparison_contains_only_authorized_stores(): void
    {
        [$user, $organization, $store] = $this->context('organization-admin');
        $authorized = $this->addStore($user, $organization, 'EU Store', 'analytics-eu.myshopify.com');
        $hidden = $organization->stores()->create([
            'name' => 'Hidden Store',
            'shopify_domain' => 'analytics-hidden.myshopify.com',
            'status' => 'active',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);
        $this->order($organization, $store, '1001', '100.00');
        $this->order($organization, $authorized, '1002', '200.00');
        $this->order($organization, $hidden, '1003', '999.00');

        $this->actingAs($user)->withSession($this->contextSession($organization, $store))
            ->get(route('analytics.stores'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Analytics/Stores')
                ->has('comparison.stores', 2)
                ->where('comparison.stores.0.id', $authorized->id)
                ->where('comparison.stores.1.id', $store->id));
    }

    public function test_notification_center_sends_configured_channels_and_tracks_attempts(): void
    {
        [$user, $organization, $store] = $this->context('organization-admin');
        StoreNotificationSetting::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'mail_enabled' => true,
            'mail_host' => 'smtp.example.com',
            'mail_port' => 465,
            'mail_encryption' => 'ssl',
            'mail_username' => 'alerts@example.com',
            'mail_password' => 'encrypted-by-model',
            'mail_from_address' => 'alerts@example.com',
            'mail_recipients' => ['ops@example.com'],
            'feishu_enabled' => true,
            'feishu_webhook_url' => 'https://open.feishu.cn/open-apis/bot/v2/hook/test-token',
            'notify_sync_failed' => true,
            'notify_webhook_failed' => true,
            'notify_connection_unhealthy' => true,
        ]);
        $alert = $this->alert($organization, $store);
        Http::fake(['open.feishu.cn/*' => Http::response(['code' => 0], 200)]);
        $mailer = Mockery::mock(Mailer::class);
        $mailer->shouldReceive('raw')->once();
        $manager = Mockery::mock(MailManager::class);
        $manager->shouldReceive('build')->once()->andReturn($mailer);

        (new StoreAlertNotificationService($manager))->deliver($alert);

        $alert->refresh();
        $this->assertSame('sent', $alert->delivery_status);
        $this->assertSame(1, $alert->delivery_attempts);
        $this->assertEqualsCanonicalizing(['mail', 'feishu'], $alert->context['notification_channels']);
        $this->assertNotNull($alert->notified_at);
        $this->assertNotNull($alert->last_delivery_at);
        Http::assertSentCount(1);

        $this->actingAs($user)->withSession($this->contextSession($organization, $store))
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Notifications/Index')
                ->has('notifications.data', 1)
                ->where('notifications.data.0.delivery_status', 'sent')
                ->where('notifications.data.0.delivery_attempts', 1)
                ->where('channels.mail', true)
                ->where('channels.feishu', true));
    }

    public function test_notification_resend_is_store_scoped_and_queued(): void
    {
        Queue::fake();
        [$user, $organization, $store] = $this->context('organization-admin');
        $other = $this->addStore($user, $organization, 'EU Store', 'notify-eu.myshopify.com');
        $visible = $this->alert($organization, $store);
        $hidden = $this->alert($organization, $other);

        $session = $this->contextSession($organization, $store);
        $this->actingAs($user)->withSession($session)->post(route('notifications.resend', $hidden))->assertNotFound();
        $this->actingAs($user)->withSession($session)->post(route('notifications.resend', $visible))->assertRedirect();

        Queue::assertPushed(DeliverStoreAlertNotificationJob::class, fn (DeliverStoreAlertNotificationJob $job): bool => $job->alertId === $visible->id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'store_alert_notification_resent', 'store_id' => $store->id]);
    }

    public function test_company_finance_records_are_scoped_and_summarized(): void
    {
        [$user, $organization, $store] = $this->context('organization-admin');
        $session = $this->contextSession($organization, $store);
        $this->actingAs($user)->withSession($session)->post(route('finance.categories.store'), [
            'name' => '产品销售',
            'type' => 'income',
            'color' => 'emerald',
            'is_active' => true,
        ])->assertRedirect();
        $income = FinanceCategory::query()->where('organization_id', $organization->id)->sole();
        $expense = FinanceCategory::query()->create([
            'organization_id' => $organization->id,
            'name' => '广告费用',
            'type' => 'expense',
            'color' => 'rose',
            'is_active' => true,
            'created_by' => $user->id,
        ]);
        $this->createFinanceEntry($user, $organization, $store, $income, 'income', '1000.00');
        $this->createFinanceEntry($user, $organization, $store, $expense, 'expense', '250.00');

        $this->actingAs($user)->withSession($session)->get(route('finance.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Finance/Index')
                ->where('finance.summary.income', 1000)
                ->where('finance.summary.expense', 250)
                ->where('finance.summary.profit', 750)
                ->where('finance.store_profit.0.id', $store->id)
                ->where('finance.store_profit.0.profit', 750)
                ->has('finance.entries.data', 2)
                ->where('canManage', true));
    }

    public function test_finance_rejects_unauthorized_store_and_viewer(): void
    {
        [$admin, $organization, $store] = $this->context('organization-admin');
        $hidden = $organization->stores()->create([
            'name' => 'Hidden Store',
            'shopify_domain' => 'finance-hidden.myshopify.com',
            'status' => 'active',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);
        $category = FinanceCategory::query()->create([
            'organization_id' => $organization->id,
            'name' => '其他收入',
            'type' => 'income',
            'color' => 'emerald',
            'is_active' => true,
            'created_by' => $admin->id,
        ]);
        $payload = [
            'type' => 'income',
            'category_id' => $category->id,
            'store_id' => $hidden->id,
            'amount' => 100,
            'occurred_on' => now()->toDateString(),
            'description' => '不应保存',
        ];
        $this->actingAs($admin)->withSession($this->contextSession($organization, $store))
            ->post(route('finance.entries.store'), $payload)->assertForbidden();
        $this->assertDatabaseCount('finance_entries', 0);

        $viewer = User::factory()->create(['email_verified_at' => now()]);
        $organization->users()->attach($viewer, ['status' => 'active', 'joined_at' => now()]);
        $store->members()->attach($viewer, ['status' => 'active', 'joined_at' => now()]);
        $viewerRole = Role::query()->whereBelongsTo($organization)->where('slug', 'viewer')->firstOrFail();
        $viewer->roles()->attach($viewerRole, ['organization_id' => $organization->id, 'store_id' => null]);
        $this->actingAs($viewer)->withSession($this->contextSession($organization, $store))
            ->get(route('finance.index'))->assertForbidden();
    }

    private function context(string $roleSlug): array
    {
        $this->seed(PermissionSeeder::class);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $organization = Organization::query()->create(['name' => 'Deco', 'code' => 'deco-dashboard-v2', 'default_currency' => 'USD']);
        $organization->users()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $store = $this->addStore($user, $organization, 'US Store', 'dashboard-v2-us.myshopify.com');
        $this->seed(RoleSeeder::class);
        $role = Role::query()->whereBelongsTo($organization)->where('slug', $roleSlug)->firstOrFail();
        $user->roles()->attach($role, ['organization_id' => $organization->id, 'store_id' => null]);

        return [$user, $organization, $store];
    }

    private function addStore(User $user, Organization $organization, string $name, string $domain): Store
    {
        $store = $organization->stores()->create([
            'name' => $name,
            'shopify_domain' => $domain,
            'status' => 'active',
            'currency' => 'USD',
            'timezone' => 'America/New_York',
        ]);
        $store->members()->attach($user, ['status' => 'active', 'joined_at' => now()]);

        return $store;
    }

    private function order(Organization $organization, Store $store, string $id, string $total): Order
    {
        return Order::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'shopify_order_id' => $id,
            'order_number' => "#{$id}",
            'financial_status' => 'paid',
            'currency' => 'USD',
            'total_price' => $total,
            'subtotal_price' => $total,
            'net_sales' => $total,
            'total_tax' => 0,
            'processed_at' => now(),
            'created_at_shopify' => now(),
            'synced_at' => now(),
        ]);
    }

    private function alert(Organization $organization, Store $store): StoreAlert
    {
        return StoreAlert::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'fingerprint' => hash('sha256', (string) Str::uuid()),
            'type' => 'sync',
            'severity' => 'error',
            'code' => 'sync_failed',
            'title' => '同步失败',
            'message' => '商品同步任务失败。',
            'status' => 'open',
            'delivery_status' => 'pending',
            'occurred_at' => now(),
        ]);
    }

    private function createFinanceEntry(User $user, Organization $organization, Store $store, FinanceCategory $category, string $type, string $amount): FinanceEntry
    {
        return FinanceEntry::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'category_id' => $category->id,
            'type' => $type,
            'amount' => $amount,
            'currency' => 'USD',
            'occurred_on' => now()->toDateString(),
            'description' => $category->name,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
    }

    private function contextSession(Organization $organization, Store $store): array
    {
        return [
            'current_organization_id' => $organization->id,
            'current_store_id' => $store->id,
        ];
    }
}
