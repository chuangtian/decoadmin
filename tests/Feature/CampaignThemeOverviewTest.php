<?php

namespace Tests\Feature;

use App\Jobs\SyncFeishuCampaignActivitiesForStore;
use App\Models\AuditLog;
use App\Models\CampaignActivity;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\CampaignThemeOverviewService;
use App\Services\CampaignThemeRefreshService;
use App\Services\Feishu\CampaignActivitySyncService;
use App\Services\Shopify\Analytics\ShopifyAnalyticsReportService;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use InvalidArgumentException;
use Tests\TestCase;

class CampaignThemeOverviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_overview_uses_completed_campaigns_from_the_current_store_only(): void
    {
        [$user, $organization, $store] = $this->context('viewer');
        $otherStore = $organization->stores()->create([
            'name' => 'Other Store',
            'shopify_domain' => 'other-campaign-theme.myshopify.com',
            'status' => 'active',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);

        $this->campaign($organization, $store, 'visible-one', 100, 20, 10, 0.01);
        $this->campaign($organization, $store, 'visible-two', 300, 80, 20, 0.03);
        $this->campaign($organization, $store, 'unfinished', 0, 999, 999, 0.99);
        $this->campaign($organization, $otherStore, 'hidden-store', 9000, 1, 9000, 0.5);

        $otherOrganization = Organization::query()->create(['name' => 'Other Organization', 'code' => 'other-campaigns']);
        $otherOrganizationStore = $otherOrganization->stores()->create([
            'name' => 'Other Organization Store',
            'shopify_domain' => 'other-organization-campaign-theme.myshopify.com',
            'status' => 'active',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);
        $this->campaign($otherOrganization, $otherOrganizationStore, 'hidden-organization', 5000, 1, 5000, 0.4);

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->get(route('campaign-themes.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('CampaignThemes/Index')
                ->where('store.id', $store->id)
                ->where('store.currency', 'USD')
                ->where('overview.schema', 'campaign-theme-overview-v1')
                ->where('overview.metrics.total_gmv', 400)
                ->where('overview.metrics.total_ad_spend', 100)
                ->where('overview.metrics.total_orders', 30)
                ->where('overview.metrics.overall_roi', 4)
                ->where('overview.metrics.average_cvr_percent', 2)
                ->where('overview.coverage.completed_campaigns', 2)
                ->where('overview.coverage.missing_conversion_rate', 0)
                ->where('refreshStatus.can_run', false));
    }

    public function test_average_cvr_uses_every_completed_campaign_as_the_denominator(): void
    {
        [$user, $organization, $store] = $this->context('operator');

        $this->campaign($organization, $store, 'with-cvr', 100, 0, 1, 0.03);
        $this->campaign($organization, $store, 'missing-cvr', 100, 0, 1, null);

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->get(route('campaign-themes.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('overview.metrics.overall_roi', 0)
                ->where('overview.metrics.average_cvr_percent', 1.5)
                ->where('overview.coverage.completed_campaigns', 2)
                ->where('overview.coverage.missing_conversion_rate', 1));
    }

    public function test_timeline_is_store_scoped_date_ordered_and_uses_store_local_statuses(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-22 12:00:00', 'America/Los_Angeles'));

        try {
            [$user, $organization, $store] = $this->context('viewer');
            $otherStore = $organization->stores()->create([
                'name' => 'Other Store',
                'shopify_domain' => 'other-timeline.myshopify.com',
                'status' => 'active',
            ]);

            $this->campaign($organization, $store, 'past', 100, 20, 1, 0.01, '2026-07-01', '2026-07-10', 4.444);
            $this->campaign($organization, $store, 'current', 0, 0, 0, null, '2026-08-20', '2026-08-25', null);
            $this->campaign($organization, $store, 'future', 300, 50, 2, 0.02, '2026-09-01', '2026-09-03', 5.678);
            $this->campaign($organization, $otherStore, 'hidden', 999, 1, 9, 0.9, '2026-10-01', '2026-10-02', 99);

            $this->actingAs($user)
                ->withSession($this->contextSession($organization, $store))
                ->get(route('campaign-themes.index'))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->has('overview.timeline', 3)
                    ->where('overview.timeline.0.name', 'future')
                    ->where('overview.timeline.0.starts_on', '2026-09-01')
                    ->where('overview.timeline.0.month', '2026-09')
                    ->where('overview.timeline.0.status', 'upcoming')
                    ->where('overview.timeline.0.duration_days', 3)
                    ->where('overview.timeline.0.gmv', 300)
                    ->where('overview.timeline.0.roi', 5.68)
                    ->where('overview.timeline.1.status', 'in_progress')
                    ->where('overview.timeline.1.duration_days', 6)
                    ->where('overview.timeline.2.status', 'completed')
                    ->where('overview.timeline.2.duration_days', 10));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_trends_are_store_scoped_chronological_and_use_synced_daily_metrics(): void
    {
        [$user, $organization, $store] = $this->context('viewer');
        $otherStore = $organization->stores()->create([
            'name' => 'Other Trend Store',
            'shopify_domain' => 'other-campaign-trends.myshopify.com',
            'status' => 'active',
        ]);

        $this->campaign($organization, $store, 'later', 200, 40, 2, 0.00301, '2026-02-10', '2026-02-12', 4.567, 12345.678, 9876.543);
        $this->campaign($organization, $store, 'earlier', 100, 25, 1, 0.00427, '2026-02-01', '2026-02-03', 8.865, 35349.007, 7846.167);
        $this->campaign($organization, $store, 'no-gmv', 0, 0, 0, 0.9, '2026-02-20', '2026-02-21', 99, 999999, 999999);
        $this->campaign($organization, $otherStore, 'hidden', 999, 1, 9, 0.9, '2026-01-01', '2026-01-02', 99, 999999, 999999);

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->get(route('campaign-themes.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('overview.trends', 2)
                ->where('overview.trends.0.name', 'later')
                ->where('overview.trends.0.starts_on', '2026-02-10')
                ->where('overview.trends.0.roi', 4.57)
                ->where('overview.trends.0.conversion_rate_percent', 0.301)
                ->where('overview.trends.0.daily_average_sales', 12345.68)
                ->where('overview.trends.0.daily_average_store_visits', 9876.54)
                ->where('overview.trends.1.name', 'earlier')
                ->where('overview.trends.1.conversion_rate_percent', 0.427));
    }

    public function test_performance_analysis_is_store_scoped_date_descending_and_uses_confirmed_thresholds(): void
    {
        [$user, $organization, $store] = $this->context('viewer');
        $otherStore = $organization->stores()->create([
            'name' => 'Other Performance Store',
            'shopify_domain' => 'other-campaign-performance.myshopify.com',
            'status' => 'active',
        ]);

        $this->campaign($organization, $store, 'reusable-latest', 700000, 99000, 100, 0.00332, '2026-05-18', '2026-05-24', 7.09, null, null, 178364);
        $this->campaign($organization, $store, 'scalable-middle', 563000, 100000, 80, 0.00298, '2026-04-16', '2026-04-25', 5.61, null, null, 177897);
        $this->campaign($organization, $store, 'underperforming-earliest', 1310000, 265000, 120, 0.00270, '2026-03-01', '2026-03-12', 4.94, null, null, 451439);
        $this->campaign($organization, $store, 'unfinished', 0, 1, 1, 0.5, '2026-07-01', '2026-07-02', 99, null, null, 999999);
        $this->campaign($organization, $otherStore, 'hidden', 999999, 1, 1, 0.9, '2026-08-01', '2026-08-02', 99, null, null, 999999);

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->get(route('campaign-themes.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('overview.performance_rules.break_even_roi', 5)
                ->where('overview.performance_rules.reusable_roi', 6)
                ->has('overview.performance', 3)
                ->where('overview.performance.0.name', 'reusable-latest')
                ->where('overview.performance.0.starts_on', '2026-05-18')
                ->where('overview.performance.0.ends_on', '2026-05-24')
                ->where('overview.performance.0.gmv', 700000)
                ->where('overview.performance.0.ad_spend', 99000)
                ->where('overview.performance.0.roi', 7.09)
                ->where('overview.performance.0.conversion_rate_percent', 0.332)
                ->where('overview.performance.0.store_visits', 178364)
                ->where('overview.performance.0.judgment', 'reusable')
                ->where('overview.performance.1.name', 'scalable-middle')
                ->where('overview.performance.1.judgment', 'scalable')
                ->where('overview.performance.2.name', 'underperforming-earliest')
                ->where('overview.performance.2.judgment', 'underperforming'));
    }

    public function test_review_uses_store_scoped_date_descending_activities_and_stable_shopify_reports(): void
    {
        [$user, $organization, $store] = $this->context('viewer');
        $otherStore = $organization->stores()->create([
            'name' => 'Other Review Store',
            'shopify_domain' => 'other-review.myshopify.com',
            'status' => 'active',
        ]);

        $previous = $this->campaign($organization, $store, 'previous', 100000, 20000, 100, 0.003, '2026-02-01', '2026-02-05', 5.0, 20000, 9000, 45000);
        $current = $this->campaign($organization, $store, 'current', 212000, 24000, 201, 0.00427, '2026-02-10', '2026-02-15', 8.86, 35333.33, 7846.17, 47077);
        $current->update([
            'campaign_id' => 'MACFOX-2026-010',
            'main_title' => 'Ride Into the Presidents’ Day Sale',
            'core_offer' => '$100 off Coupon Code',
            'campaign_images' => ['/storage/campaign-current.webp', 'https://example.com/not-exposed.jpg'],
            'email_content' => ['/storage/campaign-email.webp'],
            'daily_average_ad_spend' => 4000,
            'daily_average_order_count' => 33.5,
        ]);
        $hidden = $this->campaign($organization, $otherStore, 'hidden-review', 999999, 1, 1, 0.9, '2026-03-01', '2026-03-02', 99);

        $reports = \Mockery::mock(ShopifyAnalyticsReportService::class);
        $reports->shouldReceive('report')->times(6)->andReturnUsing(
            function (Store $reportedStore, string $report, string $from, string $to) use ($store): array {
                $this->assertTrue($reportedStore->is($store));
                $this->assertContains($report, ['core-sales-timeseries', 'marketing-engagement-spend-timeseries', 'conversion-funnel-breakdown', 'model-sales-summary']);

                if ($report === 'core-sales-timeseries') {
                    return $this->shopifyReport([[
                        'day' => '2026-02-10', 'total_sales' => '27852.15', 'orders' => '26',
                    ], [
                        'day' => '2026-02-15', 'total_sales' => '28322.97', 'orders' => '30',
                    ]]);
                }

                if ($report === 'marketing-engagement-spend-timeseries') {
                    return $this->shopifyReport([[
                        'day' => '2026-02-10', 'engagements_ad_spend' => '1000.00',
                    ], [
                        'day' => '2026-02-15', 'engagements_ad_spend' => '5000.00',
                    ]]);
                }

                if ($report === 'conversion-funnel-breakdown') {
                    return $from === '2026-02-10'
                        ? $this->shopifyReport([[
                            'sessions' => '47077',
                            'sessions_with_cart_additions' => '7637',
                            'sessions_that_reached_checkout' => '1239',
                            'sessions_that_completed_checkout' => '201',
                        ]])
                        : $this->shopifyReport([[
                            'sessions' => '30000',
                            'sessions_with_cart_additions' => '3000',
                            'sessions_that_reached_checkout' => '900',
                            'sessions_that_completed_checkout' => '120',
                        ]]);
                }

                return $from === '2026-02-10'
                    ? $this->shopifyReport([[
                        'product_title' => 'Macfox X1S', 'net_items_sold' => '5', 'total_sales' => '5000',
                    ], [
                        'product_title' => 'Macfox X1S', 'net_items_sold' => '3', 'total_sales' => '3000',
                    ], [
                        'product_title' => 'Macfox X7', 'net_items_sold' => '2', 'total_sales' => '2500',
                    ], [
                        'product_title' => 'Price Difference', 'net_items_sold' => '1080', 'total_sales' => '1080',
                    ], [
                        'product_title' => 'Shipping Protection', 'net_items_sold' => '110', 'total_sales' => '1100',
                    ], [
                        'product_title' => 'Macfox E-bike X1S Battery & Dual Battery Upgrade Kit', 'net_items_sold' => '12', 'total_sales' => '1200',
                    ], [
                        'product_title' => 'Macfox E-bike M16', 'net_items_sold' => '0', 'total_sales' => '0',
                    ]])
                    : $this->shopifyReport([[
                        'product_title' => 'Macfox X1S', 'net_items_sold' => '4', 'total_sales' => '4000',
                    ]]);
            },
        );
        app()->instance(ShopifyAnalyticsReportService::class, $reports);

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->get(route('campaign-themes.index', ['tab' => 'review']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('activeTab', 'review')
                ->where('review.schema', 'campaign-theme-review-v1')
                ->has('review.activities', 2)
                ->where('review.activities.0.id', $current->id)
                ->where('review.activities.1.id', $previous->id)
                ->where('review.selected_activity_id', $current->id)
                ->where('review.comparison_activity_id', $previous->id)
                ->where('review.activity.campaign_id', 'MACFOX-2026-010')
                ->where('review.activity.campaign_images', ['/storage/campaign-current.webp'])
                ->where('review.activity.metrics.average_order_value.value', 1054.73)
                ->where('review.activity.metrics.orders.value', 201)
                ->where('review.activity.metrics.daily_average_sessions.value', 7846.17)
                ->where('review.activity.metrics.daily_average_sessions.comparison_value', 6000)
                ->where('review.activity.metrics.daily_average_sessions.change_percent', 30.8)
                ->where('review.activity.metrics.daily_average_site_views.value', 14123.11)
                ->where('review.activity.metrics.daily_average_site_views.comparison_value', 10800)
                ->where('review.activity.metrics.daily_average_site_views.change_percent', 30.8)
                ->where('review.activity.metrics.daily_average_cart_addition_cost.value', 3.14)
                ->where('review.activity.metrics.daily_average_cart_addition_cost.comparison_value', 6.67)
                ->where('review.activity.metrics.daily_average_checkout_cost.value', 19.37)
                ->where('review.activity.metrics.daily_average_checkout_cost.comparison_value', 22.22)
                ->missing('review.activity.metrics.daily_average_orders')
                ->missing('review.activity.metrics.daily_average_store_visits')
                ->where('review.daily_sales.date_order', 'descending')
                ->where('review.daily_sales.date_from', '2026-02-10')
                ->where('review.daily_sales.date_to', '2026-02-15')
                ->where('review.daily_sales.ad_spend_available', true)
                ->where('review.daily_sales.ad_spend_reconciled', true)
                ->where('review.daily_sales.ad_spend_coverage_percent', 25)
                ->where('review.daily_sales.reported_ad_spend_total', 6000)
                ->where('review.daily_sales.campaign_ad_spend_total', 24000)
                ->has('review.daily_sales.points', 6)
                ->where('review.daily_sales.points.0.date', '2026-02-15')
                ->where('review.daily_sales.points.0.ad_spend', 20000)
                ->where('review.daily_sales.points.0.roi', 1.42)
                ->where('review.daily_sales.points.4.date', '2026-02-11')
                ->where('review.daily_sales.points.4.total_sales', 0)
                ->where('review.daily_sales.points.4.ad_spend', 0)
                ->where('review.daily_sales.points.4.roi', null)
                ->where('review.daily_sales.points.5.date', '2026-02-10')
                ->where('review.daily_sales.points.5.ad_spend', 4000)
                ->where('review.daily_sales.points.5.roi', 6.96)
                ->where('review.funnel.stages.1.rate_percent', 16.22)
                ->where('review.funnel.stages.3.sessions', 201)
                ->where('review.model_sales.total_units', 10)
                ->has('review.model_sales.models', 2)
                ->where('review.model_sales.models.0.name', 'Macfox X1S')
                ->where('review.model_sales.models.0.units', 8)
                ->where('review.model_sales.models.0.share_percent', 80));

        $this->assertNotSame($hidden->id, $current->id);
    }

    public function test_authorized_user_can_queue_only_one_manual_feishu_refresh(): void
    {
        Queue::fake();
        [$user, $organization, $store] = $this->context('organization-admin');

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->from(route('campaign-themes.index'))
            ->post(route('campaign-themes.refresh'))
            ->assertRedirect(route('campaign-themes.index'))
            ->assertSessionHas('success');

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->from(route('campaign-themes.index'))
            ->post(route('campaign-themes.refresh'))
            ->assertRedirect(route('campaign-themes.index'))
            ->assertSessionHas('info');

        Queue::assertPushed(
            SyncFeishuCampaignActivitiesForStore::class,
            fn (SyncFeishuCampaignActivitiesForStore $job): bool => $job->storeId === $store->id
                && $job->actorId === $user->id,
        );
        Queue::assertPushed(SyncFeishuCampaignActivitiesForStore::class, 1);
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'user_id' => $user->id,
            'action' => 'campaign_activity_sync_queued',
        ]);
    }

    public function test_manual_feishu_refresh_job_updates_status_and_audit(): void
    {
        [$user, $organization, $store] = $this->context('organization-admin');
        $sync = \Mockery::mock(CampaignActivitySyncService::class);
        $sync->shouldReceive('syncStore')
            ->once()
            ->withArgs(fn (Store $syncedStore): bool => $syncedStore->is($store))
            ->andReturn(['inserted' => 1, 'updated' => 11, 'skipped' => 0, 'records' => 12]);

        $refresh = app(CampaignThemeRefreshService::class);
        (new SyncFeishuCampaignActivitiesForStore($store->id, $user->id))->handle($sync, $refresh);

        $status = $refresh->status($store);
        $this->assertSame('completed', $status['status']);
        $this->assertSame('更新完成：新增 1，更新 11，跳过 0。', $status['message']);
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'user_id' => $user->id,
            'action' => 'campaign_activity_sync_completed',
        ]);
    }

    public function test_viewer_cannot_queue_manual_feishu_refresh(): void
    {
        Queue::fake();
        [$user, $organization, $store] = $this->context('viewer');

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->post(route('campaign-themes.refresh'))
            ->assertForbidden();

        Queue::assertNothingPushed();
        $this->assertSame(0, AuditLog::query()->where('action', 'campaign_activity_sync_queued')->count());
    }

    public function test_user_without_reports_permission_cannot_view_campaign_themes(): void
    {
        [$user, $organization, $store] = $this->context('developer');

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->get(route('campaign-themes.index'))
            ->assertForbidden();
    }

    public function test_overview_service_rejects_a_store_from_another_organization(): void
    {
        $organization = Organization::query()->create(['name' => 'Organization A', 'code' => 'campaign-a']);
        $otherOrganization = Organization::query()->create(['name' => 'Organization B', 'code' => 'campaign-b']);
        $store = $otherOrganization->stores()->create([
            'name' => 'Store B',
            'shopify_domain' => 'campaign-service-mismatch.myshopify.com',
            'status' => 'active',
        ]);

        $this->expectException(InvalidArgumentException::class);

        app(CampaignThemeOverviewService::class)->overview($organization, $store);
    }

    /** @return array{0: User, 1: Organization, 2: Store} */
    private function context(string $roleSlug): array
    {
        $this->seed(PermissionSeeder::class);
        $user = User::factory()->create();
        $organization = Organization::query()->create(['name' => 'DecoMKT', 'code' => 'decomkt-campaigns']);
        $organization->users()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $store = $organization->stores()->create([
            'name' => 'Macfox US',
            'shopify_domain' => 'macfox-campaign-theme.myshopify.com',
            'status' => 'active',
            'currency' => 'USD',
            'timezone' => 'America/Los_Angeles',
        ]);
        $store->members()->attach($user, ['status' => 'active', 'joined_at' => now()]);

        $this->seed(RoleSeeder::class);
        $role = Role::query()->whereBelongsTo($organization)->where('slug', $roleSlug)->firstOrFail();
        $user->roles()->attach($role, ['organization_id' => $organization->id, 'store_id' => null]);

        return [$user, $organization, $store];
    }

    private function campaign(
        Organization $organization,
        Store $store,
        string $recordId,
        float $gmv,
        float $adSpend,
        int $orders,
        ?float $conversionRate,
        ?string $startsOn = null,
        ?string $endsOn = null,
        ?float $roi = null,
        ?float $dailyAverageSales = null,
        ?float $dailyAverageStoreVisits = null,
        ?int $storeVisits = null,
    ): CampaignActivity {
        return CampaignActivity::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'source_record_id' => $recordId,
            'campaign_name' => $recordId,
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'sales_amount' => $gmv,
            'ad_spend' => $adSpend,
            'roi' => $roi,
            'order_count' => $orders,
            'conversion_rate' => $conversionRate,
            'store_visits' => $storeVisits,
            'daily_average_sales' => $dailyAverageSales,
            'daily_average_store_visits' => $dailyAverageStoreVisits,
            'synced_at' => now(),
        ]);
    }

    /** @return array{current_organization_id: int, current_store_id: int} */
    private function contextSession(Organization $organization, Store $store): array
    {
        return [
            'current_organization_id' => $organization->id,
            'current_store_id' => $store->id,
        ];
    }

    /** @param list<array<string, mixed>> $rows @return array<string, mixed> */
    private function shopifyReport(array $rows): array
    {
        return [
            'scope_granted' => true,
            'available' => true,
            'source' => 'shopifyql',
            'rows' => $rows,
            'error' => null,
            'storage' => ['pending' => false, 'stale' => false],
        ];
    }
}
