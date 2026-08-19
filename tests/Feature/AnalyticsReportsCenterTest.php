<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Organization;
use App\Models\Role;
use App\Models\ShopifyConnection;
use App\Models\Store;
use App\Models\User;
use App\Services\AnalyticsCacheVersionService;
use App\Services\AnalyticsQueryService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AnalyticsReportsCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_custom_period_uses_accurate_order_scope_and_financial_metrics(): void
    {
        [$user, $organization, $store] = $this->context('organization-admin');
        $today = Carbon::now('UTC')->startOfDay()->addHours(12);
        $this->order($organization, $store, 'valid', $today, [
            'subtotal_price' => 100, 'net_sales' => 80, 'discount_total' => 20,
            'refund_total' => 20, 'total_tax' => 8, 'shipping_total' => 5, 'total_price' => 93,
        ]);
        $this->order($organization, $store, 'test', $today, ['net_sales' => 999, 'is_test' => true]);
        $this->order($organization, $store, 'cancelled', $today, ['net_sales' => 888, 'cancelled_at' => $today]);

        $this->actingAs($user)->withSession($this->contextSession($organization, $store))
            ->get(route('analytics.sales', ['date_from' => $today->toDateString(), 'date_to' => $today->toDateString()]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Analytics/Sales')
            ->where('analytics.period.timezone', 'UTC')
            ->where('analytics.period.days', 1)
            ->where('analytics.summary.orders', 1)
            ->where('analytics.summary.gross_sales', 120)
            ->where('analytics.summary.net_sales', 80)
            ->where('analytics.summary.discounts', 20)
            ->where('analytics.summary.refunds', 20)
            ->where('analytics.summary.taxes', 8)
            ->where('analytics.summary.shipping', 5)
            ->where('analytics.summary.average_order_value', 80)
            ->has('analytics.comparisons.previous.net_sales')
            ->has('analytics.comparisons.previous.orders')
            ->has('analytics.comparisons.previous.average_order_value')
            ->has('analytics.comparisons.previous.refunds')
            ->has('analytics.comparisons.previous.discounts')
            ->has('analytics.comparisons.previous.taxes')
            ->has('analytics.comparisons.year_over_year.discounts')
            ->has('analytics.comparisons.year_over_year.taxes'));
    }

    public function test_operations_overview_uses_real_store_scoped_data_and_marks_unavailable_traffic_metrics(): void
    {
        [$user, $organization, $store] = $this->context('organization-admin');
        $other = $organization->stores()->create([
            'name' => 'Other Store', 'shopify_domain' => strtolower(fake()->unique()->lexify('????????')).'.myshopify.com',
            'status' => 'active', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);
        $this->order($organization, $store, 'overview-visible', now(), [
            'net_sales' => 75, 'subtotal_price' => 90, 'discount_total' => 15,
            'refund_total' => 5, 'shipping_total' => 8, 'total_tax' => 7, 'total_price' => 90,
        ]);
        $this->order($organization, $other, 'overview-hidden', now(), ['net_sales' => 999]);

        $this->actingAs($user)->withSession($this->contextSession($organization, $store))
            ->get(route('analytics.overview'))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Analytics/Overview')
            ->where('store.id', $store->id)
            ->where('overview.schema', 'operations-overview-v1')
            ->where('overview.summary.net_sales', 75)
            ->where('overview.summary.orders', 1)
            ->where('overview.traffic.available', false)
            ->where('overview.traffic.reason_code', 'web_pixel_not_connected')
            ->has('overview.sales_breakdown', 7)
            ->has('overview.order_statuses.financial'));
    }

    public function test_report_center_is_store_scoped_and_exports_csv_and_excel(): void
    {
        [$admin, $organization, $store] = $this->context('organization-admin');
        $hidden = $organization->stores()->create([
            'name' => 'Hidden', 'shopify_domain' => 'hidden-reports.myshopify.com', 'status' => 'active', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);
        $this->order($organization, $store, 'visible', now(), ['net_sales' => 50]);
        $this->order($organization, $hidden, 'hidden', now(), ['net_sales' => 999]);
        $session = $this->contextSession($organization, $store);

        $this->actingAs($admin)->withSession($session)->get(route('reports.index'))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Reports/Index')
            ->has('reports', 38)
            ->where('reports.0.slug', 'store-overview')
            ->where('reports.0.creator', 'DecoAdmin'));
        $this->actingAs($admin)->withSession($session)->get(route('reports.show', ['report' => 'sales-over-time']))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Reports/Show')
            ->where('store.id', $store->id)
            ->where('report.slug', 'sales-over-time')
            ->where('report.summary.net_sales', 50)
            ->where('canExport', true));
        $this->actingAs($admin)->withSession($session)->get(route('reports.export', ['report' => 'sales-over-time', 'format' => 'csv']))
            ->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->actingAs($admin)->withSession($session)->get(route('reports.export', ['report' => 'sales-over-time', 'format' => 'excel']))
            ->assertOk()->assertHeader('content-type', 'application/vnd.ms-excel; charset=UTF-8');
        $this->actingAs($admin)->withSession($session)->get(route('reports.show', ['report' => 'not-a-report']))
            ->assertNotFound();
    }

    public function test_shopifyql_reports_require_read_reports_and_return_native_rows_when_authorized(): void
    {
        [$admin, $organization, $store] = $this->context('organization-admin');
        $connection = ShopifyConnection::query()->create([
            'store_id' => $store->id,
            'shop_domain' => $store->shopify_domain,
            'access_token_encrypted' => 'token',
            'token_type' => 'offline',
            'scopes' => ['read_orders'],
            'api_version' => '2026-07',
            'status' => 'connected',
        ]);
        $session = $this->contextSession($organization, $store);

        $this->actingAs($admin)->withSession($session)->get(route('reports.index'))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('reports.29.slug', 'acquisition-by-source')
            ->where('reports.29.data_source', 'shopifyql')
            ->where('reports.29.available', false)
            ->where('reports.29.requires_scope', 'read_reports'));
        $this->actingAs($admin)->withSession($session)->get(route('reports.show', ['report' => 'acquisition-by-source']))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('report.integration.source', 'shopifyql')
            ->where('report.integration.available', false)
            ->where('report.integration.scope_granted', false)
            ->where('report.integration.error', '缺少 read_reports，请重新授权店铺。')
            ->has('report.rows', 0));

        $connection->update(['scopes' => ['read_orders', 'read_reports']]);
        Http::fake(fn (Request $request) => Http::response(['data' => ['shopifyqlQuery' => [
            'tableData' => [
                'columns' => [],
                'rows' => [[
                    'referrer_source' => 'Social',
                    'referrer_name' => 'Instagram',
                    'sessions' => '12',
                    'online_store_visitors' => '10',
                    'sessions_that_completed_checkout' => '3',
                    'conversion_rate' => '0.25',
                ]],
            ],
            'parseErrors' => [],
        ]]]));

        $this->actingAs($admin)->withSession($session)->get(route('reports.show', ['report' => 'acquisition-by-source']))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('report.integration.available', true)
            ->where('report.integration.scope_granted', true)
            ->where('report.rows.0.referrer_source', 'Social')
            ->where('report.rows.0.referrer_name', 'Instagram')
            ->where('report.rows.0.sessions', 12)
            ->where('report.rows.0.conversion_rate', 25));

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), $store->shopify_domain)
            && str_contains((string) data_get($request->data(), 'variables.query'), 'FROM sessions')
            && str_contains((string) data_get($request->data(), 'variables.query'), 'GROUP BY referrer_source, referrer_name')
        );
    }

    public function test_derived_report_keeps_the_existing_report_dataset(): void
    {
        [$admin, $organization, $store] = $this->context('organization-admin');
        $this->order($organization, $store, 'gross-sales-report', now()->subMinute(), [
            'subtotal_price' => 100,
            'discount_total' => 10,
            'net_sales' => 90,
        ]);

        $this->actingAs($admin)->withSession($this->contextSession($organization, $store))
            ->get(route('reports.show', ['report' => 'gross-sales-over-time']))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Reports/Show')
            ->where('report.slug', 'gross-sales-over-time')
            ->where('report.selected.metric', 'gross_sales')
            ->has('report.rows'));
    }

    public function test_live_view_is_store_scoped_and_does_not_collect_raw_ip_data(): void
    {
        [$admin, $organization, $store] = $this->context('organization-admin');
        $other = $organization->stores()->create([
            'name' => 'Other Live Store',
            'shopify_domain' => 'other-live.myshopify.com',
            'status' => 'active',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);
        $this->order($organization, $store, 'live-visible', now()->subMinute(), ['net_sales' => 50]);
        $this->order($organization, $other, 'live-hidden', now()->subMinute(), ['net_sales' => 999]);
        $session = $this->contextSession($organization, $store);

        $this->actingAs($admin)->withSession($session)->get(route('analytics.live'))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Analytics/Live')
            ->where('snapshot.schema', 'live-view-v1')
            ->where('snapshot.store.id', $store->id)
            ->where('snapshot.metrics.orders.value', 1)
            ->where('snapshot.metrics.net_sales.value', 50)
            ->where('snapshot.metrics.current_visitors.available', false)
            ->where('snapshot.insights.visits_by_location.message', '此日期范围内无数据')
            ->where('snapshot.insights.new_vs_returning.message', '此日期范围内无数据')
            ->where('snapshot.insights.sales_by_product.message', '此日期范围内无数据')
            ->where('snapshot.traffic.reason_code', 'web_pixel_not_connected')
            ->where('snapshot.privacy.location_precision', 'coarse')
            ->where('snapshot.privacy.raw_ip_collected', false)
            ->has('snapshot.locations', 0));

        $this->actingAs($admin)->withSession($session)->get(route('analytics.live.data'))
            ->assertOk()
            ->assertJsonPath('store.id', $store->id)
            ->assertJsonPath('metrics.orders.value', 1)
            ->assertJsonPath('insights.sales_by_product.available', false)
            ->assertJsonPath('privacy.raw_ip_collected', false);
    }

    public function test_viewer_can_view_reports_but_cannot_export(): void
    {
        [$viewer, $organization, $store] = $this->context('viewer');
        $session = $this->contextSession($organization, $store);

        $this->actingAs($viewer)->withSession($session)->get(route('reports.index'))->assertOk();
        $this->actingAs($viewer)->withSession($session)->get(route('reports.show', ['report' => 'sales-over-time']))->assertOk();
        $this->actingAs($viewer)->withSession($session)->get(route('reports.export', ['report' => 'sales-over-time', 'format' => 'csv']))->assertForbidden();
    }

    public function test_analytics_cache_is_invalidated_by_store_version(): void
    {
        [, $organization, $store] = $this->context('organization-admin');
        $order = $this->order($organization, $store, 'cached', now(), ['net_sales' => 10]);
        $analytics = app(AnalyticsQueryService::class);

        $this->assertSame(10.0, $analytics->sales($store, 30)['summary']['net_sales']);
        $order->update(['net_sales' => 25]);
        $this->assertSame(10.0, $analytics->sales($store, 30)['summary']['net_sales']);
        app(AnalyticsCacheVersionService::class)->bump($store->id);
        $this->assertSame(25.0, $analytics->sales($store, 30)['summary']['net_sales']);
    }

    /** @return array{User, Organization, Store} */
    private function context(string $roleSlug): array
    {
        $this->seed(PermissionSeeder::class);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $organization = Organization::query()->create(['name' => 'Reports Org', 'code' => 'reports-'.strtolower(fake()->unique()->lexify('????????'))]);
        $organization->users()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $store = $organization->stores()->create([
            'name' => 'Reports Store', 'shopify_domain' => strtolower(fake()->unique()->lexify('????????')).'.myshopify.com',
            'status' => 'active', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);
        $store->members()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $this->seed(RoleSeeder::class);
        $role = Role::query()->whereBelongsTo($organization)->where('slug', $roleSlug)->firstOrFail();
        $user->roles()->attach($role, ['organization_id' => $organization->id, 'store_id' => null]);

        return [$user, $organization, $store];
    }

    /** @param array<string, mixed> $values */
    private function order(Organization $organization, Store $store, string $id, mixed $date, array $values): Order
    {
        return Order::query()->create([
            'organization_id' => $organization->id, 'store_id' => $store->id, 'shopify_order_id' => $id,
            'order_number' => '#'.$id, 'financial_status' => 'paid', 'currency' => 'USD',
            'total_price' => $values['total_price'] ?? $values['net_sales'] ?? 0,
            'subtotal_price' => $values['subtotal_price'] ?? $values['net_sales'] ?? 0,
            'net_sales' => $values['net_sales'] ?? 0, 'discount_total' => $values['discount_total'] ?? 0,
            'refund_total' => $values['refund_total'] ?? 0, 'shipping_total' => $values['shipping_total'] ?? 0,
            'total_tax' => $values['total_tax'] ?? 0, 'is_test' => $values['is_test'] ?? false,
            'cancelled_at' => $values['cancelled_at'] ?? null, 'processed_at' => $date,
            'created_at_shopify' => $date, 'synced_at' => now(),
        ]);
    }

    /** @return array{current_organization_id: int, current_store_id: int} */
    private function contextSession(Organization $organization, Store $store): array
    {
        return ['current_organization_id' => $organization->id, 'current_store_id' => $store->id];
    }
}
