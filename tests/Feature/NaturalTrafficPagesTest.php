<?php

namespace Tests\Feature;

use App\Jobs\SyncSeoAnalyticsForStore;
use App\Jobs\SyncSeoAnalyticsShardForStore;
use App\Models\FeishuBitableRecord;
use App\Models\FeishuBitableTable;
use App\Models\Organization;
use App\Models\Role;
use App\Models\SeoAnalyticsSyncRun;
use App\Models\SeoGa4ChannelDailyMetric;
use App\Models\SeoGa4LandingPageDailyMetric;
use App\Models\SeoGoalWorkRecord;
use App\Models\SeoGscBreakdownDailyMetric;
use App\Models\SeoGscDailyMetric;
use App\Models\SeoGscPage;
use App\Models\SeoGscPageDailyMetric;
use App\Models\SeoGscQuery;
use App\Models\SeoGscQueryDailyMetric;
use App\Models\SeoGscSearchTypeDailyMetric;
use App\Models\Store;
use App\Models\StoreBusinessCredential;
use App\Models\User;
use App\Services\SeoAnalytics\GoogleSeoApiClient;
use App\Services\SeoAnalytics\GscDetailPruneService;
use App\Services\SeoAnalytics\GscDimensionBackfillService;
use App\Services\SeoAnalytics\GscMetricQueryService;
use App\Services\SeoAnalytics\SeoAnalyticsCacheVersionService;
use App\Services\SeoAnalytics\SeoAnalyticsSyncManager;
use App\Services\SeoAnalytics\SeoAnalyticsSyncService;
use App\Services\SeoAnalytics\SeoOverviewDashboardService;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class NaturalTrafficPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_with_reports_permission_can_open_each_natural_traffic_page(): void
    {
        [$user, $organization, $store] = $this->context('operator');

        $components = [
            'natural-traffic.seo-geo' => 'NaturalTraffic/SeoGeo',
            'natural-traffic.brand-media' => 'NaturalTraffic/BrandMedia',
            'natural-traffic.influencer-operations' => 'NaturalTraffic/InfluencerOperations',
            'natural-traffic.edm-email' => 'NaturalTraffic/EdmEmail',
            'natural-traffic.affiliate-marketing' => 'NaturalTraffic/AffiliateMarketing',
        ];

        foreach ($this->pages() as $routeName => $title) {
            $response = $this->actingAs($user)
                ->withSession($this->contextSession($organization, $store))
                ->get(route($routeName))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component($components[$routeName]));

            if ($routeName !== 'natural-traffic.seo-geo') {
                $response->assertInertia(fn (Assert $page) => $page->where('dashboard.title', $title));
            }
        }
    }

    public function test_seo_goal_dashboard_matches_database_records_and_calculation_rules(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-24 12:15:05', 'Asia/Shanghai'));
        [$user, $organization, $store] = $this->context('operator');
        $table = FeishuBitableTable::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'source_section' => 'seo-goal',
            'source_table_id' => 'daily-table',
            'name' => '日数据',
            'metadata_encrypted' => [],
            'synced_at' => now(),
        ]);
        FeishuBitableRecord::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'feishu_bitable_table_id' => $table->id,
            'source_record_id' => '2026-08-21',
            'fields_encrypted' => [
                '日期' => '2026-08-21',
                'SEOGMV' => 193514.97,
                '点击-行业词' => 1460,
                '点击-博客' => 7440,
            ],
            'synced_at' => now(),
        ]);

        $this->workRecord($organization, $store, 'new_blog', 1, ['actual_date' => '2026-08-10', 'url_present' => true]);
        foreach (range(1, 4) as $row) {
            $this->workRecord($organization, $store, 'old_blog', $row, ['actual_date' => "2026-08-{$row}", 'url_present' => true]);
        }
        foreach (range(1, 13) as $row) {
            $this->workRecord($organization, $store, 'backlinks', $row, ['cooperation_month' => '2026-08', 'cooperation_month_number' => 8]);
        }
        foreach (range(1, 35) as $row) {
            $this->workRecord($organization, $store, 'ai_automation', $row, ['name' => "flow-{$row}", 'included' => true]);
        }

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->get(route('natural-traffic.seo-geo', ['month' => '2026-08']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('NaturalTraffic/SeoGeo')
                ->where('dashboard.month', '2026-08')
                ->where('dashboard.target_origin', 'default')
                ->where('dashboard.data_through', '2026-08-21')
                ->where('dashboard.summary.expected_count', 3)
                ->where('dashboard.summary.catch_up_count', 4)
                ->where('dashboard.summary.overall', 'catch_up')
                ->where('dashboard.metrics.0.current', 193514.97)
                ->where('dashboard.metrics.0.target', 268483.61)
                ->where('dashboard.metrics.0.status', 'achievable')
                ->where('dashboard.metrics.1.current', 1460)
                ->where('dashboard.metrics.2.current', 7440)
                ->where('dashboard.metrics.3.current', 1)
                ->where('dashboard.metrics.4.current', 4)
                ->where('dashboard.metrics.5.current', 13)
                ->where('dashboard.metrics.5.status', 'leading')
                ->where('dashboard.metrics.6.current', 35)
                ->where('dashboard.metrics.6.status', 'leading'));
    }

    public function test_user_without_reports_permission_cannot_open_natural_traffic_pages(): void
    {
        [$user, $organization, $store] = $this->context('marketing');

        foreach (array_keys($this->pages()) as $routeName) {
            $this->actingAs($user)
                ->withSession($this->contextSession($organization, $store))
                ->get(route($routeName))
                ->assertForbidden();
        }
    }

    public function test_reports_only_user_cannot_run_natural_traffic_sync(): void
    {
        [$user, $organization, $store] = $this->context('operator');
        $session = $this->contextSession($organization, $store);

        foreach (['brand-media', 'influencer-operations', 'edm-email', 'affiliate-marketing'] as $channel) {
            $this->actingAs($user)->withSession($session)
                ->post(route("natural-traffic.{$channel}.refresh"))
                ->assertForbidden();
        }
    }

    public function test_non_ai_dashboards_read_only_current_store_database_and_apply_real_previous_periods(): void
    {
        [$user, $organization, $store] = $this->context('operator');
        $otherStore = $organization->stores()->create([
            'name' => 'Other Natural Store', 'shopify_domain' => 'other-natural-dashboard.myshopify.com', 'status' => 'active',
        ]);

        $this->archiveRecord($organization, $store, 'natural-traffic:social', '官媒周数据', 'social-current', [
            '发布日期' => '2026-08-18', '平台' => 'Instagram', '帖子数' => 2, '浏览量' => 1000, '点赞' => 80, '评论数' => 10, '分享数' => 5,
        ]);
        $this->archiveRecord($organization, $store, 'natural-traffic:social', '官媒周数据', 'social-previous', [
            '发布日期' => '2026-08-17', '平台' => 'Instagram', '帖子数' => 1, '浏览量' => 500, '点赞' => 30, '评论数' => 5, '分享数' => 2,
        ]);
        $this->archiveRecord($organization, $otherStore, 'natural-traffic:social', '官媒周数据', 'social-other', [
            '发布日期' => '2026-08-18', '平台' => 'Facebook', '帖子数' => 99, '浏览量' => 99999,
        ]);

        $this->actingAs($user)->withSession($this->contextSession($organization, $store))
            ->get(route('natural-traffic.brand-media', ['date_from' => '2026-08-18', 'date_to' => '2026-08-18']))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('NaturalTraffic/BrandMedia')
            ->where('dashboard.source.storage', 'current-project-mysql')
            ->where('dashboard.source.record_count', 2)
            ->where('dashboard.kpis.0.value', 2)
            ->where('dashboard.kpis.1.value', 1000)
            ->where('dashboard.kpis.1.previous', 500)
            ->where('dashboard.kpis.1.change', 100)
            ->where('dashboard.funnel.0.value', 1000)
            ->where('dashboard.weekly_reports.0.included_posts', 2)
            ->where('dashboard.platform_coverage.missing.0', 'Facebook')
            ->has('dashboard.tabs', 3));

        $this->archiveRecord($organization, $store, 'natural-traffic:kol', '红人数据', 'kol-current', [
            '发布日期' => '2026-08-18', '红人title' => 'creator_a', '平台' => ['IG'], '浏览' => 20000, '赞' => 1000, '评' => 60, '互动率' => 0.053, 'clicks' => 120,
        ]);
        $this->archiveRecord($organization, $store, 'natural-traffic:kol', '红人数据', 'kol-previous', [
            '发布日期' => '2026-08-17', '红人title' => 'creator_a', '平台' => ['IG'], '浏览' => 10000, '互动率' => 0.04, 'clicks' => 50,
        ]);
        $this->actingAs($user)->withSession($this->contextSession($organization, $store))
            ->get(route('natural-traffic.influencer-operations', ['date_from' => '2026-08-18', 'date_to' => '2026-08-18']))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('NaturalTraffic/InfluencerOperations')
            ->where('dashboard.kpis.0.value', 1)
            ->where('dashboard.kpis.2.value', 20000)
            ->where('dashboard.kpis.4.value', 5.3)
            ->has('dashboard.scatter', 1)
            ->has('dashboard.tabs', 2)
            ->missing('dashboard.ai'));

        $this->archiveRecord($organization, $store, 'natural-traffic:sequence', '序列表现', 'edm-current', [
            'Name' => 'Welcome Flow', '开始日期' => '2026-08-18', '周' => 34, 'Email Revenue' => 3000, 'Open Rate' => 0.46, 'CTR' => 0.033, 'CVR' => 0.016, 'Unsubscribe Rate' => 0.0106,
        ]);
        $this->archiveRecord($organization, $store, 'natural-traffic:sequence', '序列表现', 'edm-previous', [
            'Name' => 'Welcome Flow', '开始日期' => '2026-08-17', '周' => 33, 'Email Revenue' => 2000, 'Open Rate' => 0.40, 'CTR' => 0.02,
        ]);
        $this->archiveRecord($organization, $store, 'natural-traffic:edm', '目标看板', 'edm-target', ['指标' => 'Revenue', '目标值' => 50000]);
        $this->archiveRecord($organization, $store, 'natural-traffic:edm', '订阅目标', 'edm-subscription-target', [
            '当月用户订阅数' => 120, '当月用户总数' => 1000, '目标' => '15%',
        ]);
        $this->actingAs($user)->withSession($this->contextSession($organization, $store))
            ->get(route('natural-traffic.edm-email', ['date_from' => '2026-08-18', 'date_to' => '2026-08-18']))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('NaturalTraffic/EdmEmail')
            ->where('dashboard.kpis.0.value', 3000)
            ->where('dashboard.kpis.1.value', 46)
            ->where('dashboard.kpis.2.value', 3.3)
            ->where('dashboard.top_flows.0.revenue_share', 100)
            ->where('dashboard.target_progress.0.actual', 12)
            ->where('dashboard.target_progress.0.progress', 80)
            ->where('dashboard.target_progress.0.status', 'attention')
            ->has('dashboard.tabs', 4)
            ->has('dashboard.target_sheets', 2));

        $this->archiveRecord($organization, $store, 'natural-traffic:affiliate', '联盟周数据', 'affiliate-current', [
            'Name' => 'Partner A', '开始日期' => '2026-08-18', '周' => ['34'], '点击' => 100, 'GMV' => 1000,
        ]);
        $this->archiveRecord($organization, $store, 'natural-traffic:affiliate', '联盟周数据', 'affiliate-previous', [
            'Name' => 'Partner A', '开始日期' => '2026-08-17', '周' => ['33'], '点击' => 50, 'GMV' => 400,
        ]);
        $this->actingAs($user)->withSession($this->contextSession($organization, $store))
            ->get(route('natural-traffic.affiliate-marketing', ['date_from' => '2026-08-18', 'date_to' => '2026-08-18']))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('NaturalTraffic/AffiliateMarketing')
            ->where('dashboard.kpis.0.value', 1000)
            ->where('dashboard.kpis.0.previous', 400)
            ->where('dashboard.kpis.1.value', 100)
            ->where('dashboard.kpis.2.value', 10));
    }

    public function test_non_ai_dashboards_keep_source_semantics_for_outliers_links_and_missing_fields(): void
    {
        [$user, $organization, $store] = $this->context('operator');
        $session = $this->contextSession($organization, $store);

        $this->archiveRecord($organization, $store, 'natural-traffic:social', '官媒内容', 'regular-post', [
            '发布日期' => '2026-08-18', '平台' => 'Instagram', '描述' => '常规内容', '浏览量' => 10000, '点赞' => 500, '评论数' => 30,
        ]);
        $this->archiveRecord($organization, $store, 'natural-traffic:social', '官媒内容', 'viral-post', [
            '发布日期' => '2026-08-19', '平台' => 'Instagram', '描述' => '爆款内容', '浏览量' => 150000, '点赞' => 6000, '评论数' => 400,
        ]);

        $this->actingAs($user)->withSession($session)
            ->get(route('natural-traffic.brand-media', ['date_from' => '2026-08-18', 'date_to' => '2026-08-24']))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.weekly_reports.0.included_posts', 1)
            ->where('dashboard.weekly_reports.0.excluded_posts', 1)
            ->where('dashboard.weekly_reports.0.included_views', 10000)
            ->where('dashboard.weekly_reports.0.included_interactions', 530)
            ->where('dashboard.weekly_reports.0.average_views', 10000)
            ->has('dashboard.weekly_reports.0.excluded_content', 1));

        $this->archiveRecord($organization, $store, 'natural-traffic:kol', '红人数据', 'kol-with-link', [
            '发布日期' => '2026-08-18', '红人title' => 'creator_link', '平台' => ['IG'], '浏览' => 20000,
            '互动率' => 0.05, '合作链接' => ['link' => 'https://example.test/post', 'text' => '合作内容'],
        ]);

        $this->actingAs($user)->withSession($session)
            ->get(route('natural-traffic.influencer-operations', ['date_from' => '2026-08-18', 'date_to' => '2026-08-18']))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('dashboard.kpis', 5)
            ->where('dashboard.details.0.link', 'https://example.test/post')
            ->where('dashboard.resources.0.link', 'https://example.test/post'));
    }

    public function test_brand_media_content_details_filter_paginate_stably_and_remain_store_scoped(): void
    {
        [$user, $organization, $store] = $this->context('operator');
        $session = $this->contextSession($organization, $store);
        $otherStore = $organization->stores()->create([
            'name' => 'Other Brand Store', 'shopify_domain' => 'other-brand.myshopify.com', 'status' => 'active',
        ]);

        foreach (range(1, 25) as $index) {
            $this->archiveRecord($organization, $store, 'natural-traffic:social', '官媒内容', sprintf('post-%02d', $index), [
                '发布日期' => '2026-08-20',
                '平台' => 'Instagram',
                '描述' => sprintf('Needle content %02d', $index),
                '帖子类型' => 'Reels',
                '浏览量' => 1000 + $index,
            ]);
        }
        $this->archiveRecord($organization, $store, 'natural-traffic:social', '官媒内容', 'excluded-post', [
            '发布日期' => '2026-08-20', '平台' => 'Instagram', '描述' => 'Needle excluded', '帖子类型' => 'Reels', '浏览量' => 150000,
        ]);
        $this->archiveRecord($organization, $store, 'natural-traffic:social', '官媒内容', 'facebook-post', [
            '发布日期' => '2026-08-20', '平台' => 'Facebook', '描述' => 'Needle facebook', '帖子类型' => '图片', '观看量' => 800,
        ]);
        $this->archiveRecord($organization, $otherStore, 'natural-traffic:social', '官媒内容', 'other-store-post', [
            '发布日期' => '2026-08-20', '平台' => 'Instagram', '描述' => 'Needle tenant leak', '帖子类型' => 'Reels', '浏览量' => 999999,
        ]);

        $this->actingAs($user)->withSession($session)
            ->get(route('natural-traffic.brand-media', [
                'date_from' => '2026-08-20',
                'date_to' => '2026-08-20',
                'comparison' => 'none',
                'content_keyword' => 'needle',
                'content_platform' => 'instagram',
                'content_type' => 'reels',
                'content_status' => 'included',
                'content_page' => 2,
                'content_per_page' => 10,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('dashboard.post_filters.keyword', 'needle')
                ->where('dashboard.post_filters.platform', 'Instagram')
                ->where('dashboard.post_filters.post_type', 'Reels')
                ->where('dashboard.post_filters.aggregation_status', 'included')
                ->where('dashboard.post_filters.page', 2)
                ->where('dashboard.post_filters.per_page', 10)
                ->where('dashboard.posts_pagination.current_page', 2)
                ->where('dashboard.posts_pagination.last_page', 3)
                ->where('dashboard.posts_pagination.total', 25)
                ->where('dashboard.posts_pagination.from', 11)
                ->where('dashboard.posts_pagination.to', 20)
                ->where('dashboard.posts.0.record_id', 'post-11')
                ->where('dashboard.posts.9.record_id', 'post-20')
                ->has('dashboard.posts', 10)
                ->where('dashboard.kpis.0.value', 26)
                ->where('dashboard.source.record_count', 27)
                ->where('dashboard.post_filter_options.platforms', ['Instagram', 'Facebook', 'YouTube'])
                ->where('dashboard.post_filter_options.aggregation_statuses.1.value', 'excluded'));

        $this->actingAs($user)->withSession($session)
            ->get(route('natural-traffic.brand-media', [
                'date_from' => '2026-08-20', 'date_to' => '2026-08-20',
                'content_platform' => 'TikTok', 'content_status' => 'unknown',
                'content_page' => -50, 'content_per_page' => 999,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('dashboard.post_filters.platform', '')
                ->where('dashboard.post_filters.aggregation_status', '')
                ->where('dashboard.post_filters.page', 1)
                ->where('dashboard.post_filters.per_page', 20)
                ->where('dashboard.posts_pagination.total', 27)
                ->has('dashboard.posts', 20));

        $this->actingAs($user)->withSession($session)
            ->get(route('natural-traffic.brand-media', [
                'date_from' => '2026-08-20', 'date_to' => '2026-08-20',
                'content_page' => 999999, 'content_per_page' => 20,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('dashboard.post_filters.page', 2)
                ->where('dashboard.posts_pagination.current_page', 2)
                ->where('dashboard.posts_pagination.last_page', 2)
                ->has('dashboard.posts', 7));
    }

    public function test_seo_overview_reads_only_local_database_and_calculates_anonymous_queries(): void
    {
        [$user, $organization, $store] = $this->context('operator');
        foreach ([
            ['2026-08-16', 'total', 100, 1000, 4.0], ['2026-08-16', 'brand', 45, 300, 2.0],
            ['2026-08-16', 'industry', 15, 500, 8.0], ['2026-08-16', 'blog', 20, 250, 6.0],
            ['2026-08-15', 'total', 80, 900, 5.0], ['2026-08-15', 'brand', 35, 280, 2.2],
            ['2026-08-15', 'industry', 10, 420, 9.0], ['2026-08-15', 'blog', 15, 220, 7.0],
            ['2025-08-16', 'total', 50, 700, 6.0], ['2025-08-16', 'brand', 20, 200, 2.5],
            ['2025-08-16', 'industry', 10, 350, 10.0], ['2025-08-16', 'blog', 8, 150, 8.0],
        ] as [$date, $segment, $clicks, $impressions, $position]) {
            SeoGscDailyMetric::query()->create([
                'organization_id' => $organization->id, 'store_id' => $store->id, 'metric_date' => $date,
                'segment' => $segment, 'clicks' => $clicks, 'impressions' => $impressions,
                'average_position' => $position, 'synced_at' => now(),
            ]);
        }
        SeoGa4ChannelDailyMetric::query()->create([
            'organization_id' => $organization->id, 'store_id' => $store->id, 'metric_date' => '2026-08-16',
            'channel_group' => 'Organic Search', 'total_revenue' => 7000, 'sessions' => 1200, 'synced_at' => now(),
        ]);
        SeoGa4ChannelDailyMetric::query()->create([
            'organization_id' => $organization->id, 'store_id' => $store->id, 'metric_date' => '2026-08-15',
            'channel_group' => 'Organic Search', 'total_revenue' => 5000, 'sessions' => 1000, 'synced_at' => now(),
        ]);
        SeoGa4ChannelDailyMetric::query()->create([
            'organization_id' => $organization->id, 'store_id' => $store->id, 'metric_date' => '2025-08-16',
            'channel_group' => 'Organic Search', 'total_revenue' => 3500, 'sessions' => 800, 'synced_at' => now(),
        ]);
        $this->gscQuery($organization, $store, '2026-08-16', 'brand', 'macfox', 45, 300, 2);
        $this->gscQuery($organization, $store, '2026-08-16', 'industry', 'ebike', 15, 500, 8);
        $this->gscPage($organization, $store, '2026-08-16', 'total', 'https://example.com/', 100, 1000, 4);

        $direct = app(SeoOverviewDashboardService::class)->forStore($store, ['date_from' => '2026-08-16', 'date_to' => '2026-08-16']);
        $this->assertSame('2026-08-16', $direct['filters']['date_from']);
        $this->assertSame(7000.0, $direct['ga4']['current']['revenue']);
        $yearComparison = app(SeoOverviewDashboardService::class)->forStore($store, [
            'date_from' => '2026-08-16', 'date_to' => '2026-08-16', 'comparison' => 'year',
        ]);
        $this->assertSame('2025-08-16', $yearComparison['comparison_period']['date_from']);
        $this->assertSame(3500.0, $yearComparison['ga4']['previous']['revenue']);
        $this->assertSame(100.0, $yearComparison['ga4']['deltas']['revenue']);
        $this->assertSame(50, $yearComparison['gsc']['total']['previous']['clicks']);
        $this->assertSame(100.0, $yearComparison['gsc']['total']['deltas']['clicks']);

        $this->actingAs($user)->withSession($this->contextSession($organization, $store))
            ->get(route('natural-traffic.seo-geo', ['tab' => 'overview', 'date_from' => '2026-08-16', 'date_to' => '2026-08-16']))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('NaturalTraffic/SeoGeo')
            ->where('activeTab', 'overview')
            ->where('overview.ga4.current.revenue', 7000)
            ->where('overview.ga4.previous.revenue', 5000)
            ->where('overview.gsc.total.current.clicks', 100)
            ->where('overview.gsc.brand.current.clicks', 45)
            ->where('overview.gsc.industry.current.clicks', 15)
            ->where('overview.gsc.anonymous.current.clicks', 40)
            ->where('overview.gsc.anonymous.current.visible_clicks', 60)
            ->where('overview.gsc.anonymous.current.ratio', 40)
            ->has('overview.gsc.total.queries', 0)
            ->has('overview.gsc.total.pages', 0));
    }

    public function test_overview_refresh_only_queues_background_sync_job(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-24 12:00:00', 'America/Los_Angeles'));
        Queue::fake();
        [$user, $organization, $store] = $this->context('organization-admin');
        foreach ([
            'gsc_client_id' => 'client', 'gsc_client_secret' => 'secret', 'gsc_refresh_token' => 'refresh',
            'gsc_site_url' => 'https://example.com/', 'ga4_property_id' => '12345',
            'ga4_service_account_json' => json_encode(['client_email' => 'seo@example.test', 'private_key' => 'private']),
        ] as $key => $value) {
            StoreBusinessCredential::query()->create([
                'organization_id' => $organization->id, 'store_id' => $store->id, 'provider' => 'google_search_console_ga4',
                'credential_key' => $key, 'credential_value' => $value, 'updated_by' => $user->id,
            ]);
        }

        $this->actingAs($user)->withSession($this->contextSession($organization, $store))
            ->post(route('natural-traffic.seo-geo.overview.refresh'))->assertRedirect();

        $run = SeoAnalyticsSyncRun::query()->sole();
        $this->assertSame('queued', $run->status);
        $this->assertSame('2026-01-01', $run->date_from?->toDateString());
        $this->assertSame('2026-08-22', $run->date_to?->toDateString());
        Queue::assertPushed(SyncSeoAnalyticsForStore::class, fn (SyncSeoAnalyticsForStore $job): bool => $job->storeId === $store->id && $job->syncRunId === $run->id);

        app(SeoAnalyticsSyncManager::class)->dispatchShards($store, $run);
        $run->refresh();
        $this->assertSame(9, data_get($run->result, 'total_shards'));
        $this->assertSame(['2026-08-16', '2026-08-22'], data_get($run->result, 'priority_period'));
        Queue::assertPushed(SyncSeoAnalyticsShardForStore::class, 9);
        Queue::assertPushed(SyncSeoAnalyticsShardForStore::class, fn (SyncSeoAnalyticsShardForStore $job): bool => $job->shardIndex === 0
            && $job->phase === 'priority'
            && $job->dateFrom === '2026-08-16'
            && $job->dateTo === '2026-08-22');
        Queue::assertPushed(SyncSeoAnalyticsShardForStore::class, fn (SyncSeoAnalyticsShardForStore $job): bool => $job->shardIndex === 1
            && $job->phase === 'backfill'
            && $job->dateFrom === '2026-08-01'
            && $job->dateTo === '2026-08-15');
    }

    public function test_gsc_and_ga4_source_tabs_read_only_current_store_local_snapshots(): void
    {
        [$user, $organization, $store] = $this->context('operator');
        $otherStore = $organization->stores()->create([
            'name' => 'Other Store', 'shopify_domain' => 'other-natural-traffic.myshopify.com', 'status' => 'active',
        ]);
        foreach ([
            [$store, 'total', 120, 2400], [$store, 'brand', 80, 1000], [$store, 'industry', 40, 1400], [$store, 'blog', 25, 700],
            [$otherStore, 'total', 9999, 99999],
        ] as [$targetStore, $segment, $clicks, $impressions]) {
            SeoGscDailyMetric::query()->create([
                'organization_id' => $organization->id, 'store_id' => $targetStore->id, 'metric_date' => '2026-08-22',
                'segment' => $segment, 'clicks' => $clicks, 'impressions' => $impressions, 'average_position' => 4.5, 'synced_at' => now(),
            ]);
        }
        $this->gscQuery($organization, $store, '2026-08-22', 'brand', 'macfox', 80, 1000, 2);
        $this->gscPage($organization, $store, '2026-08-22', 'total', 'https://example.com/', 120, 2400, 4.5);
        SeoGa4ChannelDailyMetric::query()->create([
            'organization_id' => $organization->id, 'store_id' => $store->id, 'metric_date' => '2026-08-22',
            'channel_group' => 'Organic Search', 'total_revenue' => 4321.5, 'sessions' => 765, 'synced_at' => now(),
        ]);
        SeoGa4ChannelDailyMetric::query()->create([
            'organization_id' => $organization->id, 'store_id' => $otherStore->id, 'metric_date' => '2026-08-22',
            'channel_group' => 'Organic Search', 'total_revenue' => 99999, 'sessions' => 9999, 'synced_at' => now(),
        ]);
        SeoGa4LandingPageDailyMetric::query()->create([
            'organization_id' => $organization->id, 'store_id' => $store->id, 'metric_date' => '2026-08-22',
            'landing_page_hash' => hash('sha256', '/products/x7'), 'landing_page' => '/products/x7', 'channel_group' => 'Organic Search',
            'sessions' => 100, 'active_users' => 80, 'new_users' => 50, 'engagement_duration' => 5000,
            'key_events' => 30, 'total_revenue' => 1234.5, 'bounce_rate' => 0.25, 'session_key_event_rate' => 0.3, 'synced_at' => now(),
        ]);

        $session = $this->contextSession($organization, $store);
        $this->actingAs($user)->withSession($session)
            ->get(route('natural-traffic.seo-geo', ['tab' => 'gsc', 'date_from' => '2026-08-22', 'date_to' => '2026-08-22']))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('NaturalTraffic/SeoGeo')
            ->where('activeTab', 'gsc')
            ->where('overview.schema', 'seo-gsc-source-v1')
            ->where('overview.segment_key', 'total')
            ->where('overview.segment.current.clicks', 120)
            ->where('overview.segment.current.impressions', 2400)
            ->where('overview.segment_totals.brand.clicks', 80)
            ->where('overview.segments.industry.current.clicks', 40)
            ->where('overview.segments.blog.current.clicks', 25)
            ->has('overview.segment.queries', 0)
            ->has('overview.segment.pages', 0)
            ->missing('overview.ga4'));

        $this->actingAs($user)->withSession($session)
            ->get(route('natural-traffic.seo-geo', ['tab' => 'ga4', 'date_from' => '2026-08-22', 'date_to' => '2026-08-22']))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('NaturalTraffic/SeoGeo')
            ->where('activeTab', 'ga4')
            ->where('overview.schema', 'seo-ga4-source-v1')
            ->where('overview.current.revenue', 4321.5)
            ->where('overview.current.sessions', 765)
            ->has('overview.channels', 1)
            ->has('overview.landing_pages', 0)
            ->missing('overview.gsc'));
    }

    public function test_priority_shard_marks_recent_data_ready_before_history_finishes(): void
    {
        [, $organization, $store] = $this->context('organization-admin');
        $run = SeoAnalyticsSyncRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'source' => 'manual',
            'mode' => 'backfill',
            'status' => 'queued',
            'date_from' => '2025-04-01',
            'date_to' => '2026-08-22',
            'result' => ['total_shards' => 18, 'completed_shards' => [], 'priority_ready' => false],
        ]);
        $sync = $this->mock(SeoAnalyticsSyncService::class);
        $sync->shouldReceive('syncRange')->once()->with(
            \Mockery::on(fn (Store $candidate): bool => $candidate->is($store)),
            '2026-08-16',
            '2026-08-22',
        )->andReturn(4321);

        (new SyncSeoAnalyticsShardForStore(
            $organization->id, $store->id, $run->id, 0, 18, 'priority', '2026-08-16', '2026-08-22',
        ))->handle($sync);

        $run->refresh();
        $this->assertSame('running', $run->status);
        $this->assertSame(5, $run->progress_percent);
        $this->assertSame(4321, $run->processed_rows);
        $this->assertTrue((bool) data_get($run->result, 'priority_ready'));
        $this->assertSame([0], data_get($run->result, 'completed_shards'));
    }

    public function test_source_detail_endpoint_paginates_filters_and_compares_local_rows(): void
    {
        [$user, $organization, $store] = $this->context('operator');
        foreach ([['2026-08-22', 15], ['2026-08-15', 10]] as [$date, $clicks]) {
            $this->gscQuery($organization, $store, $date, 'industry', 'electric bike', $clicks, 100, 4);
        }
        SeoGscSearchTypeDailyMetric::query()->create([
            'organization_id' => $organization->id, 'store_id' => $store->id, 'metric_date' => '2026-08-22',
            'search_type' => 'image', 'clicks' => 8, 'impressions' => 400, 'average_position' => 6, 'synced_at' => now(),
        ]);
        SeoGscBreakdownDailyMetric::query()->create([
            'organization_id' => $organization->id, 'store_id' => $store->id, 'metric_date' => '2026-08-22',
            'search_type' => 'web', 'dimension' => 'country', 'value_hash' => hash('sha256', 'usa'), 'value' => 'usa',
            'clicks' => 12, 'impressions' => 300, 'average_position' => 3, 'synced_at' => now(),
        ]);
        SeoGa4LandingPageDailyMetric::query()->create([
            'organization_id' => $organization->id, 'store_id' => $store->id, 'metric_date' => '2026-08-22',
            'landing_page_hash' => hash('sha256', '/products/test'), 'landing_page' => '/products/test', 'channel_group' => 'Organic Search',
            'sessions' => 20, 'active_users' => 15, 'new_users' => 9, 'engagement_duration' => 600,
            'key_events' => 5, 'total_revenue' => 250, 'bounce_rate' => 0.2, 'session_key_event_rate' => 0.25, 'synced_at' => now(),
        ]);

        $session = $this->contextSession($organization, $store);
        $this->actingAs($user)->withSession($session)->getJson(route('natural-traffic.seo-geo.source-details', [
            'source' => 'gsc', 'date_from' => '2026-08-16', 'date_to' => '2026-08-22', 'segment' => 'industry',
            'detail_type' => 'queries', 'search' => 'electric', 'per_page' => 25,
        ]))->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('rows.0.clicks', 15)
            ->assertJsonPath('rows.0.previous.clicks', 10)
            ->assertJsonPath('rows.0.difference.clicks', 5);

        $this->actingAs($user)->withSession($session)->getJson(route('natural-traffic.seo-geo.source-details', [
            'source' => 'gsc', 'date_from' => '2026-08-22', 'date_to' => '2026-08-22', 'search_type' => 'web', 'dimension' => 'country',
        ]))->assertOk()->assertJsonPath('rows.0.label', 'usa');

        $this->actingAs($user)->withSession($session)->getJson(route('natural-traffic.seo-geo.source-details', [
            'source' => 'ga4', 'date_from' => '2026-08-22', 'date_to' => '2026-08-22', 'page_type' => 'product',
        ]))->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('rows.0.page_type', 'product')
            ->assertJsonPath('totals.revenue', 250);
    }

    public function test_blog_page_details_are_derived_from_total_page_rows(): void
    {
        [$user, $organization, $store] = $this->context('operator');
        $blogPage = 'https://example.com/blogs/news/electric-bike-guide';

        $this->gscPage($organization, $store, '2026-08-22', 'total', $blogPage, 15, 300, 4);
        $this->gscPage($organization, $store, '2026-08-15', 'total', $blogPage, 10, 200, 5);
        $this->gscPage($organization, $store, '2026-08-22', 'total', 'https://example.com/products/x1', 99, 999, 1);
        $this->gscPage($organization, $store, '2026-08-22', 'blog', $blogPage, 500, 5000, 2);

        $this->actingAs($user)->withSession($this->contextSession($organization, $store))
            ->getJson(route('natural-traffic.seo-geo.source-details', [
                'source' => 'gsc', 'date_from' => '2026-08-16', 'date_to' => '2026-08-22',
                'segment' => 'blog', 'detail_type' => 'pages',
            ]))->assertOk()
            ->assertJsonPath('type', 'pages')
            ->assertJsonPath('segment', 'blog')
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('rows.0.label', $blogPage)
            ->assertJsonPath('rows.0.clicks', 15)
            ->assertJsonPath('rows.0.previous.clicks', 10)
            ->assertJsonPath('rows.0.difference.clicks', 5);
    }

    public function test_gsc_dimension_backfill_preserves_page_and_query_details(): void
    {
        [$user, $organization, $store] = $this->context('operator');
        $blogPage = 'https://example.com/blogs/news/dimension-guide';

        $this->gscPage($organization, $store, '2026-08-22', 'total', $blogPage, 15, 300, 4);
        $this->gscPage($organization, $store, '2026-08-15', 'total', $blogPage, 10, 200, 5);
        $this->gscQuery($organization, $store, '2026-08-22', 'industry', 'dimension ebike', 8, 120, 6);

        $status = app(GscDimensionBackfillService::class)->backfill($store->id, 1000);

        $this->assertSame(0, $status['pages']['missing_rows']);
        $this->assertSame(0, $status['queries']['missing_rows']);
        $this->assertSame(1, $status['pages']['dimension_rows']);
        $this->assertSame(1, $status['queries']['dimension_rows']);
        $this->assertTrue(SeoGscPage::query()->sole()->is_blog);
        $this->assertSame('dimension ebike', SeoGscQuery::query()->sole()->query);
        $this->assertTrue(app(GscMetricQueryService::class)->dimensionsReady($store, 'pages'));
        $this->assertTrue(app(GscMetricQueryService::class)->dimensionsReady($store, 'queries'));
        $this->assertDatabaseMissing('seo_gsc_page_daily_metrics', ['page_id' => null]);
        $this->assertDatabaseMissing('seo_gsc_query_daily_metrics', ['query_id' => null]);

        $session = $this->contextSession($organization, $store);
        $this->actingAs($user)->withSession($session)->getJson(route('natural-traffic.seo-geo.source-details', [
            'source' => 'gsc', 'date_from' => '2026-08-16', 'date_to' => '2026-08-22',
            'segment' => 'blog', 'detail_type' => 'pages',
        ]))->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('rows.0.label', $blogPage)
            ->assertJsonPath('rows.0.clicks', 15)
            ->assertJsonPath('rows.0.previous.clicks', 10);

        $this->actingAs($user)->withSession($session)->getJson(route('natural-traffic.seo-geo.source-details', [
            'source' => 'gsc', 'date_from' => '2026-08-22', 'date_to' => '2026-08-22',
            'segment' => 'industry', 'detail_type' => 'queries', 'search' => 'dimension',
        ]))->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('rows.0.label', 'dimension ebike')
            ->assertJsonPath('rows.0.clicks', 8);
    }

    public function test_gsc_detail_cache_is_invalidated_by_store_version(): void
    {
        [, $organization, $store] = $this->context('operator');
        $this->gscQuery($organization, $store, '2026-08-22', 'industry', 'cached ebike', 5, 100, 4);
        $filters = [
            'date_from' => '2026-08-22', 'date_to' => '2026-08-22',
            'segment' => 'industry', 'detail_type' => 'queries',
        ];
        $overview = app(SeoOverviewDashboardService::class);

        $this->assertSame(5, data_get($overview->gscSourceDetails($store, $filters), 'rows.0.clicks'));
        SeoGscQueryDailyMetric::query()->update(['clicks' => 9]);
        $this->assertSame(5, data_get($overview->gscSourceDetails($store, $filters), 'rows.0.clicks'));

        app(SeoAnalyticsCacheVersionService::class)->bump((int) $store->id);

        $this->assertSame(9, data_get($overview->gscSourceDetails($store, $filters), 'rows.0.clicks'));
    }

    public function test_gsc_sync_skips_blog_page_request_and_removes_legacy_rows(): void
    {
        [$user, $organization, $store] = $this->context('organization-admin');
        foreach ([
            'gsc_client_id' => 'client', 'gsc_client_secret' => 'secret', 'gsc_refresh_token' => 'refresh',
            'gsc_site_url' => 'https://example.com/', 'ga4_property_id' => '12345',
            'ga4_service_account_json' => json_encode(['client_email' => 'seo@example.test', 'private_key' => 'private']),
        ] as $key => $value) {
            StoreBusinessCredential::query()->create([
                'organization_id' => $organization->id, 'store_id' => $store->id, 'provider' => 'google_search_console_ga4',
                'credential_key' => $key, 'credential_value' => $value, 'updated_by' => $user->id,
            ]);
        }

        $legacyBlogPage = 'https://example.com/blogs/news/legacy';
        $this->gscPage($organization, $store, '2026-08-22', 'blog', $legacyBlogPage, 5, 100, 3);

        $pageRequests = [];
        $google = \Mockery::mock(GoogleSeoApiClient::class);
        $google->shouldReceive('ga4Rows')->twice()->andReturn([]);
        $google->shouldReceive('gscRows')->andReturn([]);
        $google->shouldReceive('gscRowPages')->andReturnUsing(
            function (Store $candidate, string $from, string $to, array $dimensions, array $filters = []) use (&$pageRequests, $legacyBlogPage): \Generator {
                if ($dimensions === ['date', 'page']) {
                    $pageRequests[] = $filters;
                }

                if ($dimensions === ['date', 'page'] && $filters === []) {
                    yield [
                        ['keys' => ['2026-08-22', $legacyBlogPage], 'clicks' => 5, 'impressions' => 100, 'position' => 3],
                        ['keys' => ['2026-08-22', 'https://example.com/low-value'], 'clicks' => 0, 'impressions' => 4, 'position' => 80],
                    ];

                    return;
                }

                if ($dimensions === ['date', 'query'] && ($filters[0]['operator'] ?? null) === 'includingRegex') {
                    yield [
                        ['keys' => ['2026-08-22', 'macfox'], 'clicks' => 3, 'impressions' => 50, 'position' => 2],
                        ['keys' => ['2026-08-22', 'low value query'], 'clicks' => 0, 'impressions' => 4, 'position' => 90],
                    ];

                    return;
                }

                yield from [];
            },
        );
        $this->instance(GoogleSeoApiClient::class, $google);

        app(SeoAnalyticsSyncService::class)->syncRange($store, '2026-08-22', '2026-08-22');

        $this->assertCount(3, $pageRequests);
        $this->assertFalse(collect($pageRequests)->contains(
            fn (array $filters): bool => collect($filters)->contains(
                fn (array $filter): bool => ($filter['dimension'] ?? null) === 'page'
                    && ($filter['operator'] ?? null) === 'contains'
                    && ($filter['expression'] ?? null) === '/blogs/',
            ),
        ));
        $this->assertDatabaseMissing('seo_gsc_page_daily_metrics', [
            'store_id' => $store->id, 'metric_date' => '2026-08-22', 'segment' => 'blog',
            'page_hash' => hash('sha256', $legacyBlogPage),
        ]);
        $this->assertDatabaseHas('seo_gsc_page_daily_metrics', [
            'store_id' => $store->id, 'metric_date' => '2026-08-22', 'segment' => 'total',
            'page_hash' => hash('sha256', $legacyBlogPage), 'page_id' => SeoGscPage::query()->sole()->id,
        ]);
        $this->assertDatabaseHas('seo_gsc_query_daily_metrics', [
            'store_id' => $store->id, 'metric_date' => '2026-08-22', 'segment' => 'brand',
            'query_hash' => hash('sha256', 'macfox'), 'query_id' => SeoGscQuery::query()->sole()->id,
        ]);
        $this->assertDatabaseMissing('seo_gsc_page_daily_metrics', [
            'store_id' => $store->id, 'page_hash' => hash('sha256', 'https://example.com/low-value'),
        ]);
        $this->assertDatabaseMissing('seo_gsc_query_daily_metrics', [
            'store_id' => $store->id, 'query_hash' => hash('sha256', 'low value query'),
        ]);
        $this->assertDatabaseMissing('seo_gsc_search_type_daily_metrics', [
            'store_id' => $store->id, 'search_type' => 'web',
        ]);
    }

    public function test_gsc_pruner_removes_only_no_click_low_impression_details_and_redundant_rows(): void
    {
        [, $organization, $store] = $this->context('organization-admin');
        $this->gscPage($organization, $store, '2026-08-22', 'total', 'https://example.com/retained', 0, 5, 8);
        $this->gscPage($organization, $store, '2026-08-22', 'total', 'https://example.com/pruned', 0, 4, 80);
        $this->gscQuery($organization, $store, '2026-08-22', 'industry', 'retained click', 1, 1, 9);
        $this->gscQuery($organization, $store, '2026-08-22', 'industry', 'pruned query', 0, 1, 90);
        SeoGscDailyMetric::query()->create([
            'organization_id' => $organization->id, 'store_id' => $store->id, 'metric_date' => '2026-08-22',
            'segment' => 'total', 'clicks' => 1, 'impressions' => 11, 'average_position' => 7, 'synced_at' => now(),
        ]);
        SeoGscSearchTypeDailyMetric::query()->create([
            'organization_id' => $organization->id, 'store_id' => $store->id, 'metric_date' => '2026-08-22',
            'search_type' => 'web', 'clicks' => 1, 'impressions' => 11, 'average_position' => 7, 'synced_at' => now(),
        ]);
        SeoGscBreakdownDailyMetric::query()->create([
            'organization_id' => $organization->id, 'store_id' => $store->id, 'metric_date' => '2026-08-22',
            'search_type' => 'web', 'dimension' => 'country', 'value_hash' => hash('sha256', 'empty'), 'value' => 'empty',
            'clicks' => 0, 'impressions' => 0, 'average_position' => 0, 'synced_at' => now(),
        ]);

        app(GscDimensionBackfillService::class)->backfill($store->id, 1000);
        $version = app(SeoAnalyticsCacheVersionService::class)->current($store->id);

        $this->artisan('seo-analytics:prune-gsc-details', [
            '--store' => $store->id, '--min-impressions' => 5, '--dry-run' => true,
        ])->assertSuccessful();
        $this->assertSame(2, SeoGscPageDailyMetric::query()->count());
        $this->assertSame(2, SeoGscQueryDailyMetric::query()->count());

        $result = app(GscDetailPruneService::class)->prune($store->id, 5, 1000);

        $this->assertSame(1, $result['pages']['deleted_rows']);
        $this->assertSame(1, $result['queries']['deleted_rows']);
        $this->assertSame(1, $result['pages']['orphan_dimensions_deleted']);
        $this->assertSame(1, $result['queries']['orphan_dimensions_deleted']);
        $this->assertDatabaseHas('seo_gsc_page_daily_metrics', ['page' => 'https://example.com/retained']);
        $this->assertDatabaseMissing('seo_gsc_page_daily_metrics', ['page' => 'https://example.com/pruned']);
        $this->assertDatabaseHas('seo_gsc_query_daily_metrics', ['query' => 'retained click']);
        $this->assertDatabaseMissing('seo_gsc_query_daily_metrics', ['query' => 'pruned query']);
        $this->assertSame(1, SeoGscDailyMetric::query()->count());
        $this->assertSame(0, SeoGscSearchTypeDailyMetric::query()->count());
        $this->assertSame(0, SeoGscBreakdownDailyMetric::query()->count());
        $this->assertSame(1, SeoGscPage::query()->count());
        $this->assertSame(1, SeoGscQuery::query()->count());
        $this->assertGreaterThan($version, app(SeoAnalyticsCacheVersionService::class)->current($store->id));
    }

    /** @return array<string, string> */
    private function pages(): array
    {
        return [
            'natural-traffic.seo-geo' => 'SEO / GEO',
            'natural-traffic.brand-media' => '品牌官媒',
            'natural-traffic.influencer-operations' => '红人运营',
            'natural-traffic.edm-email' => 'EDM 邮件',
            'natural-traffic.affiliate-marketing' => '联盟营销',
        ];
    }

    /** @param array<string, mixed> $fields */
    private function archiveRecord(Organization $organization, Store $store, string $section, string $tableName, string $recordId, array $fields): void
    {
        $tableKey = Str::slug($tableName) ?: 'table-'.substr(hash('sha256', $tableName), 0, 12);
        $table = FeishuBitableTable::query()->firstOrCreate(
            ['store_id' => $store->id, 'source_section' => $section, 'source_table_id' => $tableKey],
            ['organization_id' => $organization->id, 'name' => $tableName, 'metadata_encrypted' => [], 'synced_at' => now()],
        );
        FeishuBitableRecord::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'feishu_bitable_table_id' => $table->id,
            'source_record_id' => $recordId,
            'fields_encrypted' => $fields,
            'synced_at' => now(),
        ]);
    }

    /** @return array{User, Organization, Store} */
    private function context(string $roleSlug): array
    {
        $this->seed(PermissionSeeder::class);
        $organization = Organization::query()->create([
            'name' => 'Natural Traffic',
            'code' => 'natural-traffic',
        ]);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $organization->users()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $store = $organization->stores()->create([
            'name' => 'Natural Traffic Store',
            'shopify_domain' => 'natural-traffic.myshopify.com',
            'status' => 'active',
        ]);
        $store->members()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $this->seed(RoleSeeder::class);
        $role = Role::query()->whereBelongsTo($organization)->where('slug', $roleSlug)->firstOrFail();
        $user->roles()->attach($role, [
            'organization_id' => $organization->id,
            'store_id' => null,
        ]);

        return [$user, $organization, $store];
    }

    /** @return array{current_organization_id: int, current_store_id: int} */
    private function contextSession(Organization $organization, Store $store): array
    {
        return [
            'current_organization_id' => $organization->id,
            'current_store_id' => $store->id,
        ];
    }

    /** @param array<string, mixed> $attributes */
    private function workRecord(Organization $organization, Store $store, string $sourceKey, int $row, array $attributes): void
    {
        SeoGoalWorkRecord::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'source_key' => $sourceKey,
            'source_row' => $row,
            'synced_at' => now(),
            ...$attributes,
        ]);
    }

    private function gscQuery(Organization $organization, Store $store, string $date, string $segment, string $query, int $clicks, int $impressions, float $position): void
    {
        SeoGscQueryDailyMetric::query()->create([
            'organization_id' => $organization->id, 'store_id' => $store->id, 'metric_date' => $date, 'segment' => $segment,
            'query_hash' => hash('sha256', $query), 'query' => $query, 'clicks' => $clicks, 'impressions' => $impressions,
            'average_position' => $position, 'synced_at' => now(),
        ]);
    }

    private function gscPage(Organization $organization, Store $store, string $date, string $segment, string $page, int $clicks, int $impressions, float $position): void
    {
        SeoGscPageDailyMetric::query()->create([
            'organization_id' => $organization->id, 'store_id' => $store->id, 'metric_date' => $date, 'segment' => $segment,
            'page_hash' => hash('sha256', $page), 'page' => $page, 'clicks' => $clicks, 'impressions' => $impressions,
            'average_position' => $position, 'synced_at' => now(),
        ]);
    }
}
