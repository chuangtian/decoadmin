<?php

namespace Tests\Feature;

use App\Jobs\RefreshShopifyAnalyticsSnapshot;
use App\Models\AnalyticsSnapshot;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Role;
use App\Models\ShopifyConnection;
use App\Models\Store;
use App\Models\StorefrontEvent;
use App\Models\User;
use App\Services\AnalyticsCacheVersionService;
use App\Services\AnalyticsQueryService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AnalyticsReportsCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_core_sales_uses_shopifyql_store_dates_and_shopify_totals(): void
    {
        Cache::flush();
        Carbon::setTestNow('2026-08-20 12:00:00 UTC');

        try {
            [$user, $organization, $store] = $this->context('organization-admin');
            $store->update(['timezone' => 'America/Los_Angeles']);
            ShopifyConnection::query()->create([
                'store_id' => $store->id,
                'shop_domain' => $store->shopify_domain,
                'access_token_encrypted' => 'token',
                'token_type' => 'offline',
                'scopes' => ['read_orders', 'read_reports'],
                'api_version' => '2026-07',
                'status' => 'connected',
            ]);

            Http::fake(function (Request $request) {
                $query = (string) data_get($request->data(), 'variables.query');
                if (str_contains($query, 'GROUP BY product_title, product_vendor, product_type')) {
                    return Http::response(['data' => ['shopifyqlQuery' => [
                        'tableData' => ['columns' => [], 'rows' => [[
                            'product_title' => 'Macfox X1S',
                            'product_vendor' => 'Macfox Bike',
                            'product_type' => 'Electric Bike',
                            'net_items_sold' => '474',
                            'gross_sales' => '727392',
                            'net_sales' => '626672',
                            'total_sales' => '672246.79',
                            'net_items_sold__totals' => '2774',
                            'net_sales__totals' => '1502581.39',
                        ]]],
                        'parseErrors' => [],
                    ]]]);
                }

                if (str_contains($query, 'GROUP BY new_or_returning_customer')) {
                    return Http::response(['data' => ['shopifyqlQuery' => [
                        'tableData' => ['columns' => [], 'rows' => [[
                            'new_or_returning_customer' => 'New',
                            'customers' => '1311',
                            'customers__totals' => '1679',
                        ], [
                            'new_or_returning_customer' => 'Returning',
                            'customers' => '457',
                            'customers__totals' => '1679',
                        ]]],
                        'parseErrors' => [],
                    ]]]);
                }

                if (str_contains($query, 'SHOW returning_customers, customers, returning_customer_rate')) {
                    return Http::response(['data' => ['shopifyqlQuery' => [
                        'tableData' => ['columns' => [], 'rows' => [[
                            'day' => '2026-07-21',
                            'returning_customers' => '15',
                            'customers' => '54',
                            'returning_customer_rate' => '0.2777',
                            'returning_customers__totals' => '457',
                            'customers__totals' => '1679',
                            'returning_customer_rate__totals' => '0.2721858',
                        ]]],
                        'parseErrors' => [],
                    ]]]);
                }

                if (str_contains($query, 'GROUP BY product_vendor') || str_contains($query, 'GROUP BY product_type')) {
                    return Http::response(['data' => ['shopifyqlQuery' => [
                        'tableData' => ['columns' => [], 'rows' => []],
                        'parseErrors' => [],
                    ]]]);
                }

                $comparison = str_contains($query, 'previous_year') ? 'previous_year' : 'previous_period';
                $row = [
                    'day' => '2026-07-21',
                    'total_sales' => '100',
                    'orders' => '2',
                    'average_order_value' => '871.605',
                    'gross_sales' => '110',
                    'discounts' => '-10',
                    'returns' => '-5',
                    'net_sales' => '95',
                    'taxes' => '4',
                    'shipping_charges' => '1',
                    'total_sales__totals' => '1616604.05',
                    'orders__totals' => '1820',
                    'average_order_value__totals' => '871.605',
                    'gross_sales__totals' => '1774674.62',
                    'discounts__totals' => '-179147.24',
                    'returns__totals' => '-92945.99',
                    'net_sales__totals' => '1502581.39',
                    'taxes__totals' => '106022.31',
                    'shipping_charges__totals' => '8000.35',
                ];
                foreach ([
                    'total_sales' => '1704127.8', 'orders' => '1788', 'average_order_value' => '900',
                    'gross_sales' => '1830000', 'discounts' => '-170000', 'returns' => '-85000',
                    'net_sales' => '1575000', 'taxes' => '101000', 'shipping_charges' => '7900',
                ] as $metric => $value) {
                    $row["comparison_{$metric}__{$comparison}"] = '1';
                    $row["comparison_{$metric}__{$comparison}__totals"] = $value;
                    $row["percent_change_{$metric}__{$comparison}"] = '99';
                    $row["percent_change_{$metric}__{$comparison}__totals"] = $comparison === 'previous_period' ? '-2.842' : '1.25';
                }

                return Http::response(['data' => ['shopifyqlQuery' => [
                    'tableData' => ['columns' => [], 'rows' => [$row]],
                    'parseErrors' => [],
                ]]]);
            });

            $this->actingAs($user)->withSession($this->contextSession($organization, $store))
                ->get(route('analytics.sales', ['days' => 30]))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('analytics.period.from', '2026-07-21')
                    ->where('analytics.period.to', '2026-08-20')
                    ->where('analytics.period.timezone', 'America/Los_Angeles')
                    ->where('analytics.period.include_cancelled', true)
                    ->where('analytics.comparison.mode', 'previous')
                    ->where('analytics.comparison.period.from', '2026-06-20')
                    ->where('analytics.comparison.period.to', '2026-07-20')
                    ->where('analytics.summary.total_sales', 1616604.05)
                    ->where('analytics.summary.orders', 1820)
                    ->where('analytics.summary.net_sales', 1502581.39)
                    ->where('analytics.summary.discounts', 179147.24)
                    ->where('analytics.summary.refunds', 92945.99)
                    ->where('analytics.comparisons.previous.total_sales.baseline', 1704127.8)
                    ->where('analytics.comparisons.previous.total_sales.change_percent', -2.84)
                    ->where('analytics.comparisons.year_over_year.total_sales.change_percent', 1.25)
                    ->where('analytics.rankings.products.0.product_id', null)
                    ->where('analytics.rankings.products.0.units', 474)
                    ->where('analytics.rankings.products.0.gross_sales', 727392)
                    ->where('analytics.rankings.products.0.net_sales', 626672)
                    ->where('analytics.customers.active', 1679)
                    ->where('analytics.customers.new', 1311)
                    ->where('analytics.customers.returning', 457)
                    ->where('analytics.customers.repeat_rate', 27.22)
                    ->where('analytics.customers.average_lifetime_value', null)
                    ->where('analytics.data_source.primary', 'shopifyql')
                    ->where('analytics.trend.0.date', '2026-07-21')
                    ->where('analytics.comparison_trend.previous.0.date', '2026-06-20')
                    ->has('analytics.trend', 31));

            $queries = collect(Http::recorded())->map(
                fn (array $exchange): string => (string) data_get($exchange[0]->data(), 'variables.query'),
            )->filter();
            $this->assertCount(7, $queries);
            $this->assertTrue($queries->contains(fn (string $query): bool => str_contains(
                $query,
                'TIMESERIES day WITH TOTALS, PERCENT_CHANGE SINCE 2026-07-21 UNTIL 2026-08-20 COMPARE TO previous_period ORDER BY day',
            )));
            $this->assertTrue($queries->contains(fn (string $query): bool => str_contains($query, 'GROUP BY product_title, product_vendor, product_type')));
            $this->assertTrue($queries->contains(fn (string $query): bool => str_contains($query, 'GROUP BY new_or_returning_customer')));
            $this->assertTrue($queries->contains(fn (string $query): bool => str_contains($query, 'SHOW returning_customers, customers, returning_customer_rate')));
            $this->assertDatabaseCount('analytics_snapshots', 7);
        } finally {
            Carbon::setTestNow();
            Cache::flush();
        }
    }

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
            ->get(route('analytics.sales', [
                'date_from' => $today->toDateString(),
                'date_to' => $today->toDateString(),
                'include_cancelled' => false,
            ]))
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
            ->where('insights.schema', 'analytics-overview-insights-v1')
            ->where('insights.acquisition.available', false)
            ->where('insights.devices.available', false)
            ->where('insights.locations.available', false)
            ->where('insights.behavior.available', false)
            ->where('insights.integration.error', 'Shopify 连接不可用。')
            ->where('insights.customers.available', true)
            ->where('insights.pos.available', true)
            ->where('insights.pos.source', 'local_sync')
            ->where('performance.schema', 'analytics-operating-metrics-v1')
            ->where('performance.metrics.ad_spend.available', false)
            ->where('performance.metrics.sessions.available', false)
            ->where('performance.metrics.sessions.note', 'Shopify 连接不可用。')
            ->where('performance.behavior.message', 'Shopify 连接不可用。')
            ->has('overview.sales_breakdown', 7)
            ->has('overview.order_statuses.financial'));
    }

    public function test_operations_overview_exposes_pending_shopifyql_refresh_for_frontend_polling(): void
    {
        Queue::fake();
        [$user, $organization, $store] = $this->context('organization-admin');
        ShopifyConnection::query()->create([
            'store_id' => $store->id,
            'shop_domain' => $store->shopify_domain,
            'access_token_encrypted' => 'token',
            'token_type' => 'offline',
            'scopes' => ['read_orders', 'read_reports'],
            'api_version' => '2026-07',
            'status' => 'connected',
        ]);

        $this->actingAs($user)->withSession($this->contextSession($organization, $store))
            ->get(route('analytics.overview', ['date_from' => '2026-08-01', 'date_to' => '2026-08-20']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('insights.behavior.available', false)
                ->where('insights.behavior.error', 'Shopify 报表正在刷新，请稍后重试。')
                ->where('insights.integration.storage.pending', true)
                ->where('insights.integration.storage.refreshing', true)
                ->where('performance.behavior.available', false)
                ->where('performance.behavior.message', 'Shopify 报表正在刷新，请稍后重试。')
                ->where('performance.metrics.sessions.note', 'Shopify 报表正在刷新，请稍后重试。')
                ->where('performance.metrics.add_to_cart_cost.note', 'Shopify 报表正在刷新，请稍后重试。'));

        $snapshot = AnalyticsSnapshot::query()
            ->where('organization_id', $organization->id)
            ->where('store_id', $store->id)
            ->where('report_key', 'analytics-overview')
            ->sole();
        Queue::assertPushed(
            RefreshShopifyAnalyticsSnapshot::class,
            fn (RefreshShopifyAnalyticsSnapshot $job): bool => $job->snapshotId === $snapshot->id,
        );
    }

    public function test_operations_overview_exposes_missing_read_reports_as_permanent_error(): void
    {
        [$user, $organization, $store] = $this->context('organization-admin');
        ShopifyConnection::query()->create([
            'store_id' => $store->id,
            'shop_domain' => $store->shopify_domain,
            'access_token_encrypted' => 'token',
            'token_type' => 'offline',
            'scopes' => ['read_orders'],
            'api_version' => '2026-07',
            'status' => 'connected',
        ]);

        $this->actingAs($user)->withSession($this->contextSession($organization, $store))
            ->get(route('analytics.overview'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('insights.integration.report_scope_granted', false)
                ->where('insights.integration.error', '缺少 read_reports，请重新授权店铺。')
                ->where('insights.behavior.error', '缺少 read_reports，请重新授权店铺。')
                ->where('performance.behavior.message', '缺少 read_reports，请重新授权店铺。')
                ->where('performance.metrics.checkout.note', '缺少 read_reports，请重新授权店铺。'));
    }

    public function test_operations_overview_connects_shopifyql_acquisition_device_location_and_pos_data(): void
    {
        [$user, $organization, $store] = $this->context('organization-admin');
        ShopifyConnection::query()->create([
            'store_id' => $store->id,
            'shop_domain' => $store->shopify_domain,
            'access_token_encrypted' => 'token',
            'token_type' => 'offline',
            'scopes' => ['read_orders', 'read_locations', 'read_reports'],
            'api_version' => '2026-07',
            'status' => 'connected',
        ]);
        $this->order($organization, $store, 'overview-customer', now(), ['net_sales' => 40]);

        Http::fake(function (Request $request) {
            $variables = data_get($request->data(), 'variables', []);
            $this->assertStringContainsString('GROUP BY referrer_source', (string) ($variables['acquisition'] ?? ''));
            $this->assertStringContainsString('GROUP BY session_device_type', (string) ($variables['devices'] ?? ''));
            $this->assertStringContainsString('GROUP BY session_country', (string) ($variables['locations'] ?? ''));
            $this->assertStringContainsString('sessions_with_cart_additions', (string) ($variables['behavior'] ?? ''));

            return Http::response(['data' => [
                'acquisition' => ['tableData' => ['rows' => [[
                    'referrer_source' => 'Social', 'referrer_name' => 'Instagram', 'sessions' => '20',
                    'online_store_visitors' => '15', 'sessions_that_completed_checkout' => '4', 'conversion_rate' => '0.2',
                ]]], 'parseErrors' => []],
                'devices' => ['tableData' => ['rows' => [[
                    'session_device_type' => 'mobile', 'sessions' => '16', 'pageviews' => '30',
                    'bounce_rate' => '0.25', 'conversion_rate' => '0.2',
                ], [
                    'session_device_type' => 'desktop', 'sessions' => '4', 'pageviews' => '8',
                    'bounce_rate' => '0.5', 'conversion_rate' => '0.1',
                ]]], 'parseErrors' => []],
                'locations' => ['tableData' => ['rows' => [[
                    'session_country' => 'Germany', 'session_region' => 'Berlin', 'sessions' => '12',
                    'online_store_visitors' => '10', 'conversion_rate' => '0.25',
                ]]], 'parseErrors' => []],
                'posLocations' => ['tableData' => ['rows' => [[
                    'pos_location_id' => '501', 'pos_location_name' => 'Berlin Store', 'orders' => '3',
                    'net_sales' => '270', 'total_sales' => '300',
                ]]], 'parseErrors' => []],
                'posStaff' => ['tableData' => ['rows' => [[
                    'staff_id' => '601', 'staff_member_name' => 'Alex', 'orders' => '2',
                    'net_items_sold' => '5', 'net_sales' => '180', 'total_sales' => '200',
                ]]], 'parseErrors' => []],
                'behavior' => ['tableData' => [
                    'columns' => [
                        ['name' => 'opaque_comparison_sessions', 'dynamicColumnMetadata' => ['type' => 'COMPARISON_TOTALS', 'originalColumnName' => 'sessions', 'comparisonReference' => 'previous_period']],
                        ['name' => 'opaque_percent_sessions', 'dynamicColumnMetadata' => ['type' => 'PERCENT_CHANGE_TOTALS', 'originalColumnName' => 'sessions', 'comparisonReference' => 'previous_period']],
                        ['name' => 'opaque_comparison_cart', 'dynamicColumnMetadata' => ['type' => 'COMPARISON_TOTALS', 'originalColumnName' => 'sessions_with_cart_additions', 'comparisonReference' => 'previous_period']],
                        ['name' => 'opaque_percent_cart', 'dynamicColumnMetadata' => ['type' => 'PERCENT_CHANGE_TOTALS', 'originalColumnName' => 'sessions_with_cart_additions', 'comparisonReference' => 'previous_period']],
                        ['name' => 'opaque_comparison_checkout', 'dynamicColumnMetadata' => ['type' => 'COMPARISON_TOTALS', 'originalColumnName' => 'sessions_that_reached_checkout', 'comparisonReference' => 'previous_period']],
                        ['name' => 'opaque_percent_checkout', 'dynamicColumnMetadata' => ['type' => 'PERCENT_CHANGE_TOTALS', 'originalColumnName' => 'sessions_that_reached_checkout', 'comparisonReference' => 'previous_period']],
                    ],
                    'rows' => [[
                        'sessions__totals' => '20', 'sessions_with_cart_additions__totals' => '8',
                        'sessions_that_reached_checkout__totals' => '5', 'sessions_that_completed_checkout__totals' => '4',
                        'conversion_rate__totals' => '0.2', 'opaque_comparison_sessions' => '16',
                        'opaque_percent_sessions' => '25', 'opaque_comparison_cart' => '6',
                        'opaque_percent_cart' => '33.33', 'opaque_comparison_checkout' => '4',
                        'opaque_percent_checkout' => '25',
                    ]],
                ], 'parseErrors' => []],
            ]]);
        });

        $this->actingAs($user)->withSession($this->contextSession($organization, $store))
            ->get(route('analytics.overview'))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('insights.integration.report_scope_granted', true)
            ->where('insights.integration.shopifyql_available', true)
            ->where('insights.acquisition.available', true)
            ->where('insights.acquisition.items.0.label', 'Instagram')
            ->where('insights.acquisition.items.0.sessions', 20)
            ->where('insights.devices.items.0.label', '移动设备')
            ->where('insights.devices.items.0.share', 80)
            ->where('insights.locations.items.0.label', 'Berlin')
            ->where('insights.locations.items.0.country', 'Germany')
            ->where('insights.behavior.available', true)
            ->where('insights.behavior.metrics.sessions.value', 20)
            ->where('insights.behavior.metrics.add_to_cart.value', 8)
            ->where('insights.behavior.metrics.checkout.value', 5)
            ->where('performance.metrics.sessions.available', true)
            ->where('performance.metrics.sessions.value', 20)
            ->where('performance.metrics.add_to_cart.value', 8)
            ->where('performance.metrics.checkout.value', 5)
            ->where('insights.customers.source', 'local_sync')
            ->where('insights.pos.source', 'shopifyql')
            ->where('insights.integration.storage.persisted', true)
            ->where('insights.integration.storage.source', 'database')
            ->where('insights.integration.storage.stale', false)
            ->where('insights.pos.locations.0.name', 'Berlin Store')
            ->where('insights.pos.staff.0.name', 'Alex'));

        $shopifyQlRequests = collect(Http::recorded())->filter(
            fn (array $exchange): bool => str_contains((string) data_get($exchange[0]->data(), 'query'), 'shopifyqlQuery'),
        );
        $this->assertCount(1, $shopifyQlRequests);
        $this->assertDatabaseHas('analytics_snapshots', [
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'report_key' => 'analytics-overview',
        ]);

        $this->actingAs($user)->withSession($this->contextSession($organization, $store))
            ->get(route('analytics.overview'))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('insights.acquisition.items.0.label', 'Instagram')
            ->where('insights.devices.items.0.label', '移动设备'));
        $this->assertCount(1, collect(Http::recorded()));

        AnalyticsSnapshot::query()->where('report_key', 'analytics-overview')
            ->update(['expires_at' => now()->subMinute()]);
        $store->shopifyConnection()->update(['status' => 'disconnected']);

        $this->actingAs($user)->withSession($this->contextSession($organization, $store))
            ->get(route('analytics.overview'))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('insights.acquisition.items.0.label', 'Instagram')
            ->where('insights.integration.storage.stale', true)
            ->where('insights.pos.locations.0.name', 'Berlin Store'));
        $this->assertCount(1, collect(Http::recorded()));
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
            ->has('reports', 172)
            ->where('reports.0.slug', 'sessions_over_time')
            ->where('reports.0.creator', 'Shopify'));
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
            ->where('reports', function ($reports): bool {
                $report = collect($reports)->firstWhere('slug', 'sessions_by_referrer');

                return $report['data_source'] === 'shopifyql'
                    && $report['available'] === false
                    && $report['requires_scope'] === 'read_reports';
            }));
        $this->actingAs($admin)->withSession($session)->get(route('reports.show', ['report' => 'sessions_by_referrer']))
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

        $this->actingAs($admin)->withSession($session)->get(route('reports.show', ['report' => 'sessions_by_referrer']))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('report.integration.available', true)
            ->where('report.integration.scope_granted', true)
            ->where('report.integration.storage.persisted', true)
            ->where('report.integration.storage.source', 'database')
            ->where('report.integration.storage.stale', false)
            ->where('report.rows.0.referrer_source', 'Social')
            ->where('report.rows.0.referrer_name', 'Instagram')
            ->where('report.rows.0.sessions', 12)
            ->where('report.rows.0.conversion_rate', 25));

        $this->assertDatabaseHas('analytics_snapshots', [
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'report_key' => 'catalog:acquisition-by-source',
        ]);
        $this->assertCount(1, collect(Http::recorded()));

        $this->actingAs($admin)->withSession($session)
            ->get(route('reports.show', ['report' => 'sessions_by_referrer']))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('report.integration.available', true)
            ->where('report.integration.storage.stale', false)
            ->where('report.rows.0.referrer_name', 'Instagram'));
        $this->assertCount(1, collect(Http::recorded()));

        AnalyticsSnapshot::query()->where('report_key', 'catalog:acquisition-by-source')
            ->update(['expires_at' => now()->subMinute()]);
        $connection->update(['status' => 'disconnected']);

        $this->actingAs($admin)->withSession($session)
            ->get(route('reports.show', ['report' => 'sessions_by_referrer']))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('report.integration.available', true)
            ->where('report.integration.storage.stale', true)
            ->where('report.rows.0.referrer_name', 'Instagram'));
        $this->assertCount(1, collect(Http::recorded()));

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), $store->shopify_domain)
            && str_contains((string) data_get($request->data(), 'variables.query'), 'FROM sessions')
            && str_contains((string) data_get($request->data(), 'variables.query'), 'GROUP BY referrer_source, referrer_name')
        );
    }

    public function test_macfox_catalog_includes_store_custom_reports_and_preferences_are_store_scoped(): void
    {
        Carbon::setTestNow('2026-08-20 14:30:00 UTC');

        try {
            [$admin, $organization, $store] = $this->context('organization-admin');
            $store->update(['shopify_domain' => 'macfoxebike.myshopify.com']);
            $other = $organization->stores()->create([
                'name' => 'Other Reports Store', 'shopify_domain' => 'other-reports-store.myshopify.com',
                'status' => 'active', 'currency' => 'USD', 'timezone' => 'UTC',
            ]);
            $session = $this->contextSession($organization, $store);

            $this->actingAs($admin)->withSession($session)->get(route('reports.index'))
                ->assertOk()->assertInertia(fn (Assert $page) => $page
                ->has('reports', 177)
                ->where('reports', function ($reports): bool {
                    $catalog = collect($reports);
                    $breakdown = $catalog->firstWhere('slug', 'conversion_rate_breakdown');
                    $byStore = $catalog->firstWhere('slug', 'conversion_rate_over_time_by_store');
                    $vendor = $catalog->firstWhere('slug', 'total_sales_by_vendor');

                    return $catalog->where('kind', 'shopify_custom')->count() === 5
                        && $breakdown['data_source'] === 'shopifyql'
                        && $byStore['data_source'] === 'shopify_internal'
                        && $vendor['data_source'] === 'shopifyql';
                }));

            $this->actingAs($admin)->withSession($session)
                ->put(route('reports.pin', ['report' => 'sessions_over_time']), ['pinned' => true])
                ->assertRedirect();
            $this->actingAs($admin)->withSession($session)
                ->get(route('reports.show', ['report' => 'sessions_over_time']))
                ->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('report.integration.source', 'shopifyql')
                ->where('report.integration.available', false)
                ->where('report.creator', 'Shopify')
                ->where('report.external_url', 'https://admin.shopify.com/store/macfoxebike/analytics/reports/sessions_over_time'));

            $this->assertDatabaseHas('report_preferences', [
                'user_id' => $admin->id, 'organization_id' => $organization->id,
                'store_id' => $store->id, 'report_slug' => 'sessions_over_time',
            ]);
            $this->assertDatabaseMissing('report_preferences', [
                'user_id' => $admin->id, 'store_id' => $other->id, 'report_slug' => 'sessions_over_time',
            ]);

            $this->actingAs($admin)->withSession($session)->get(route('reports.index'))
                ->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('reports.0.slug', 'sessions_over_time')
                ->where('reports.0.pinned', true)
                ->where('reports.0.last_viewed_at', '2026-08-20T14:30:00+00:00'));
        } finally {
            Carbon::setTestNow();
        }
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

    public function test_model_sales_summary_uses_active_shopify_products_and_previous_period(): void
    {
        Cache::flush();
        [$admin, $organization, $store] = $this->context('organization-admin');
        ShopifyConnection::query()->create([
            'store_id' => $store->id,
            'shop_domain' => $store->shopify_domain,
            'access_token_encrypted' => 'token',
            'token_type' => 'offline',
            'scopes' => ['read_orders', 'read_reports'],
            'api_version' => '2026-07',
            'status' => 'connected',
        ]);

        Http::fake(function (Request $request) {
            $query = (string) data_get($request->data(), 'variables.query');
            $rows = [[
                'product_id' => 'gid://shopify/Product/100',
                'product_title' => 'Macfox X1S',
                'product_variant_id' => 'gid://shopify/ProductVariant/101',
                'product_variant_title' => 'Black',
                'product_variant_sku' => 'X1S-BLK',
                'net_items_sold' => '10',
                'total_sales' => '1000',
                'gross_sales' => '1100',
                'comparison_net_items_sold__previous_period' => '8',
                'comparison_total_sales__previous_period' => '800',
                'comparison_gross_sales__previous_period' => '850',
            ], [
                'product_id' => 'gid://shopify/Product/100',
                'product_title' => 'Macfox X1S',
                'product_variant_id' => 'gid://shopify/ProductVariant/102',
                'product_variant_title' => 'Nebula Purple',
                'product_variant_sku' => 'X1S-PUR',
                'net_items_sold' => '5',
                'total_sales' => '500',
                'gross_sales' => '550',
                'comparison_net_items_sold__previous_period' => '2',
                'comparison_total_sales__previous_period' => '200',
                'comparison_gross_sales__previous_period' => '220',
            ], [
                'product_id' => 'gid://shopify/Product/200',
                'product_title' => 'Macfox X7',
                'product_variant_id' => 'gid://shopify/ProductVariant/201',
                'product_variant_title' => 'Default Title',
                'product_variant_sku' => 'X7',
                'net_items_sold' => '3',
                'total_sales' => '300',
                'gross_sales' => '320',
                'comparison_net_items_sold__previous_period' => '0',
                'comparison_total_sales__previous_period' => '0',
                'comparison_gross_sales__previous_period' => '0',
            ]];

            return Http::response(['data' => ['shopifyqlQuery' => [
                'tableData' => ['columns' => [], 'rows' => $rows],
                'parseErrors' => [],
            ]]]);
        });

        $this->actingAs($admin)->withSession($this->contextSession($organization, $store))
            ->get(route('analytics.model-sales', ['date_from' => '2026-08-01', 'date_to' => '2026-08-20']))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Analytics/ModelSales')
            ->where('store.id', $store->id)
            ->where('report.schema', 'model-sales-summary-v1')
            ->where('report.period.comparison_from', '2026-07-12')
            ->where('report.period.comparison_to', '2026-07-31')
            ->where('report.summary.models', 2)
            ->where('report.summary.variants', 3)
            ->where('report.summary.net_items_sold', 18)
            ->where('report.summary.total_sales', 1800)
            ->where('report.models.0.title', 'Macfox X1S')
            ->where('report.models.0.variant_count', 2)
            ->where('report.models.0.current.total_sales', 1500)
            ->where('report.models.0.previous.total_sales', 1000)
            ->where('report.models.0.changes.net_items_sold.percent', 50)
            ->where('report.models.0.changes.total_sales.percent', 50)
            ->where('report.models.0.variants.0.changes.net_items_sold.percent', 25)
            ->where('report.models.0.variants.0.changes.total_sales.percent', 25)
            ->where('report.models.0.trend.key', 'hot')
            ->where('report.models.0.trend.percent', 50)
            ->where('report.models.1.title', 'Macfox X7')
            ->where('report.models.1.variants.0.title', '默认款')
            ->where('report.models.1.changes.net_items_sold.key', 'new')
            ->where('report.models.1.changes.total_sales.key', 'new')
            ->where('report.models.1.trend.key', 'new')
            ->where('report.integration.complete', true));

        Http::assertSent(fn (Request $request): bool => str_contains((string) data_get($request->data(), 'variables.query'), "product_status = 'Active'")
            && str_contains((string) data_get($request->data(), 'variables.query'), 'GROUP BY product_id, product_title, product_variant_id, product_variant_title, product_variant_sku')
            && str_contains((string) data_get($request->data(), 'variables.query'), 'WITH TOTALS, PERCENT_CHANGE')
            && str_contains((string) data_get($request->data(), 'variables.query'), 'COMPARE TO previous_period'));
        $modelReportRequests = collect(Http::recorded())->filter(
            fn (array $record): bool => str_contains(
                (string) data_get($record[0]->data(), 'variables.query'),
                "product_status = 'Active'",
            ),
        );
        $this->assertCount(1, $modelReportRequests);
        $this->assertDatabaseHas('analytics_snapshots', [
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'report_key' => 'catalog:model-sales-summary',
        ]);
    }

    public function test_live_view_uses_shopify_today_metrics_and_store_scoped_pixel_events(): void
    {
        Cache::flush();
        Carbon::setTestNow('2026-09-02 12:00:00 UTC');

        try {
            [$admin, $organization, $store] = $this->context('organization-admin');
            $other = $organization->stores()->create([
                'name' => 'Other Live Store',
                'shopify_domain' => 'other-live.myshopify.com',
                'status' => 'active',
                'currency' => 'USD',
                'timezone' => 'UTC',
            ]);
            ShopifyConnection::query()->create([
                'store_id' => $store->id,
                'shop_domain' => $store->shopify_domain,
                'access_token_encrypted' => 'token',
                'token_type' => 'offline',
                'scopes' => ['read_reports'],
                'api_version' => '2026-07',
                'status' => 'connected',
            ]);
            foreach ([
                ['page_viewed', 'session-current'],
                ['product_added_to_cart', 'session-cart'],
                ['checkout_started', 'session-checkout'],
                ['checkout_completed', 'session-purchased'],
            ] as $index => [$eventName, $sessionId]) {
                StorefrontEvent::query()->create([
                    'organization_id' => $organization->id,
                    'store_id' => $store->id,
                    'event_id' => "live-event-{$index}",
                    'event_name' => $eventName,
                    'client_id_hash' => "client-{$index}",
                    'session_id_hash' => $sessionId,
                    'occurred_at' => now()->subMinutes(2),
                    'country_code' => 'US',
                    'region_code' => 'CA',
                    'city' => 'Los Angeles',
                    'received_at' => now(),
                ]);
            }
            StorefrontEvent::query()->create([
                'organization_id' => $organization->id,
                'store_id' => $other->id,
                'event_id' => 'other-store-event',
                'event_name' => 'page_viewed',
                'client_id_hash' => 'other-client',
                'session_id_hash' => 'other-session',
                'occurred_at' => now()->subMinute(),
                'received_at' => now(),
            ]);

            Http::fake(fn () => Http::response(['data' => [
                'sales' => ['tableData' => ['rows' => [[
                    'hour' => '2026-09-02T10:00:00Z',
                    'total_sales' => '15000.00',
                    'orders' => '25',
                    'total_sales__totals' => '35000.00',
                    'orders__totals' => '61',
                ], [
                    'hour' => '2026-09-02T11:00:00Z',
                    'total_sales' => '20000.00',
                    'orders' => '36',
                ]]], 'parseErrors' => []],
                'sessions' => ['tableData' => ['rows' => [[
                    'hour' => '2026-09-02T10:00:00Z',
                    'sessions' => '9000',
                    'sessions__totals' => '19000',
                ], [
                    'hour' => '2026-09-02T11:00:00Z',
                    'sessions' => '10000',
                ]]], 'parseErrors' => []],
                'locations' => ['tableData' => ['rows' => [[
                    'session_country' => 'United States',
                    'session_region' => 'California',
                    'sessions' => '1207',
                ]]], 'parseErrors' => []],
                'sources' => ['tableData' => ['rows' => [[
                    'referrer_source' => 'Social',
                    'referrer_name' => 'Facebook',
                    'sessions' => '5000',
                ]]], 'parseErrors' => []],
                'customers' => ['tableData' => ['rows' => [[
                    'new_or_returning_customer' => 'New',
                    'customers' => '44',
                ], [
                    'new_or_returning_customer' => 'Returning',
                    'customers' => '17',
                ]]], 'parseErrors' => []],
                'products' => ['tableData' => ['rows' => [[
                    'product_title' => 'Macfox X7',
                    'product_vendor' => 'Macfox Bike',
                    'total_sales' => '10479.53',
                ]]], 'parseErrors' => []],
            ]]));

            $session = $this->contextSession($organization, $store);
            $this->actingAs($admin)->withSession($session)->get(route('analytics.live'))
                ->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component('Analytics/Live')
                ->where('snapshot.schema', 'live-view-v2')
                ->where('snapshot.store.id', $store->id)
                ->where('snapshot.period.label', '今日累计')
                ->where('snapshot.metrics.current_visitors.value', 4)
                ->where('snapshot.metrics.total_sales.value', 35000)
                ->where('snapshot.metrics.orders.value', 61)
                ->where('snapshot.metrics.visits.value', 19000)
                ->where('snapshot.customer_behavior.active_carts.value', 1)
                ->where('snapshot.customer_behavior.checking_out.value', 1)
                ->where('snapshot.customer_behavior.purchased.value', 1)
                ->where('snapshot.insights.visits_by_location.items.0.label', 'United States · California')
                ->where('snapshot.insights.new_vs_returning.items.0.label', '新客户')
                ->where('snapshot.insights.new_vs_returning.items.0.value', 44)
                ->where('snapshot.insights.sales_by_product.items.0.label', 'Macfox X7 · Macfox Bike')
                ->where('snapshot.insights.sales_by_product.items.0.value', 10479.53)
                ->where('snapshot.traffic.status', 'active')
                ->where('snapshot.shopify.complete', true)
                ->where('snapshot.privacy.location_precision', 'coarse')
                ->where('snapshot.privacy.raw_ip_collected', false)
                ->has('snapshot.locations', 1));

            $this->actingAs($admin)->withSession($session)->get(route('analytics.live.data'))
                ->assertOk()
                ->assertJsonPath('store.id', $store->id)
                ->assertJsonPath('metrics.total_sales.value', 35000)
                ->assertJsonPath('metrics.orders.value', 61)
                ->assertJsonPath('privacy.raw_ip_collected', false);

            Http::assertSent(fn (Request $request): bool => str_contains((string) data_get($request->data(), 'query'), 'query ShopifyLiveViewReports')
                && str_contains((string) data_get($request->data(), 'variables.sales'), 'SINCE 2026-09-02 UNTIL 2026-09-02'));
        } finally {
            Carbon::setTestNow();
            Cache::flush();
        }
    }

    public function test_live_view_does_not_present_unsynchronized_local_orders_as_shopify_totals(): void
    {
        Cache::flush();
        [$admin, $organization, $store] = $this->context('organization-admin');
        $this->order($organization, $store, 'local-only', now()->subMinute(), [
            'net_sales' => 999,
            'total_price' => 1099,
        ]);
        Http::fake();

        $this->actingAs($admin)->withSession($this->contextSession($organization, $store))
            ->get(route('analytics.live'))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Analytics/Live')
            ->where('snapshot.schema', 'live-view-v2')
            ->where('snapshot.metrics.total_sales.available', false)
            ->where('snapshot.metrics.total_sales.value', 0)
            ->where('snapshot.metrics.orders.available', false)
            ->where('snapshot.metrics.orders.value', 0)
            ->where('snapshot.metrics.current_visitors.available', false)
            ->where('snapshot.traffic.status', 'not_received')
            ->where('snapshot.traffic.reason_code', 'web_pixel_no_events')
            ->where('snapshot.shopify.available', false)
            ->where('snapshot.insights.sales_by_product.available', false));

        Cache::flush();
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

    public function test_sales_marks_new_shopify_period_as_pending_for_automatic_refresh(): void
    {
        Cache::flush();
        Queue::fake();
        [, , $store] = $this->context('organization-admin');
        ShopifyConnection::query()->create([
            'store_id' => $store->id,
            'shop_domain' => $store->shopify_domain,
            'access_token_encrypted' => 'token',
            'token_type' => 'offline',
            'scopes' => ['read_orders', 'read_reports'],
            'api_version' => '2026-07',
            'status' => 'connected',
        ]);

        $result = app(AnalyticsQueryService::class)->sales($store->fresh('shopifyConnection'), [
            'date_from' => '2026-03-04',
            'date_to' => '2026-03-20',
            'comparison' => 'custom',
            'comparison_date_from' => '2025-09-01',
            'comparison_date_to' => '2025-09-30',
        ]);

        $this->assertTrue($result['data_source']['pending']);
        $this->assertFalse($result['data_source']['comparison_pending']);
        $this->assertSame('Shopify 统计报表正在准备，页面会自动刷新。', $result['data_source']['notice']);
        $this->assertDatabaseHas('analytics_snapshots', [
            'store_id' => $store->id,
            'report_key' => 'catalog:core-sales-timeseries',
            'period_from' => '2026-03-04 00:00:00',
            'period_to' => '2026-03-20 00:00:00',
            'source' => 'pending',
        ]);
        Queue::assertPushed(RefreshShopifyAnalyticsSnapshot::class);
    }

    public function test_sales_marks_new_custom_comparison_as_pending_for_automatic_refresh(): void
    {
        Cache::flush();
        Queue::fake();
        [, $organization, $store] = $this->context('organization-admin');
        ShopifyConnection::query()->create([
            'store_id' => $store->id,
            'shop_domain' => $store->shopify_domain,
            'access_token_encrypted' => 'token',
            'token_type' => 'offline',
            'scopes' => ['read_orders', 'read_reports'],
            'api_version' => '2026-07',
            'status' => 'connected',
        ]);
        AnalyticsSnapshot::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'report_key' => 'catalog:core-sales-timeseries',
            'period_from' => '2026-03-04',
            'period_to' => '2026-03-20',
            'timezone' => 'UTC',
            'source' => 'shopifyql',
            'schema_version' => 3,
            'payload' => [
                'scope_granted' => true,
                'available' => true,
                'source' => 'shopifyql',
                'rows' => [['day' => '2026-03-04', 'total_sales__totals' => '100', 'orders__totals' => '1']],
                'error' => null,
            ],
            'fetched_at' => now(),
            'expires_at' => now()->addMinutes(15),
        ]);

        $result = app(AnalyticsQueryService::class)->sales($store->fresh('shopifyConnection'), [
            'date_from' => '2026-03-04',
            'date_to' => '2026-03-20',
            'comparison' => 'custom',
            'comparison_date_from' => '2025-09-01',
            'comparison_date_to' => '2025-09-30',
        ]);

        $this->assertFalse($result['data_source']['pending']);
        $this->assertTrue($result['data_source']['comparison_pending']);
        $this->assertDatabaseHas('analytics_snapshots', [
            'store_id' => $store->id,
            'report_key' => 'catalog:core-sales-timeseries',
            'period_from' => '2025-09-01 00:00:00',
            'period_to' => '2025-09-30 00:00:00',
            'source' => 'pending',
        ]);
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
