<?php

namespace App\Services;

use App\Models\CampaignActivity;
use App\Models\Organization;
use App\Models\Store;
use App\Services\Advertising\AdvertisingChannelSnapshotService;
use App\Services\Shopify\Analytics\ShopifyAnalyticsReportService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class CampaignThemeReviewService
{
    public function __construct(
        private ShopifyAnalyticsReportService $reports,
        private AdvertisingChannelSnapshotService $advertisingChannels,
    ) {}

    /** @return array<string, mixed> */
    public function review(
        Organization $organization,
        Store $store,
        ?int $activityId = null,
        int|string|null $comparison = 'auto',
    ): array {
        if ((int) $store->organization_id !== (int) $organization->getKey()) {
            throw new InvalidArgumentException('The store does not belong to the requested organization.');
        }

        /** @var Collection<int, CampaignActivity> $activities */
        $activities = CampaignActivity::query()
            ->forOrganization($organization)
            ->forStore($store)
            ->where('sales_amount', '>', 0)
            ->with(['planningDocumentSnapshot:id,campaign_activity_id,sync_status,source_url,title'])
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        $selected = $activityId === null
            ? $activities->first()
            : ($activities->firstWhere('id', $activityId) ?? $activities->first());
        $comparisonActivity = $this->comparisonActivity($activities, $selected, $comparison);

        if (! $selected) {
            return [
                'schema' => 'campaign-theme-review-v1',
                'activities' => [],
                'selected_activity_id' => null,
                'comparison_activity_id' => null,
                'activity' => null,
                'comparison_activity' => null,
                'daily_sales' => $this->unavailableDataset('暂无已完成活动。'),
                'traffic_cost_trend' => $this->unavailableTrafficCostTrend('暂无已完成活动。'),
                'funnel' => $this->unavailableDataset('暂无已完成活动。'),
                'channel_performance' => $this->unavailableChannelPerformance('暂无已完成活动。'),
                'model_sales' => $this->unavailableDataset('暂无已完成活动。'),
            ];
        }

        $currentReports = $this->activityReports($store, $selected);
        $comparisonReports = $comparisonActivity
            ? $this->comparisonReports($store, $comparisonActivity)
            : [];
        $dailySales = $this->dailyPerformance(
            $currentReports['sales'] ?? null,
            $currentReports['ad_spend'] ?? null,
            $selected->starts_on?->toDateString(),
            $selected->ends_on?->toDateString(),
            $selected->ad_spend !== null ? (float) $selected->ad_spend : null,
        );

        return [
            'schema' => 'campaign-theme-review-v1',
            'activities' => $activities->map(fn (CampaignActivity $activity): array => [
                'id' => (int) $activity->id,
                'name' => $this->activityName($activity),
                'starts_on' => $activity->starts_on?->toDateString(),
                'ends_on' => $activity->ends_on?->toDateString(),
            ])->values()->all(),
            'selected_activity_id' => (int) $selected->id,
            'comparison_activity_id' => $comparisonActivity ? (int) $comparisonActivity->id : null,
            'activity' => $this->activity(
                $selected,
                $comparisonActivity,
                $currentReports['funnel'] ?? null,
                $comparisonReports['funnel'] ?? null,
            ),
            'comparison_activity' => $comparisonActivity ? $this->activitySummary($comparisonActivity) : null,
            'daily_sales' => $dailySales,
            'traffic_cost_trend' => $this->trafficCostTrend(
                $currentReports['funnel_timeseries'] ?? null,
                $dailySales,
            ),
            'funnel' => $this->funnel(
                $currentReports['funnel'] ?? null,
                $comparisonReports['funnel'] ?? null,
            ),
            'channel_performance' => $this->channelPerformance(
                $currentReports['channel_performance'] ?? null,
            ),
            'model_sales' => $this->modelSales(
                $currentReports['models'] ?? null,
                $comparisonReports['models'] ?? null,
            ),
        ];
    }

    /**
     * @param  Collection<int, CampaignActivity>  $activities
     */
    private function comparisonActivity(
        Collection $activities,
        ?CampaignActivity $selected,
        int|string|null $comparison,
    ): ?CampaignActivity {
        if (! $selected || $comparison === null || $comparison === 'none' || $comparison === '') {
            return null;
        }

        if (is_numeric($comparison)) {
            $requested = $activities->firstWhere('id', (int) $comparison);

            return $requested && ! $requested->is($selected) ? $requested : null;
        }

        $selectedIndex = $activities->search(fn (CampaignActivity $activity): bool => $activity->is($selected));

        return is_int($selectedIndex) ? $activities->get($selectedIndex + 1) : null;
    }

    /** @return array<string, mixed> */
    private function activity(
        CampaignActivity $activity,
        ?CampaignActivity $comparison,
        ?array $funnelReport,
        ?array $comparisonFunnelReport,
    ): array {
        $metrics = $this->metrics($activity, $funnelReport);
        $comparisonMetrics = $comparison ? $this->metrics($comparison, $comparisonFunnelReport) : [];

        foreach ($metrics as $key => $value) {
            $baseline = $comparisonMetrics[$key] ?? null;
            $metrics[$key] = [
                'value' => $value,
                'comparison_value' => $baseline,
                'change_percent' => is_numeric($value) && is_numeric($baseline) && abs((float) $baseline) > 0.000001
                    ? round(((float) $value - (float) $baseline) / abs((float) $baseline) * 100, 1)
                    : null,
            ];
        }

        return [
            ...$this->activitySummary($activity),
            'main_title' => $activity->main_title,
            'subtitle' => $activity->subtitle,
            'core_offer' => $activity->core_offer,
            'planning_document_url' => $activity->planning_document,
            'planning_document_snapshot' => $activity->planningDocumentSnapshot ? [
                'available' => $activity->planningDocumentSnapshot->sync_status === 'synced',
                'title' => $activity->planningDocumentSnapshot->title,
                'source_url' => $activity->planningDocumentSnapshot->source_url,
            ] : null,
            'campaign_images' => $this->imageUrls($activity->campaign_images),
            'email_images' => $this->imageUrls($activity->email_content),
            'analysis' => [
                'summary' => $activity->campaign_summary,
                'diagnosis' => $activity->problem_diagnosis,
                'optimization' => $activity->optimization_analysis,
            ],
            'metrics' => $metrics,
            'source_updated_at' => $activity->source_updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function activitySummary(CampaignActivity $activity): array
    {
        $roi = $activity->roi !== null ? round((float) $activity->roi, 2) : null;

        return [
            'id' => (int) $activity->id,
            'campaign_id' => $activity->campaign_id,
            'name' => $this->activityName($activity),
            'starts_on' => $activity->starts_on?->toDateString(),
            'ends_on' => $activity->ends_on?->toDateString(),
            'judgment' => match (true) {
                $roi === null => 'insufficient_data',
                $roi >= 6 => 'reusable',
                $roi >= 5 => 'scalable',
                default => 'underperforming',
            },
        ];
    }

    /** @return array<string, float|int|null> */
    private function metrics(CampaignActivity $activity, ?array $funnelReport): array
    {
        $gmv = $activity->sales_amount !== null ? round((float) $activity->sales_amount, 2) : null;
        $adSpend = $activity->ad_spend !== null ? round((float) $activity->ad_spend, 2) : null;
        $orders = $activity->order_count !== null ? (int) $activity->order_count : null;
        $activityDays = $this->activityDays($activity);
        $sessions = $this->reportMetric($funnelReport, 'sessions');
        $cartAdditions = $this->reportMetric($funnelReport, 'sessions_with_cart_additions');
        $reachedCheckout = $this->reportMetric($funnelReport, 'sessions_that_reached_checkout');
        $dailyAverageSessions = $sessions !== null && $activityDays !== null
            ? round($sessions / $activityDays, 2)
            : null;

        return [
            'gmv' => $gmv,
            'ad_spend' => $adSpend,
            'roi' => $activity->roi !== null ? round((float) $activity->roi, 2) : null,
            'orders' => $orders,
            'daily_average_sales' => $activity->daily_average_sales !== null ? round((float) $activity->daily_average_sales, 2) : null,
            'daily_average_ad_spend' => $activity->daily_average_ad_spend !== null ? round((float) $activity->daily_average_ad_spend, 2) : null,
            'conversion_rate_percent' => $activity->conversion_rate !== null
                ? round((float) $activity->conversion_rate * 100, 3)
                : null,
            'average_order_value' => $gmv !== null && $orders !== null && $orders > 0
                ? round($gmv / $orders, 2)
                : null,
            'daily_average_sessions' => $dailyAverageSessions,
            'daily_average_site_views' => $dailyAverageSessions !== null
                ? round($dailyAverageSessions * 1.8, 2)
                : null,
            'daily_average_cart_addition_cost' => $adSpend !== null && $cartAdditions !== null && $cartAdditions > 0
                ? round($adSpend / $cartAdditions, 2)
                : null,
            'daily_average_checkout_cost' => $adSpend !== null && $reachedCheckout !== null && $reachedCheckout > 0
                ? round($adSpend / $reachedCheckout, 2)
                : null,
        ];
    }

    private function activityDays(CampaignActivity $activity): ?int
    {
        if (! $activity->starts_on || ! $activity->ends_on) {
            return null;
        }

        $start = CarbonImmutable::parse($activity->starts_on->toDateString());
        $end = CarbonImmutable::parse($activity->ends_on->toDateString());

        return (int) $start->diffInDays($end, true) + 1;
    }

    private function reportMetric(?array $report, string $metric): ?int
    {
        if (! ($report['available'] ?? false)) {
            return null;
        }

        $row = collect($report['rows'] ?? [])->first(fn (mixed $item): bool => is_array($item));
        if (! is_array($row)) {
            return null;
        }

        $totalsKey = "{$metric}__totals";
        if (! array_key_exists($totalsKey, $row) && ! array_key_exists($metric, $row)) {
            return null;
        }

        return $this->reportInteger($row, $metric);
    }

    /** @return array<string, array<string, mixed>> */
    private function activityReports(Store $store, CampaignActivity $activity): array
    {
        if (! $activity->starts_on || ! $activity->ends_on) {
            return [];
        }

        $from = $activity->starts_on->toDateString();
        $to = $activity->ends_on->toDateString();

        return [
            'sales' => $this->reports->report($store, 'core-sales-timeseries', $from, $to),
            'ad_spend' => $this->reports->report($store, 'marketing-engagement-spend-timeseries', $from, $to),
            'funnel_timeseries' => $this->reports->report($store, 'conversion-funnel-timeseries', $from, $to),
            'funnel' => $this->reports->report($store, 'conversion-funnel-breakdown', $from, $to),
            'channel_performance' => $this->advertisingChannels->report($store, $from, $to),
            'models' => $this->reports->report($store, 'model-sales-summary', $from, $to),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function comparisonReports(Store $store, CampaignActivity $activity): array
    {
        if (! $activity->starts_on || ! $activity->ends_on) {
            return [];
        }

        $from = $activity->starts_on->toDateString();
        $to = $activity->ends_on->toDateString();

        return [
            'funnel' => $this->reports->report($store, 'conversion-funnel-breakdown', $from, $to),
            'models' => $this->reports->report($store, 'model-sales-summary', $from, $to),
        ];
    }

    /** @return array<string, mixed> */
    private function trafficCostTrend(?array $funnelReport, array $dailySales): array
    {
        $state = $this->reportState(
            $funnelReport,
            '该活动缺少完整日期，无法读取 Shopify 每日流量与转化成本。',
        );
        $state['pending'] = (bool) $state['pending'] || (bool) ($dailySales['pending'] ?? false);
        $state['stale'] = (bool) $state['stale'] || (bool) ($dailySales['stale'] ?? false);
        $funnelByDate = collect($funnelReport['rows'] ?? [])
            ->filter(fn (mixed $row): bool => is_array($row) && filled($row['day'] ?? null))
            ->groupBy(fn (array $row): string => (string) $row['day'])
            ->map(fn (Collection $rows): array => [
                'sessions' => (int) $rows->sum(fn (array $row): int => $this->integer($row['sessions'] ?? 0)),
                'cart_additions' => (int) $rows->sum(
                    fn (array $row): int => $this->integer($row['sessions_with_cart_additions'] ?? 0),
                ),
                'reached_checkout' => (int) $rows->sum(
                    fn (array $row): int => $this->integer($row['sessions_that_reached_checkout'] ?? 0),
                ),
            ]);

        $points = collect(($state['available'] ?? false) ? ($dailySales['points'] ?? []) : [])
            ->filter(fn (mixed $point): bool => is_array($point) && filled($point['date'] ?? null))
            ->map(function (array $dailyPoint) use ($funnelByDate): array {
                $date = (string) $dailyPoint['date'];
                $funnel = $funnelByDate->get($date, [
                    'sessions' => 0,
                    'cart_additions' => 0,
                    'reached_checkout' => 0,
                ]);
                $adSpend = is_numeric($dailyPoint['ad_spend'] ?? null)
                    ? round((float) $dailyPoint['ad_spend'], 2)
                    : null;
                $cartAdditions = (int) $funnel['cart_additions'];
                $reachedCheckout = (int) $funnel['reached_checkout'];

                return [
                    'date' => $date,
                    'sessions' => (int) $funnel['sessions'],
                    'cart_additions' => $cartAdditions,
                    'reached_checkout' => $reachedCheckout,
                    'ad_spend' => $adSpend,
                    'cart_addition_cost' => $adSpend !== null && $cartAdditions > 0
                        ? round($adSpend / $cartAdditions, 2)
                        : null,
                    'checkout_cost' => $adSpend !== null && $reachedCheckout > 0
                        ? round($adSpend / $reachedCheckout, 2)
                        : null,
                ];
            })
            ->values()
            ->all();

        return [
            ...$state,
            'ad_spend_available' => (bool) ($dailySales['ad_spend_available'] ?? false),
            'ad_spend_message' => $dailySales['ad_spend_message'] ?? null,
            'ad_spend_reconciled' => (bool) ($dailySales['ad_spend_reconciled'] ?? false),
            'ad_spend_coverage_percent' => $dailySales['ad_spend_coverage_percent'] ?? null,
            'date_from' => $dailySales['date_from'] ?? null,
            'date_to' => $dailySales['date_to'] ?? null,
            'points' => $points,
            'date_order' => 'descending',
        ];
    }

    /** @return array<string, mixed> */
    private function dailyPerformance(
        ?array $salesReport,
        ?array $spendReport,
        ?string $from,
        ?string $to,
        ?float $campaignAdSpend,
    ): array {
        $salesState = $this->reportState($salesReport, '该活动缺少完整日期，无法读取 Shopify 日销售。');
        $spendState = $this->reportState($spendReport, '该活动缺少完整日期，无法读取 Shopify 日广告花费。');

        if (! $from || ! $to) {
            return [
                ...$salesState,
                'ad_spend_available' => false,
                'ad_spend_message' => $spendState['message'],
                'ad_spend_reconciled' => false,
                'ad_spend_coverage_percent' => null,
                'points' => [],
                'date_order' => 'descending',
            ];
        }

        $start = CarbonImmutable::parse($from)->startOfDay();
        $end = CarbonImmutable::parse($to)->startOfDay();
        if ($end->lessThan($start)) {
            [$start, $end] = [$end, $start];
        }

        $salesByDate = collect($salesReport['rows'] ?? [])
            ->filter(fn (mixed $row): bool => is_array($row) && filled($row['day'] ?? null))
            ->groupBy(fn (array $row): string => (string) $row['day'])
            ->map(fn (Collection $rows): array => [
                'total_sales' => round((float) $rows->sum(fn (array $row): float => $this->decimal($row['total_sales'] ?? 0)), 2),
                'orders' => (int) $rows->sum(fn (array $row): int => $this->integer($row['orders'] ?? 0)),
            ]);
        $spendByDate = collect($spendReport['rows'] ?? [])
            ->filter(fn (mixed $row): bool => is_array($row) && filled($row['day'] ?? null))
            ->groupBy(fn (array $row): string => (string) $row['day'])
            ->map(fn (Collection $rows): float => round((float) $rows->sum(
                fn (array $row): float => $this->decimal($row['engagements_ad_spend'] ?? 0),
            ), 2));
        $reportedAdSpend = round((float) $spendByDate->sum(), 2);
        $spendAvailable = (bool) ($spendReport['available'] ?? false)
            && ($reportedAdSpend > 0 || $campaignAdSpend === 0.0);
        $reconcileSpend = $spendAvailable
            && $campaignAdSpend !== null
            && $campaignAdSpend > 0
            && $reportedAdSpend > 0;
        $spendScale = $reconcileSpend ? $campaignAdSpend / $reportedAdSpend : 1.0;

        $points = collect();
        for ($date = $end; $date->greaterThanOrEqualTo($start); $date = $date->subDay()) {
            $dateKey = $date->toDateString();
            $sales = $salesByDate->get($dateKey, ['total_sales' => 0.0, 'orders' => 0]);
            $adSpend = $spendAvailable
                ? round((float) $spendByDate->get($dateKey, 0.0) * $spendScale, 2)
                : null;

            $points->push([
                'date' => $dateKey,
                'total_sales' => (float) $sales['total_sales'],
                'ad_spend' => $adSpend,
                'roi' => $adSpend !== null && $adSpend > 0
                    ? round((float) $sales['total_sales'] / $adSpend, 2)
                    : null,
                'orders' => (int) $sales['orders'],
            ]);
        }

        if ($reconcileSpend && $points->isNotEmpty()) {
            $difference = round($campaignAdSpend - (float) $points->sum('ad_spend'), 2);
            if (abs($difference) >= 0.01) {
                $first = $points->first();
                $first['ad_spend'] = round((float) $first['ad_spend'] + $difference, 2);
                $first['roi'] = $first['ad_spend'] > 0
                    ? round((float) $first['total_sales'] / $first['ad_spend'], 2)
                    : null;
                $points->put(0, $first);
            }
        }

        return [
            ...$salesState,
            'pending' => (bool) $salesState['pending'] || (bool) $spendState['pending'],
            'stale' => (bool) $salesState['stale'] || (bool) $spendState['stale'],
            'ad_spend_available' => $spendAvailable,
            'ad_spend_message' => $spendState['message'],
            'ad_spend_reconciled' => $reconcileSpend,
            'ad_spend_coverage_percent' => $reconcileSpend && $campaignAdSpend > 0
                ? round($reportedAdSpend / $campaignAdSpend * 100, 2)
                : null,
            'reported_ad_spend_total' => $reportedAdSpend,
            'campaign_ad_spend_total' => $campaignAdSpend !== null ? round($campaignAdSpend, 2) : null,
            'date_from' => $start->toDateString(),
            'date_to' => $end->toDateString(),
            'points' => $points->all(),
            'date_order' => 'descending',
        ];
    }

    /** @return array<string, mixed> */
    private function funnel(?array $report, ?array $comparisonReport): array
    {
        $state = $this->reportState($report, '该活动缺少完整日期，无法读取 Shopify 转化漏斗。');
        $row = collect($report['rows'] ?? [])->first(fn (mixed $item): bool => is_array($item)) ?? [];
        $comparisonRow = collect($comparisonReport['rows'] ?? [])->first(fn (mixed $item): bool => is_array($item)) ?? [];
        $total = $this->reportInteger($row, 'sessions');
        $comparisonTotal = $this->reportInteger($comparisonRow, 'sessions');
        $definitions = [
            ['key' => 'sessions', 'label' => '访问'],
            ['key' => 'sessions_with_cart_additions', 'label' => '已加入购物车'],
            ['key' => 'sessions_that_reached_checkout', 'label' => '已到达结账'],
            ['key' => 'sessions_that_completed_checkout', 'label' => '已完成成交'],
        ];

        $stages = collect($definitions)->map(function (array $definition) use ($row, $comparisonRow, $total, $comparisonTotal): array {
            $sessions = $this->reportInteger($row, $definition['key']);
            $comparisonSessions = $this->reportInteger($comparisonRow, $definition['key']);

            return [
                'key' => $definition['key'],
                'label' => $definition['label'],
                'sessions' => $sessions,
                'rate_percent' => $total > 0 ? round($sessions / $total * 100, 2) : 0.0,
                'comparison_rate_percent' => $comparisonTotal > 0
                    ? round($comparisonSessions / $comparisonTotal * 100, 2)
                    : null,
            ];
        })->all();

        return [
            ...$state,
            'comparison_available' => (bool) ($comparisonReport['available'] ?? false),
            'stages' => $stages,
        ];
    }

    /** @return array<string, mixed> */
    private function channelPerformance(?array $report): array
    {
        if ($report === null) {
            return $this->unavailableChannelPerformance('该活动缺少完整日期，无法读取广告平台周期快照。');
        }

        $state = [
            'available' => (bool) ($report['available'] ?? false),
            'complete' => (bool) ($report['complete'] ?? false),
            'pending' => (bool) ($report['pending'] ?? false) || (bool) data_get($report, 'storage.pending', false),
            'stale' => (bool) data_get($report, 'storage.stale', false),
            'source' => 'advertising_apis',
            'message' => $report['message'] ?? null,
        ];
        $order = ['facebook' => 0, 'google' => 1, 'tiktok' => 2, 'bing' => 3, 'criteo' => 4];
        $grouped = collect($report['channels'] ?? [])
            ->filter(fn (mixed $row): bool => is_array($row) && (bool) ($row['available'] ?? false))
            ->map(fn (array $row): array => [
                'key' => (string) ($row['key'] ?? ''),
                'name' => (string) ($row['name'] ?? ''),
                'ad_spend' => max(0.0, $this->decimal($row['ad_spend'] ?? 0)),
                'attributed_sales' => max(0.0, $this->decimal($row['attributed_sales'] ?? 0)),
            ])
            ->filter(fn (array $row): bool => isset($order[$row['key']]))
            ->sortBy(fn (array $row): int => $order[$row['key']])
            ->values();
        $totalAdSpend = round((float) $grouped->sum('ad_spend'), 2);
        $totalAttributedSales = round((float) $grouped->sum('attributed_sales'), 2);

        $channels = $grouped
            ->map(function (array $channel) use ($totalAdSpend, $totalAttributedSales): array {
                $spendShare = $totalAdSpend > 0
                    ? round((float) $channel['ad_spend'] / $totalAdSpend * 100, 1)
                    : 0.0;
                $salesShare = $totalAttributedSales > 0
                    ? round((float) $channel['attributed_sales'] / $totalAttributedSales * 100, 1)
                    : 0.0;

                return [
                    ...$channel,
                    'spend_share_percent' => $spendShare,
                    'sales_share_percent' => $salesShare,
                    'efficiency_roi' => $spendShare > 0 ? round($salesShare / $spendShare, 2) : null,
                ];
            })
            ->sortByDesc('ad_spend')
            ->values()
            ->all();

        return [
            ...$state,
            'semantics' => 'advertising_platform_attribution_share',
            'total_ad_spend' => $totalAdSpend,
            'total_attributed_sales' => $totalAttributedSales,
            'failed_channels' => array_values(array_filter(
                is_array($report['failed_channels'] ?? null) ? $report['failed_channels'] : [],
                'is_string',
            )),
            'channels' => $channels,
        ];
    }

    /** @return array<string, mixed> */
    private function modelSales(?array $report, ?array $comparisonReport): array
    {
        $state = $this->reportState($report, '该活动缺少完整日期，无法读取 Shopify 车型销量。');
        $current = $this->modelRows($report['rows'] ?? []);
        $comparison = collect($this->modelRows($comparisonReport['rows'] ?? []))->keyBy('key');
        $totalUnits = (int) collect($current)->sum('units');

        $models = collect($current)->map(function (array $model) use ($comparison, $totalUnits): array {
            $previous = $comparison->get($model['key']);
            $previousUnits = is_array($previous) ? (int) $previous['units'] : 0;

            return [
                ...$model,
                'share_percent' => $totalUnits > 0 ? round($model['units'] / $totalUnits * 100, 1) : 0.0,
                'comparison_units' => $previousUnits,
                'change_percent' => $previousUnits > 0
                    ? round(($model['units'] - $previousUnits) / $previousUnits * 100, 1)
                    : null,
            ];
        })->sortByDesc('units')->values()->all();

        return [
            ...$state,
            'comparison_available' => (bool) ($comparisonReport['available'] ?? false),
            'quantity_semantics' => 'net_items_sold',
            'grouping' => 'product_title',
            'total_units' => $totalUnits,
            'models' => $models,
        ];
    }

    /** @param list<array<string, mixed>> $rows @return list<array<string, mixed>> */
    private function modelRows(array $rows): array
    {
        return collect($rows)
            ->filter(fn (mixed $row): bool => is_array($row) && $this->isBikeModelRow($row))
            ->groupBy(fn (array $row): string => mb_strtolower(trim((string) $row['product_title'])))
            ->map(function (Collection $rows, string $key): array {
                $first = $rows->first();

                return [
                    'key' => $key,
                    'name' => trim((string) ($first['product_title'] ?? '未命名车型')),
                    'units' => (int) $rows->sum(fn (array $row): int => $this->integer($row['net_items_sold'] ?? 0)),
                    'total_sales' => round((float) $rows->sum(fn (array $row): float => $this->decimal($row['total_sales'] ?? 0)), 2),
                ];
            })
            ->filter(fn (array $model): bool => $model['units'] > 0)
            ->values()
            ->all();
    }

    /** @param  array<string, mixed>  $row */
    private function isBikeModelRow(array $row): bool
    {
        $title = mb_strtolower(trim((string) ($row['product_title'] ?? '')));

        if ($title === '') {
            return false;
        }

        $productType = mb_strtolower(trim((string) ($row['product_type'] ?? '')));
        $isBikeType = in_array($productType, ['electric bike', 'electric bicycle', 'e-bike', 'ebike'], true);
        $modelPattern = '(?:x1s|x2|x7l?|m16|m19|m20x)';
        $hasModelCode = preg_match("/(?:^|[^a-z0-9]){$modelPattern}(?:$|[^a-z0-9])/iu", $title) === 1;

        if (! $isBikeType && ! $hasModelCode) {
            return false;
        }

        $accessoryPattern = implode('|', [
            'adapter', 'axle', 'bag', 'basket', 'battery', 'brake', 'charger', 'chain',
            'controller', 'crank', 'fender', 'fork', 'frame', 'handlebar', 'headlight',
            'holder', 'instrument', 'key', 'light', 'mirror', 'motor', 'outfit', 'pad',
            'pedal', 'protection', 'rack', 'rim', 'screw', 'seat', 'shield', 'stand',
            'suspension', 'taillight', 'throttle', 'tire', 'tube', 'wheel',
        ]);

        if (preg_match("/\\b(?:{$accessoryPattern})\\b/iu", $title) === 1) {
            return false;
        }

        return $isBikeType
            || preg_match('/^macfox\s+(?:e-?bike\s+)?'.$modelPattern.'(?:$|\s)/iu', $title) === 1
            || preg_match('/^macfox\s+'.$modelPattern.'.*\b(?:e-?bike|electric\s+(?:mountain\s+)?bike)\b/iu', $title) === 1;
    }

    /** @return array<string, mixed> */
    private function reportState(?array $report, string $missingDateMessage): array
    {
        if ($report === null) {
            return $this->unavailableDataset($missingDateMessage);
        }

        return [
            'available' => (bool) ($report['available'] ?? false),
            'pending' => (bool) data_get($report, 'storage.pending', false),
            'stale' => (bool) data_get($report, 'storage.stale', false),
            'source' => 'shopifyql',
            'message' => $report['error'] ?? null,
        ];
    }

    /** @return array{available: false, pending: false, stale: false, source: string, message: string} */
    private function unavailableDataset(string $message): array
    {
        return [
            'available' => false,
            'pending' => false,
            'stale' => false,
            'source' => 'shopifyql',
            'message' => $message,
        ];
    }

    /** @return array<string, mixed> */
    private function unavailableChannelPerformance(string $message): array
    {
        return [
            'available' => false,
            'complete' => false,
            'pending' => false,
            'stale' => false,
            'source' => 'advertising_apis',
            'message' => $message,
            'semantics' => 'advertising_platform_attribution_share',
            'total_ad_spend' => 0.0,
            'total_attributed_sales' => 0.0,
            'failed_channels' => [],
            'channels' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function unavailableTrafficCostTrend(string $message): array
    {
        return [
            ...$this->unavailableDataset($message),
            'ad_spend_available' => false,
            'ad_spend_message' => $message,
            'ad_spend_reconciled' => false,
            'ad_spend_coverage_percent' => null,
            'date_from' => null,
            'date_to' => null,
            'points' => [],
            'date_order' => 'descending',
        ];
    }

    /** @return list<string> */
    private function imageUrls(mixed $images): array
    {
        return collect(is_array($images) ? $images : [])
            ->filter(fn (mixed $url): bool => is_string($url) && str_starts_with($url, '/storage/'))
            ->values()
            ->all();
    }

    private function reportInteger(array $row, string $metric): int
    {
        return $this->integer($row["{$metric}__totals"] ?? $row[$metric] ?? 0);
    }

    private function activityName(CampaignActivity $activity): string
    {
        return $activity->campaign_name ?: $activity->campaign_id ?: '未命名活动';
    }

    private function integer(mixed $value): int
    {
        return is_numeric($value) ? (int) round((float) $value) : 0;
    }

    private function decimal(mixed $value): float
    {
        return is_numeric($value) ? round((float) $value, 2) : 0.0;
    }
}
