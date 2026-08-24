<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\SeoAnalyticsSyncRun;
use App\Models\Store;
use App\Services\NaturalTraffic\NaturalTrafficDashboardService;
use App\Services\NaturalTraffic\NaturalTrafficDataSyncService;
use App\Services\SeoAnalytics\SeoAnalyticsConfigurationService;
use App\Services\SeoAnalytics\SeoAnalyticsSyncManager;
use App\Services\SeoAnalytics\SeoOverviewDashboardService;
use App\Services\SeoGoalDashboardService;
use App\Services\SeoGoalDataSyncService;
use App\Services\StoreFeishuDataLinkService;
use App\Support\CurrentOrganization;
use App\Support\CurrentStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class NaturalTrafficController extends Controller
{
    /** @var array<string, string> */
    public const CHANNELS = [
        'seo-geo' => 'SEO / GEO',
        'brand-media' => '品牌官媒',
        'influencer-operations' => '红人运营',
        'edm-email' => 'EDM 邮件',
        'affiliate-marketing' => '联盟营销',
    ];

    public function __invoke(
        Request $request,
        CurrentOrganization $currentOrganization,
        CurrentStore $currentStore,
        SeoGoalDashboardService $dashboard,
        SeoOverviewDashboardService $overview,
        SeoAnalyticsConfigurationService $seoAnalyticsConfiguration,
        StoreFeishuDataLinkService $dataLinks,
        NaturalTrafficDashboardService $trafficDashboard,
        NaturalTrafficDataSyncService $trafficSync,
        string $channel,
    ): Response {
        $organization = $currentOrganization->require();
        $store = $currentStore->require();

        abort_unless(isset(self::CHANNELS[$channel]), 404);

        if ($channel === 'seo-geo') {
            $activeTab = in_array($request->query('tab'), ['overview', 'gsc', 'ga4'], true) ? (string) $request->query('tab') : 'goals';
            $analyticsFilters = $request->only(['date_from', 'date_to', 'comparison', 'segment']);
            $analytics = match ($activeTab) {
                'overview' => $overview->forStore($store, $analyticsFilters),
                'gsc' => $overview->forGscSource($store, $analyticsFilters),
                'ga4' => $overview->forGa4Source($store, $analyticsFilters),
                default => null,
            };

            return Inertia::render('NaturalTraffic/SeoGeo', [
                'store' => ['id' => $store->id, 'name' => $store->name],
                'activeTab' => $activeTab,
                'dashboard' => $activeTab === 'goals' ? $dashboard->forStore($store, $request->query('month')) : null,
                'overview' => $analytics,
                'overviewSyncStatus' => $overview->syncStatus($store),
                'overviewSourceStatus' => $seoAnalyticsConfiguration->status($store),
                'sourceStatus' => [
                    'daily' => $dataLinks->sectionStatusForFrontend($store, 'seo_geo'),
                    'work' => $dataLinks->sectionStatusForFrontend($store, 'seo_work'),
                ],
                'canSync' => $request->user()?->hasPermission('sync.run', $organization, $store) ?? false,
            ]);
        }

        $component = match ($channel) {
            'brand-media' => 'NaturalTraffic/BrandMedia',
            'influencer-operations' => 'NaturalTraffic/InfluencerOperations',
            'edm-email' => 'NaturalTraffic/EdmEmail',
            'affiliate-marketing' => 'NaturalTraffic/AffiliateMarketing',
        };

        return Inertia::render($component, [
            'store' => ['id' => $store->id, 'name' => $store->name, 'currency' => $store->currency ?: 'USD'],
            'dashboard' => $trafficDashboard->forChannel($store, $channel, $request->only([
                'date_from', 'date_to', 'comparison', 'affiliate',
            ])),
            'configured' => $trafficSync->hasConfiguration($store, $channel),
            'canSync' => $request->user()?->hasPermission('sync.run', $organization, $store) ?? false,
        ]);
    }

    public function refreshChannel(
        Request $request,
        CurrentOrganization $currentOrganization,
        CurrentStore $currentStore,
        NaturalTrafficDataSyncService $sync,
        string $channel,
    ): RedirectResponse {
        $organization = $currentOrganization->require();
        $store = $currentStore->require();

        abort_unless(isset(NaturalTrafficDataSyncService::SECTIONS[$channel]), 404);
        abort_unless((int) $store->organization_id === (int) $organization->id, 404);
        abort_unless($request->user()?->hasPermission('sync.run', $organization, $store), 403);

        try {
            $summary = $sync->sync($store, $channel);
            AuditLog::query()->create([
                'organization_id' => $organization->id,
                'store_id' => $store->id,
                'user_id' => $request->user()?->id,
                'action' => 'natural_traffic_data_synced',
                'subject_type' => Store::class,
                'subject_id' => $store->id,
                'metadata' => [
                    'scope' => 'store',
                    'channel' => $channel,
                    'tables' => $summary['tables'],
                    'fields' => $summary['fields'],
                    'records' => $summary['records'],
                ],
            ]);

            return back()->with('success', sprintf(
                '%s 数据已同步到当前项目数据库：%d 张表、%d 条记录。',
                self::CHANNELS[$channel], $summary['tables'], $summary['records'],
            ));
        } catch (Throwable $exception) {
            report($exception);
            $message = preg_replace('/[A-Za-z0-9_-]{24,}/', '[已隐藏]', $exception->getMessage()) ?: '同步失败，请检查当前项目的数据源配置。';

            return back()->with('error', mb_substr($message, 0, 500));
        }
    }

    public function refreshOverview(
        Request $request,
        CurrentOrganization $currentOrganization,
        CurrentStore $currentStore,
        SeoAnalyticsSyncManager $sync,
    ): RedirectResponse {
        $organization = $currentOrganization->require();
        $store = $currentStore->require();
        abort_unless((int) $store->organization_id === (int) $organization->id, 404);
        abort_unless($request->user()?->hasPermission('sync.run', $organization, $store), 403);

        $run = $sync->queue($store, 'manual', $request->user());
        AuditLog::query()->create([
            'organization_id' => $organization->id, 'store_id' => $store->id, 'user_id' => $request->user()?->id,
            'action' => 'seo_analytics_sync_queued', 'subject_type' => SeoAnalyticsSyncRun::class, 'subject_id' => $run->id,
            'metadata' => ['uuid' => $run->uuid, 'mode' => $run->mode, 'date_from' => $run->date_from?->toDateString(), 'date_to' => $run->date_to?->toDateString()],
        ]);

        return back()->with('success', $run->status === 'queued' ? 'GA4 / GSC 同步任务已提交，页面仍使用本地数据库。' : '已有同步任务正在运行。');
    }

    public function overviewSyncStatus(
        CurrentOrganization $currentOrganization,
        CurrentStore $currentStore,
        SeoOverviewDashboardService $overview,
        string $syncRun,
    ): JsonResponse {
        $organization = $currentOrganization->require();
        $store = $currentStore->require();
        abort_unless((int) $store->organization_id === (int) $organization->id, 404);
        abort_unless(SeoAnalyticsSyncRun::query()->forOrganization($organization->id)->forStore($store->id)->where('uuid', $syncRun)->exists(), 404);

        return response()->json($overview->syncStatus($store));
    }

    public function sourceDetails(
        Request $request,
        CurrentOrganization $currentOrganization,
        CurrentStore $currentStore,
        SeoOverviewDashboardService $overview,
    ): JsonResponse {
        $organization = $currentOrganization->require();
        $store = $currentStore->require();
        abort_unless((int) $store->organization_id === (int) $organization->id, 404);

        $source = (string) $request->query('source', 'gsc');
        abort_unless(in_array($source, ['gsc', 'ga4'], true), 422);

        $filters = $request->only([
            'date_from', 'date_to', 'comparison', 'segment', 'detail_type', 'page', 'per_page',
            'search', 'sort', 'direction', 'channel', 'page_type', 'search_type', 'dimension',
        ]);

        return response()->json($source === 'gsc'
            ? $overview->gscSourceDetails($store, $filters)
            : $overview->ga4SourceDetails($store, $filters));
    }

    public function refresh(
        Request $request,
        CurrentOrganization $currentOrganization,
        CurrentStore $currentStore,
        SeoGoalDataSyncService $sync,
    ): RedirectResponse {
        $organization = $currentOrganization->require();
        $store = $currentStore->require();

        abort_unless((int) $store->organization_id === (int) $organization->id, 404);
        abort_unless($request->user()?->hasPermission('sync.run', $organization, $store), 403);

        try {
            $summary = $sync->sync($store);
            AuditLog::query()->create([
                'organization_id' => $organization->id,
                'store_id' => $store->id,
                'user_id' => $request->user()?->id,
                'action' => 'seo_goal_data_synced',
                'subject_type' => Store::class,
                'subject_id' => $store->id,
                'metadata' => [
                    'scope' => 'store',
                    'daily_records' => $summary['daily_records'],
                    'work_records' => $summary['work_records'],
                ],
            ]);

            return back()->with('success', "SEO 目标数据已更新：{$summary['daily_records']} 条日数据，{$summary['work_records']} 条推进记录。");
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', $exception->getMessage());
        }
    }
}
