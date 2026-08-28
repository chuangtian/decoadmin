<?php

namespace App\Services\SeoAnalytics;

use App\Models\SeoAnalyticsSyncRun;
use App\Models\SeoGa4ChannelDailyMetric;
use App\Models\SeoGa4LandingPageDailyMetric;
use App\Models\SeoGscBreakdownDailyMetric;
use App\Models\SeoGscDailyMetric;
use App\Models\SeoGscSearchTypeDailyMetric;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SeoOverviewDashboardService
{
    public function __construct(
        private GscMetricQueryService $gscMetrics,
        private SeoAnalyticsCacheVersionService $cacheVersion,
    ) {}

    /** @param array{date_from?: mixed, date_to?: mixed, comparison?: mixed} $filters */
    public function forStore(Store $store, array $filters = []): array
    {
        [$from, $to] = $this->dates($store, $filters);
        $days = $from->diffInDays($to) + 1;
        $previousTo = $from->subDay();
        $previousFrom = $previousTo->subDays($days - 1);
        $yearFrom = $from->subYear();
        $yearTo = $to->subYear();
        $comparison = in_array($filters['comparison'] ?? null, ['previous', 'year', 'none'], true) ? (string) $filters['comparison'] : 'previous';
        $comparisonFrom = $comparison === 'year' ? $yearFrom : $previousFrom;
        $comparisonTo = $comparison === 'year' ? $yearTo : $previousTo;

        $segments = $this->gscOverviewSegments($store, $from, $to, $comparisonFrom, $comparisonTo, $yearFrom, $yearTo);
        $segments['anonymous'] = $this->anonymousSegment($segments['total'], $segments['brand'], $segments['industry']);
        $segments['web'] = [
            ...$segments['total'],
            'label' => '网页',
            'description' => 'GSC Page 维度的全站 URL 表现。',
            'queries' => [],
        ];

        return [
            'schema' => 'seo-overview-dashboard-v1',
            'filters' => [
                'date_from' => $from->toDateString(), 'date_to' => $to->toDateString(), 'comparison' => $comparison,
            ],
            'comparison_period' => ['date_from' => $comparisonFrom->toDateString(), 'date_to' => $comparisonTo->toDateString()],
            'year_period' => ['date_from' => $yearFrom->toDateString(), 'date_to' => $yearTo->toDateString()],
            'data_through' => SeoGscDailyMetric::query()->forOrganization($store->organization_id)->forStore($store->id)->where('segment', 'total')->max('metric_date'),
            'generated_at' => now()->toIso8601String(),
            'ga4' => $this->ga4($store, $from, $to, $comparisonFrom, $comparisonTo, $yearFrom, $yearTo),
            'gsc' => $segments,
            'data_ready' => SeoGscDailyMetric::query()->forOrganization($store->organization_id)->forStore($store->id)->exists()
                || SeoGa4ChannelDailyMetric::query()->forOrganization($store->organization_id)->forStore($store->id)->exists(),
        ];
    }

    /** @param array{date_from?: mixed, date_to?: mixed, comparison?: mixed} $filters */
    public function forGscSource(Store $store, array $filters = []): array
    {
        [$from, $to] = $this->dates($store, $filters, 'gsc');
        [$comparison, $comparisonFrom, $comparisonTo, $yearFrom, $yearTo] = $this->comparisonDates($from, $to, $filters);
        $segment = in_array($filters['segment'] ?? null, ['total', 'brand', 'industry', 'blog'], true) ? (string) $filters['segment'] : 'total';
        $labels = [
            'total' => ['总点击', '全站 GSC 搜索表现。'], 'brand' => ['品牌词点击', '匹配 Macfox 品牌词正则的 Query。'],
            'industry' => ['行业词点击', '排除品牌词正则后的 Query。'], 'blog' => ['博客点击', 'Page 包含 /blogs/ 的页面。'],
        ];
        $currentTotals = $this->gscSourceSegmentTotals($store, $from, $to);
        $previousTotals = $this->gscSourceSegmentTotals($store, $comparisonFrom, $comparisonTo);
        $sourceSegments = collect(['total', 'brand', 'industry', 'blog'])->mapWithKeys(function (string $key) use ($segment, $store, $from, $to, $labels, $currentTotals, $previousTotals): array {
            $current = $currentTotals[$key];
            $previous = $previousTotals[$key];

            return [$key => [
                'label' => $labels[$key][0], 'description' => $labels[$key][1],
                'current' => $current, 'previous' => $previous, 'deltas' => $this->summaryDeltas($current, $previous),
                'trend' => $key === $segment ? $this->gscTrend($store, $key, $from, $to) : [],
                'queries' => [], 'pages' => [],
            ]];
        })->all();

        return [
            'schema' => 'seo-gsc-source-v1',
            'filters' => ['date_from' => $from->toDateString(), 'date_to' => $to->toDateString(), 'comparison' => $comparison, 'segment' => $segment],
            'comparison_period' => ['date_from' => $comparisonFrom->toDateString(), 'date_to' => $comparisonTo->toDateString()],
            'data_through' => SeoGscDailyMetric::query()->forOrganization($store->organization_id)->forStore($store->id)->where('segment', 'total')->max('metric_date'),
            'generated_at' => now()->toIso8601String(),
            'segment_key' => $segment,
            'segment' => $sourceSegments[$segment],
            'segments' => $sourceSegments,
            'segment_totals' => $currentTotals,
            'data_ready' => SeoGscDailyMetric::query()->forOrganization($store->organization_id)->forStore($store->id)->exists(),
        ];
    }

    /** @param array{date_from?: mixed, date_to?: mixed, comparison?: mixed} $filters */
    public function forGa4Source(Store $store, array $filters = []): array
    {
        [$from, $to] = $this->dates($store, $filters, 'ga4');
        [$comparison, $comparisonFrom, $comparisonTo, $yearFrom, $yearTo] = $this->comparisonDates($from, $to, $filters);

        $current = $this->ga4SourceSummary($store, $from, $to);
        $previous = $this->ga4SourceSummary($store, $comparisonFrom, $comparisonTo);

        return [
            'schema' => 'seo-ga4-source-v1',
            'filters' => ['date_from' => $from->toDateString(), 'date_to' => $to->toDateString(), 'comparison' => $comparison],
            'comparison_period' => ['date_from' => $comparisonFrom->toDateString(), 'date_to' => $comparisonTo->toDateString()],
            'data_through' => SeoGa4ChannelDailyMetric::query()->forOrganization($store->organization_id)->forStore($store->id)->max('metric_date'),
            'generated_at' => now()->toIso8601String(),
            'current' => $current,
            'previous' => $previous,
            'deltas' => $this->summaryDeltas($current, $previous),
            'trend' => $this->ga4SourceTrend($store, $from, $to),
            'channels' => $this->ga4Channels($store, $from, $to),
            'landing_pages' => [],
            'data_ready' => SeoGa4ChannelDailyMetric::query()->forOrganization($store->organization_id)->forStore($store->id)->exists(),
        ];
    }

    /** @param array<string, mixed> $filters */
    public function gscSourceDetails(Store $store, array $filters = []): array
    {
        ksort($filters);
        $version = $this->cacheVersion->current((int) $store->getKey());
        $key = implode(':', [
            'seo-gsc-details', 'schema-v2', 'organization', $store->organization_id,
            'store', $store->getKey(), "v{$version}", sha1((string) json_encode($filters)),
        ]);

        return Cache::remember($key, now()->addMinutes(5), fn (): array => $this->buildGscSourceDetails($store, $filters));
    }

    /** @param array<string, mixed> $filters */
    private function buildGscSourceDetails(Store $store, array $filters = []): array
    {
        [$from, $to] = $this->dates($store, $filters, 'gsc');
        [, $comparisonFrom, $comparisonTo] = $this->comparisonDates($from, $to, $filters);
        $segment = in_array($filters['segment'] ?? null, ['total', 'brand', 'industry', 'blog'], true)
            ? (string) $filters['segment'] : 'total';
        $searchType = in_array($filters['search_type'] ?? null, ['web', 'image', 'video', 'news'], true)
            ? (string) $filters['search_type'] : 'web';
        $dimension = in_array($filters['dimension'] ?? null, ['country', 'device', 'search_appearance'], true)
            ? (string) $filters['dimension'] : '';
        $type = in_array($filters['detail_type'] ?? null, ['queries', 'pages'], true)
            ? (string) $filters['detail_type'] : ($segment === 'blog' ? 'pages' : 'queries');
        if ($segment === 'blog') {
            $type = 'pages';
        }
        $segments = $type === 'queries' && $segment === 'total'
            ? ['brand', 'industry']
            : ($type === 'pages' && $segment === 'blog' ? ['total'] : [$segment]);
        $blogOnly = $type === 'pages' && $segment === 'blog';
        [$page, $perPage] = $this->pageFilters($filters, [12, 25, 50, 100], 50);
        $search = mb_substr(trim((string) ($filters['search'] ?? '')), 0, 200);
        $sort = in_array($filters['sort'] ?? null, ['clicks', 'impressions', 'ctr', 'position', 'label'], true)
            ? (string) $filters['sort'] : 'clicks';
        $direction = ($filters['direction'] ?? null) === 'asc' ? 'asc' : 'desc';
        $useFactPagination = $dimension === '' && $searchType === 'web' && $sort !== 'label'
            && $this->gscMetrics->dimensionsReady($store, $type);

        $base = $dimension !== ''
            ? $this->gscBreakdownAggregate($store, $from, $to, $searchType, $dimension, $search)
            : ($searchType === 'web'
                ? ($useFactPagination
                    ? $this->gscMetrics->factAggregate($store, $type, $segments, $from, $to, $search, [], $blogOnly)
                    : $this->gscMetrics->aggregate($store, $type, $segments, $from, $to, $search, [], $blogOnly))
                : null);
        if ($base === null) {
            return [
                'schema' => 'seo-gsc-detail-page-v1', 'type' => $type, 'segment' => $segment,
                'search_type' => $searchType, 'dimension' => $dimension, 'rows' => [],
                'summary' => $this->gscSearchTypeSummary($store, $searchType, $from, $to),
                'trend' => $this->gscSearchTypeTrend($store, $searchType, $from, $to),
                'pagination' => $this->pagination(1, $perPage, 0),
            ];
        }
        $sortColumn = ['ctr' => 'ctr', 'position' => 'position', 'label' => 'label'][$sort] ?? $sort;
        $ranked = DB::query()->fromSub((clone $base), 'gsc_detail_rows')
            ->select('gsc_detail_rows.*')->selectRaw('COUNT(*) OVER() total_rows');
        $rows = (clone $ranked)->orderBy($sortColumn, $direction)->offset(($page - 1) * $perPage)->limit($perPage)->get();
        $total = (int) ($rows->first()?->total_rows ?? 0);
        if ($rows->isEmpty() && $page > 1) {
            $total = (int) DB::query()->fromSub((clone $base), 'gsc_detail_count')->count();
            $page = min($page, max(1, (int) ceil($total / $perPage)));
            $rows = (clone $ranked)->orderBy($sortColumn, $direction)->offset(($page - 1) * $perPage)->limit($perPage)->get();
        }
        if ($useFactPagination) {
            $rows = $this->gscMetrics->hydrateDimensions($type, $rows);
        }
        $hashes = $rows->pluck('hash')->map(fn ($value): string => (string) $value)->all();
        if ($useFactPagination) {
            $dimensionIds = $rows->pluck('dimension_id')->map(fn ($id): int => (int) $id)->all();
            $previous = $dimensionIds === [] ? collect() : $this->gscMetrics
                ->factAggregate($store, $type, $segments, $comparisonFrom, $comparisonTo, '', $dimensionIds, $blogOnly)
                ->get()->keyBy('dimension_id');
        } else {
            $previous = $hashes === [] ? collect() : ($dimension !== ''
                ? $this->gscBreakdownAggregate($store, $comparisonFrom, $comparisonTo, $searchType, $dimension, '', $hashes)
                : $this->gscMetrics->aggregate($store, $type, $segments, $comparisonFrom, $comparisonTo, '', $hashes, $blogOnly))
                ->get()->keyBy('hash');
        }

        $summary = $searchType === 'web'
            ? $this->gscSummary($store, $segment, $from, $to)
            : $this->gscSearchTypeSummary($store, $searchType, $from, $to);
        $trend = $searchType === 'web'
            ? $this->gscTrend($store, $segment, $from, $to)
            : $this->gscSearchTypeTrend($store, $searchType, $from, $to);

        return [
            'schema' => 'seo-gsc-detail-page-v1', 'type' => $type, 'segment' => $segment,
            'search_type' => $searchType, 'dimension' => $dimension, 'summary' => $summary, 'trend' => $trend,
            'rows' => $rows->map(fn ($row): array => $this->gscDetailRow(
                $row,
                $useFactPagination ? $previous->get((int) $row->dimension_id) : $previous->get((string) $row->hash),
            ))->values()->all(),
            'pagination' => $this->pagination($page, $perPage, $total),
        ];
    }

    /** @param array<string, mixed> $filters */
    public function ga4SourceDetails(Store $store, array $filters = []): array
    {
        [$from, $to] = $this->dates($store, $filters, 'ga4');
        [, $comparisonFrom, $comparisonTo] = $this->comparisonDates($from, $to, $filters);
        [$page, $perPage] = $this->pageFilters($filters, [12, 30, 50, 100], 30);
        $search = mb_substr(trim((string) ($filters['search'] ?? '')), 0, 200);
        $channel = mb_substr(trim((string) ($filters['channel'] ?? '')), 0, 120);
        $pageType = in_array($filters['page_type'] ?? null, ['home', 'product', 'collection', 'blog', 'page', 'other'], true)
            ? (string) $filters['page_type'] : '';
        $sort = in_array($filters['sort'] ?? null, ['revenue', 'sessions', 'active_users', 'new_users', 'key_events', 'bounce_rate', 'average_engagement_seconds', 'page'], true)
            ? (string) $filters['sort'] : 'revenue';
        $direction = ($filters['direction'] ?? null) === 'asc' ? 'asc' : 'desc';

        $base = $this->ga4LandingAggregate($store, $from, $to, $search, $channel, $pageType);
        $total = (int) DB::query()->fromSub((clone $base)->toBase(), 'ga4_detail_rows')->count();
        $page = min($page, max(1, (int) ceil($total / $perPage)));
        $totals = DB::query()->fromSub((clone $base)->toBase(), 'ga4_totals')->selectRaw(
            'COALESCE(SUM(revenue), 0) revenue, COALESCE(SUM(sessions), 0) sessions, COALESCE(SUM(active_users), 0) active_users, COALESCE(SUM(new_users), 0) new_users, COALESCE(SUM(key_events), 0) key_events'
        )->first();
        $sortColumn = $sort === 'page' ? 'landing_page' : $sort;
        $rows = (clone $base)->orderBy($sortColumn, $direction)->offset(($page - 1) * $perPage)->limit($perPage)->get();
        $previous = collect();
        if ($rows->isNotEmpty()) {
            $previousQuery = $this->ga4LandingAggregate($store, $comparisonFrom, $comparisonTo, '', '', '');
            $previousQuery->where(function (Builder $scope) use ($rows): void {
                foreach ($rows as $row) {
                    $scope->orWhere(function (Builder $pair) use ($row): void {
                        $pair->where('landing_page_hash', (string) $row->landing_page_hash)
                            ->where('channel_group', (string) $row->channel_group);
                    });
                }
            });
            $previous = $previousQuery->get()->keyBy(fn ($row): string => (string) $row->landing_page_hash.'|'.(string) $row->channel_group);
        }

        return [
            'schema' => 'seo-ga4-detail-page-v1',
            'rows' => $rows->map(function ($row) use ($previous): array {
                $key = (string) $row->landing_page_hash.'|'.(string) $row->channel_group;

                return $this->ga4DetailRow($row, $previous->get($key));
            })->values()->all(),
            'totals' => [
                'revenue' => round((float) ($totals?->revenue ?? 0), 2), 'sessions' => (int) ($totals?->sessions ?? 0),
                'active_users' => (int) ($totals?->active_users ?? 0), 'new_users' => (int) ($totals?->new_users ?? 0),
                'key_events' => round((float) ($totals?->key_events ?? 0), 1),
            ],
            'pagination' => $this->pagination($page, $perPage, $total),
        ];
    }

    /** @return array<string, array{clicks: int, impressions: int, ctr: float, position: float}> */
    private function gscSourceSegmentTotals(Store $store, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = $this->dateRange(SeoGscDailyMetric::query()->forOrganization($store->organization_id)->forStore($store->id), $from, $to)
            ->selectRaw('segment, SUM(clicks) clicks, SUM(impressions) impressions')
            ->selectRaw('CASE WHEN SUM(impressions) > 0 THEN SUM(average_position * impressions) / SUM(impressions) ELSE 0 END position')
            ->whereIn('segment', ['total', 'brand', 'industry', 'blog'])->groupBy('segment')->get()->keyBy('segment');

        return collect(['total', 'brand', 'industry', 'blog'])->mapWithKeys(function (string $segment) use ($rows): array {
            $row = $rows->get($segment);
            $clicks = (int) ($row?->clicks ?? 0);
            $impressions = (int) ($row?->impressions ?? 0);

            return [$segment => [
                'clicks' => $clicks, 'impressions' => $impressions,
                'ctr' => $impressions > 0 ? round($clicks / $impressions * 100, 2) : 0.0,
                'position' => round((float) ($row?->position ?? 0), 2),
            ]];
        })->all();
    }

    public function syncStatus(Store $store): array
    {
        $run = SeoAnalyticsSyncRun::query()->forOrganization($store->organization_id)->forStore($store->id)->latest('id')->first();
        $lastCompleted = SeoAnalyticsSyncRun::query()->forOrganization($store->organization_id)->forStore($store->id)
            ->where('status', 'completed')->latest('completed_at')->value('completed_at');

        return [
            'schema' => 'seo-analytics-sync-status-v1',
            'uuid' => $run?->uuid,
            'status' => $run?->status ?? 'idle',
            'mode' => $run?->mode,
            'progress_percent' => $run?->progress_percent ?? 0,
            'processed_rows' => $run?->processed_rows ?? 0,
            'total_shards' => (int) data_get($run?->result, 'total_shards', 0),
            'completed_shards' => count((array) data_get($run?->result, 'completed_shards', [])),
            'priority_ready' => (bool) data_get($run?->result, 'priority_ready', false),
            'priority_period' => data_get($run?->result, 'priority_period'),
            'last_completed_shard' => data_get($run?->result, 'last_completed_shard'),
            'date_from' => $run?->date_from?->toDateString(),
            'date_to' => $run?->date_to?->toDateString(),
            'message' => $run?->last_error,
            'last_completed_at' => $lastCompleted ? CarbonImmutable::parse((string) $lastCompleted)->toIso8601String() : null,
        ];
    }

    /** @return array{CarbonImmutable, CarbonImmutable} */
    private function dates(Store $store, array $filters, string $source = 'gsc'): array
    {
        $timezone = $store->timezone ?: 'America/Los_Angeles';
        $latest = $source === 'ga4'
            ? SeoGa4ChannelDailyMetric::query()->forOrganization($store->organization_id)->forStore($store->id)->max('metric_date')
            : SeoGscDailyMetric::query()->forOrganization($store->organization_id)->forStore($store->id)->where('segment', 'total')->max('metric_date');
        $fallback = $latest ? CarbonImmutable::parse((string) $latest, $timezone) : CarbonImmutable::now($timezone)->subDays(2);
        $to = $this->date($filters['date_to'] ?? null, $fallback, $timezone);
        $from = $this->date($filters['date_from'] ?? null, $to->subDays(6), $timezone);
        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }
        if ($from->diffInDays($to) > 366) {
            $from = $to->subDays(366);
        }

        return [$from, $to];
    }

    /** @return array{string, CarbonImmutable, CarbonImmutable, CarbonImmutable, CarbonImmutable} */
    private function comparisonDates(CarbonImmutable $from, CarbonImmutable $to, array $filters): array
    {
        $days = $from->diffInDays($to) + 1;
        $previousTo = $from->subDay();
        $previousFrom = $previousTo->subDays($days - 1);
        $yearFrom = $from->subYear();
        $yearTo = $to->subYear();
        $comparison = in_array($filters['comparison'] ?? null, ['previous', 'year', 'none'], true) ? (string) $filters['comparison'] : 'previous';

        return [
            $comparison,
            $comparison === 'year' ? $yearFrom : $previousFrom,
            $comparison === 'year' ? $yearTo : $previousTo,
            $yearFrom,
            $yearTo,
        ];
    }

    private function date(mixed $value, CarbonImmutable $fallback, string $timezone): CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $fallback;
        }
        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $value, $timezone)->startOfDay();
        } catch (\Throwable) {
            return $fallback;
        }
    }

    private function ga4(Store $store, CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $previousFrom, CarbonImmutable $previousTo, CarbonImmutable $yearFrom, CarbonImmutable $yearTo): array
    {
        $current = $this->ga4Summary($store, $from, $to);
        $previous = $this->ga4Summary($store, $previousFrom, $previousTo);
        $year = $this->ga4Summary($store, $yearFrom, $yearTo);

        return [
            'current' => $current, 'previous' => $previous, 'year' => $year,
            'deltas' => $this->summaryDeltas($current, $previous),
            'year_deltas' => $this->summaryDeltas($current, $year),
            'trend' => $this->ga4Trend($store, $from, $to),
            'previous_trend' => $this->ga4Trend($store, $previousFrom, $previousTo),
            'channels' => $this->ga4Channels($store, $from, $to),
            // Large landing-page collections are loaded after the dashboard shell is visible.
            'landing_pages' => [],
            'landing_trend' => ['series' => [], 'points' => []],
        ];
    }

    /** @return array{revenue: float, sessions: int} */
    private function ga4Summary(Store $store, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $row = $this->dateRange($this->ga4Base($store), $from, $to)
            ->selectRaw('COALESCE(SUM(total_revenue), 0) revenue, COALESCE(SUM(sessions), 0) sessions')->first();

        return ['revenue' => round((float) ($row?->revenue ?? 0), 2), 'sessions' => (int) ($row?->sessions ?? 0)];
    }

    /** @return array<string, int|float> */
    private function ga4SourceSummary(Store $store, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $summary = $this->ga4Summary($store, $from, $to);
        $landing = $this->dateRange(
            SeoGa4LandingPageDailyMetric::query()->forOrganization($store->organization_id)->forStore($store->id),
            $from,
            $to,
        )->selectRaw('COALESCE(SUM(engagement_duration), 0) engagement_duration, COALESCE(SUM(key_events), 0) key_events')
            ->selectRaw('CASE WHEN SUM(sessions) > 0 THEN SUM(bounce_rate * sessions) / SUM(sessions) * 100 ELSE 0 END bounce_rate')
            ->first();

        return [
            ...$summary,
            'average_engagement_seconds' => $summary['sessions'] > 0
                ? round((float) ($landing?->engagement_duration ?? 0) / $summary['sessions'], 1)
                : 0.0,
            'key_events' => round((float) ($landing?->key_events ?? 0), 1),
            'bounce_rate' => round((float) ($landing?->bounce_rate ?? 0), 2),
        ];
    }

    /** @return list<array<string, int|float|string>> */
    private function ga4SourceTrend(Store $store, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $channels = collect($this->ga4Trend($store, $from, $to))->keyBy('date');
        $landing = $this->dateRange(
            SeoGa4LandingPageDailyMetric::query()->forOrganization($store->organization_id)->forStore($store->id),
            $from,
            $to,
        )->selectRaw('metric_date date, SUM(engagement_duration) engagement_duration, SUM(key_events) key_events, SUM(sessions) landing_sessions')
            ->selectRaw('CASE WHEN SUM(sessions) > 0 THEN SUM(bounce_rate * sessions) / SUM(sessions) * 100 ELSE 0 END bounce_rate')
            ->groupBy('metric_date')->get()->keyBy(fn ($row): string => (string) $row->date);

        return $channels->map(function (array $row, string $date) use ($landing): array {
            $daily = $landing->get($date);
            $sessions = (int) ($row['sessions'] ?? 0);

            return [
                ...$row,
                'average_engagement_seconds' => $sessions > 0 ? round((float) ($daily?->engagement_duration ?? 0) / $sessions, 1) : 0.0,
                'key_events' => round((float) ($daily?->key_events ?? 0), 1),
                'bounce_rate' => round((float) ($daily?->bounce_rate ?? 0), 2),
            ];
        })->values()->all();
    }

    /** @return list<array{date: string, revenue: float, sessions: int}> */
    private function ga4Trend(Store $store, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = $this->dateRange($this->ga4Base($store), $from, $to)
            ->selectRaw('metric_date date, SUM(total_revenue) revenue, SUM(sessions) sessions')->groupBy('metric_date')->orderBy('metric_date')->get()->keyBy(fn ($row) => (string) $row->date);
        $result = [];
        for ($date = $from; $date->lte($to); $date = $date->addDay()) {
            $row = $rows->get($date->toDateString());
            $result[] = ['date' => $date->toDateString(), 'revenue' => round((float) ($row?->revenue ?? 0), 2), 'sessions' => (int) ($row?->sessions ?? 0)];
        }

        return $result;
    }

    /** @return list<array{channel: string, revenue: float, sessions: int, share: float}> */
    private function ga4Channels(Store $store, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = $this->dateRange($this->ga4Base($store), $from, $to)
            ->selectRaw('channel_group channel, SUM(total_revenue) revenue, SUM(sessions) sessions')->groupBy('channel_group')->orderByDesc('revenue')->get();
        $total = max(0.0, (float) $rows->sum('revenue'));

        return $rows->map(fn ($row): array => [
            'channel' => (string) $row->channel, 'revenue' => round((float) $row->revenue, 2), 'sessions' => (int) $row->sessions,
            'share' => $total > 0 ? round((float) $row->revenue / $total * 100, 2) : 0.0,
        ])->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function landingPages(Store $store, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return $this->dateRange(SeoGa4LandingPageDailyMetric::query()->forOrganization($store->organization_id)->forStore($store->id), $from, $to)
            ->selectRaw('landing_page_hash, MAX(landing_page) landing_page, channel_group, SUM(sessions) sessions, SUM(active_users) active_users, SUM(new_users) new_users')
            ->selectRaw('SUM(engagement_duration) engagement_duration, SUM(key_events) key_events, SUM(total_revenue) revenue')
            ->selectRaw('CASE WHEN SUM(sessions) > 0 THEN SUM(bounce_rate * sessions) / SUM(sessions) ELSE 0 END bounce_rate')
            ->selectRaw('CASE WHEN SUM(sessions) > 0 THEN SUM(session_key_event_rate * sessions) / SUM(sessions) ELSE 0 END event_rate')
            ->groupBy('landing_page_hash', 'channel_group')->orderByDesc('revenue')->limit(200)->get()->map(fn ($row): array => [
                'key' => $row->landing_page_hash.'|'.$row->channel_group, 'page' => (string) $row->landing_page, 'channel' => (string) $row->channel_group,
                'sessions' => (int) $row->sessions, 'active_users' => (int) $row->active_users, 'new_users' => (int) $row->new_users,
                'average_engagement_seconds' => $row->sessions > 0 ? round((float) $row->engagement_duration / (int) $row->sessions, 1) : 0.0,
                'key_events' => round((float) $row->key_events, 1), 'revenue' => round((float) $row->revenue, 2),
                'bounce_rate' => round((float) $row->bounce_rate * 100, 2), 'event_rate' => round((float) $row->event_rate * 100, 2),
            ])->values()->all();
    }

    /** @return array{series: list<array{key: string, page: string, channel: string}>, points: list<array<string, mixed>>} */
    private function landingTrend(Store $store, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $scope = fn () => $this->dateRange(
            SeoGa4LandingPageDailyMetric::query()
                ->forOrganization($store->organization_id)
                ->forStore($store->id),
            $from,
            $to,
        );
        $topRows = $scope()
            ->selectRaw('landing_page_hash, channel_group, MAX(landing_page) landing_page, SUM(total_revenue) revenue')
            ->groupBy('landing_page_hash', 'channel_group')
            ->orderByDesc('revenue')
            ->orderBy('landing_page_hash')
            ->orderBy('channel_group')
            ->limit(5)
            ->get();
        if ($topRows->isEmpty()) {
            return ['series' => [], 'points' => []];
        }

        $rows = $scope()->where(function (Builder $pairs) use ($topRows): void {
            foreach ($topRows as $top) {
                $pairs->orWhere(function (Builder $pair) use ($top): void {
                    $pair->where('landing_page_hash', $top->landing_page_hash)
                        ->where('channel_group', $top->channel_group);
                });
            }
        })->get(['metric_date', 'landing_page_hash', 'channel_group', 'total_revenue']);
        $top = $topRows->map(fn ($row): string => $row->landing_page_hash.'|'.$row->channel_group);
        $series = $topRows->map(fn ($row): array => [
            'key' => $row->landing_page_hash.'|'.$row->channel_group,
            'page' => (string) $row->landing_page,
            'channel' => (string) $row->channel_group,
        ])->values()->all();
        $points = $rows->filter(fn ($row): bool => $top->contains($row->landing_page_hash.'|'.$row->channel_group))
            ->groupBy(fn ($row): string => $row->metric_date->toDateString())->map(function (Collection $dateRows, string $date) use ($top): array {
                $point = ['date' => $date];
                foreach ($top as $key) {
                    $point[$key] = round((float) $dateRows->filter(fn ($row): bool => $row->landing_page_hash.'|'.$row->channel_group === $key)->sum('total_revenue'), 2);
                }

                return $point;
            })->sortKeys()->values()->all();

        return ['series' => $series, 'points' => $points];
    }

    /** @return array<string, array<string, mixed>> */
    private function gscOverviewSegments(
        Store $store,
        CarbonImmutable $from,
        CarbonImmutable $to,
        CarbonImmutable $previousFrom,
        CarbonImmutable $previousTo,
        CarbonImmutable $yearFrom,
        CarbonImmutable $yearTo,
    ): array {
        $labels = [
            'total' => ['总点击', '全站 GSC 搜索表现。'], 'brand' => ['品牌词点击', '匹配 Macfox 品牌词正则的 Query。'],
            'industry' => ['行业词点击', '排除品牌词正则后的 Query。'], 'blog' => ['博客点击', 'Page 包含 /blogs/ 的页面。'],
        ];
        $currentTotals = $this->gscSourceSegmentTotals($store, $from, $to);
        $previousTotals = $this->gscSourceSegmentTotals($store, $previousFrom, $previousTo);
        $yearTotals = $this->gscSourceSegmentTotals($store, $yearFrom, $yearTo);
        $currentTrends = $this->gscSegmentTrends($store, $from, $to);
        $previousTrends = $this->gscSegmentTrends($store, $previousFrom, $previousTo);

        return collect(['total', 'brand', 'industry', 'blog'])->mapWithKeys(function (string $segment) use ($labels, $currentTotals, $previousTotals, $yearTotals, $currentTrends, $previousTrends): array {
            $current = $currentTotals[$segment];
            $previous = $previousTotals[$segment];
            $year = $yearTotals[$segment];

            return [$segment => [
                'label' => $labels[$segment][0], 'description' => $labels[$segment][1],
                'current' => $current, 'previous' => $previous, 'year' => $year,
                'deltas' => $this->summaryDeltas($current, $previous), 'year_deltas' => $this->summaryDeltas($current, $year),
                'trend' => $currentTrends[$segment] ?? [], 'previous_trend' => $previousTrends[$segment] ?? [],
                'queries' => [], 'pages' => [],
            ]];
        })->all();
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function gscSegmentTrends(Store $store, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = $this->dateRange(
            SeoGscDailyMetric::query()->forOrganization($store->organization_id)->forStore($store->id)
                ->whereIn('segment', ['total', 'brand', 'industry', 'blog']),
            $from,
            $to,
        )->orderBy('metric_date')->get();

        return $rows->groupBy('segment')->map(fn (Collection $segmentRows): array => $segmentRows->map(fn ($row): array => [
            'date' => $row->metric_date->toDateString(),
            'clicks' => (int) $row->clicks,
            'impressions' => (int) $row->impressions,
            'ctr' => $row->impressions > 0 ? round((int) $row->clicks / (int) $row->impressions * 100, 2) : 0.0,
            'position' => round((float) $row->average_position, 2),
        ])->values()->all())->all();
    }

    /** @return array{clicks: int, impressions: int, ctr: float, position: float} */
    private function gscSummary(Store $store, string $segment, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $row = $this->dateRange($this->gscBase($store, $segment), $from, $to)
            ->selectRaw('COALESCE(SUM(clicks), 0) clicks, COALESCE(SUM(impressions), 0) impressions')
            ->selectRaw('CASE WHEN SUM(impressions) > 0 THEN SUM(average_position * impressions) / SUM(impressions) ELSE 0 END position')->first();
        $clicks = (int) ($row?->clicks ?? 0);
        $impressions = (int) ($row?->impressions ?? 0);

        return ['clicks' => $clicks, 'impressions' => $impressions, 'ctr' => $impressions > 0 ? round($clicks / $impressions * 100, 2) : 0.0, 'position' => round((float) ($row?->position ?? 0), 2)];
    }

    /** @return list<array<string, mixed>> */
    private function gscTrend(Store $store, string $segment, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return $this->dateRange($this->gscBase($store, $segment), $from, $to)->orderBy('metric_date')->get()
            ->map(fn ($row): array => [
                'date' => $row->metric_date->toDateString(), 'clicks' => (int) $row->clicks, 'impressions' => (int) $row->impressions,
                'ctr' => $row->impressions > 0 ? round((int) $row->clicks / (int) $row->impressions * 100, 2) : 0.0, 'position' => round((float) $row->average_position, 2),
            ])->values()->all();
    }

    /** @param list<int> $allowed @return array{int, int} */
    private function pageFilters(array $filters, array $allowed, int $default): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $requested = (int) ($filters['per_page'] ?? $default);

        return [$page, in_array($requested, $allowed, true) ? $requested : $default];
    }

    /** @return array{current_page: int, per_page: int, total: int, last_page: int, from: int, to: int} */
    private function pagination(int $page, int $perPage, int $total): array
    {
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);

        return [
            'current_page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'last_page' => $lastPage,
            'from' => $total === 0 ? 0 : (($page - 1) * $perPage) + 1,
            'to' => min($page * $perPage, $total),
        ];
    }

    /** @param list<string> $segments @param list<string> $hashes */
    private function gscDimensionAggregate(
        Builder $query,
        Store $store,
        string $hashColumn,
        string $labelColumn,
        array $segments,
        CarbonImmutable $from,
        CarbonImmutable $to,
        string $search = '',
        array $hashes = [],
        string $requiredLabelContains = '',
    ): Builder {
        $query = $this->dateRange(
            $query->forOrganization($store->organization_id)->forStore($store->id)->whereIn('segment', $segments),
            $from,
            $to,
        );
        if ($search !== '') {
            $query->where($labelColumn, 'like', '%'.$search.'%');
        }
        if ($requiredLabelContains !== '') {
            $query->where($labelColumn, 'like', '%'.$requiredLabelContains.'%');
        }
        if ($hashes !== []) {
            $query->whereIn($hashColumn, $hashes);
        }

        return $query
            ->selectRaw("{$hashColumn} hash, MAX({$labelColumn}) label, SUM(clicks) clicks, SUM(impressions) impressions")
            ->selectRaw('CASE WHEN SUM(impressions) > 0 THEN SUM(clicks) * 100.0 / SUM(impressions) ELSE 0 END ctr')
            ->selectRaw('CASE WHEN SUM(impressions) > 0 THEN SUM(average_position * impressions) / SUM(impressions) ELSE 0 END position')
            ->groupBy($hashColumn);
    }

    /** @param list<string> $hashes */
    private function gscBreakdownAggregate(
        Store $store,
        CarbonImmutable $from,
        CarbonImmutable $to,
        string $searchType,
        string $dimension,
        string $search = '',
        array $hashes = [],
    ): Builder {
        $query = $this->dateRange(
            SeoGscBreakdownDailyMetric::query()->forOrganization($store->organization_id)->forStore($store->id)
                ->where('search_type', $searchType)->where('dimension', $dimension),
            $from,
            $to,
        );
        if ($search !== '') {
            $query->where('value', 'like', '%'.$search.'%');
        }
        if ($hashes !== []) {
            $query->whereIn('value_hash', $hashes);
        }

        return $query
            ->selectRaw('value_hash hash, MAX(value) label, SUM(clicks) clicks, SUM(impressions) impressions')
            ->selectRaw('CASE WHEN SUM(impressions) > 0 THEN SUM(clicks) * 100.0 / SUM(impressions) ELSE 0 END ctr')
            ->selectRaw('CASE WHEN SUM(impressions) > 0 THEN SUM(average_position * impressions) / SUM(impressions) ELSE 0 END position')
            ->groupBy('value_hash');
    }

    /** @param list<string> $segments */
    private function gscDimensionCount(
        Builder $query,
        Store $store,
        string $hashColumn,
        string $labelColumn,
        array $segments,
        CarbonImmutable $from,
        CarbonImmutable $to,
        string $search = '',
        string $requiredLabelContains = '',
    ): int {
        $query = $this->dateRange(
            $query->forOrganization($store->organization_id)->forStore($store->id)->whereIn('segment', $segments),
            $from,
            $to,
        );
        if ($search !== '') {
            $query->where($labelColumn, 'like', '%'.$search.'%');
        }
        if ($requiredLabelContains !== '') {
            $query->where($labelColumn, 'like', '%'.$requiredLabelContains.'%');
        }

        return (int) $query->distinct()->count($hashColumn);
    }

    private function gscBreakdownCount(
        Store $store,
        CarbonImmutable $from,
        CarbonImmutable $to,
        string $searchType,
        string $dimension,
        string $search = '',
    ): int {
        $query = $this->dateRange(
            SeoGscBreakdownDailyMetric::query()->forOrganization($store->organization_id)->forStore($store->id)
                ->where('search_type', $searchType)->where('dimension', $dimension),
            $from,
            $to,
        );
        if ($search !== '') {
            $query->where('value', 'like', '%'.$search.'%');
        }

        return (int) $query->distinct()->count('value_hash');
    }

    /** @return array{clicks: int, impressions: int, ctr: float, position: float} */
    private function gscSearchTypeSummary(Store $store, string $searchType, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $row = $this->dateRange(
            SeoGscSearchTypeDailyMetric::query()->forOrganization($store->organization_id)->forStore($store->id)->where('search_type', $searchType),
            $from,
            $to,
        )->selectRaw('SUM(clicks) clicks, SUM(impressions) impressions')
            ->selectRaw('CASE WHEN SUM(impressions) > 0 THEN SUM(average_position * impressions) / SUM(impressions) ELSE 0 END position')->first();
        $clicks = (int) ($row?->clicks ?? 0);
        $impressions = (int) ($row?->impressions ?? 0);

        return ['clicks' => $clicks, 'impressions' => $impressions, 'ctr' => $impressions > 0 ? round($clicks / $impressions * 100, 2) : 0.0, 'position' => round((float) ($row?->position ?? 0), 2)];
    }

    /** @return list<array<string, int|float|string>> */
    private function gscSearchTypeTrend(Store $store, string $searchType, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return $this->dateRange(
            SeoGscSearchTypeDailyMetric::query()->forOrganization($store->organization_id)->forStore($store->id)->where('search_type', $searchType),
            $from,
            $to,
        )->orderBy('metric_date')->get()->map(function ($row): array {
            $clicks = (int) $row->clicks;
            $impressions = (int) $row->impressions;

            return ['date' => $row->metric_date->toDateString(), 'clicks' => $clicks, 'impressions' => $impressions,
                'ctr' => $impressions > 0 ? round($clicks / $impressions * 100, 2) : 0.0, 'position' => round((float) $row->average_position, 2)];
        })->values()->all();
    }

    /** @return array<string, mixed> */
    private function gscDetailRow(object $row, ?object $previous): array
    {
        $current = [
            'clicks' => (int) $row->clicks,
            'impressions' => (int) $row->impressions,
            'ctr' => round((float) $row->ctr, 2),
            'position' => round((float) $row->position, 2),
        ];
        $previousValues = [
            'clicks' => (int) ($previous?->clicks ?? 0),
            'impressions' => (int) ($previous?->impressions ?? 0),
            'ctr' => round((float) ($previous?->ctr ?? 0), 2),
            'position' => round((float) ($previous?->position ?? 0), 2),
        ];

        return [
            'hash' => (string) $row->hash,
            'label' => (string) $row->label,
            ...$current,
            'previous' => $previousValues,
            'difference' => [
                'clicks' => $current['clicks'] - $previousValues['clicks'],
                'impressions' => $current['impressions'] - $previousValues['impressions'],
                'ctr' => round($current['ctr'] - $previousValues['ctr'], 2),
                'position' => round($current['position'] - $previousValues['position'], 2),
            ],
            'status' => $previous === null ? 'new' : 'existing',
        ];
    }

    private function ga4LandingAggregate(
        Store $store,
        CarbonImmutable $from,
        CarbonImmutable $to,
        string $search = '',
        string $channel = '',
        string $pageType = '',
    ): Builder {
        $query = $this->dateRange(
            SeoGa4LandingPageDailyMetric::query()->forOrganization($store->organization_id)->forStore($store->id),
            $from,
            $to,
        );
        if ($search !== '') {
            $query->where('landing_page', 'like', '%'.$search.'%');
        }
        if ($channel !== '') {
            $query->where('channel_group', $channel);
        }
        $this->applyPageType($query, $pageType);

        return $query
            ->selectRaw('landing_page_hash, MAX(landing_page) landing_page, channel_group')
            ->selectRaw('SUM(sessions) sessions, SUM(active_users) active_users, SUM(new_users) new_users')
            ->selectRaw('SUM(engagement_duration) engagement_duration, SUM(key_events) key_events, SUM(total_revenue) revenue')
            ->selectRaw('CASE WHEN SUM(sessions) > 0 THEN SUM(engagement_duration) / SUM(sessions) ELSE 0 END average_engagement_seconds')
            ->selectRaw('CASE WHEN SUM(sessions) > 0 THEN SUM(bounce_rate * sessions) / SUM(sessions) * 100 ELSE 0 END bounce_rate')
            ->selectRaw('CASE WHEN SUM(sessions) > 0 THEN SUM(session_key_event_rate * sessions) / SUM(sessions) * 100 ELSE 0 END event_rate')
            ->groupBy('landing_page_hash', 'channel_group');
    }

    private function applyPageType(Builder $query, string $pageType): void
    {
        $patterns = [
            'product' => '/products/%',
            'collection' => '/collections/%',
            'blog' => '/blogs/%',
            'page' => '/pages/%',
        ];
        if ($pageType === 'home') {
            $query->whereIn('landing_page', ['/', '']);
        } elseif (isset($patterns[$pageType])) {
            $query->where('landing_page', 'like', $patterns[$pageType]);
        } elseif ($pageType === 'other') {
            $query->whereNotIn('landing_page', ['/', ''])->where(function (Builder $other) use ($patterns): void {
                foreach ($patterns as $pattern) {
                    $other->where('landing_page', 'not like', $pattern);
                }
            });
        }
    }

    private function pageType(string $page): string
    {
        if ($page === '' || $page === '/') {
            return 'home';
        }
        foreach (['product' => '/products/', 'collection' => '/collections/', 'blog' => '/blogs/', 'page' => '/pages/'] as $type => $needle) {
            if (str_contains($page, $needle)) {
                return $type;
            }
        }

        return 'other';
    }

    /** @return array<string, mixed> */
    private function ga4DetailRow(object $row, ?object $previous): array
    {
        $metrics = ['revenue', 'sessions', 'active_users', 'new_users', 'key_events', 'average_engagement_seconds', 'bounce_rate', 'event_rate'];
        $current = [
            'revenue' => round((float) $row->revenue, 2),
            'sessions' => (int) $row->sessions,
            'active_users' => (int) $row->active_users,
            'new_users' => (int) $row->new_users,
            'key_events' => round((float) $row->key_events, 1),
            'average_engagement_seconds' => round((float) $row->average_engagement_seconds, 1),
            'bounce_rate' => round((float) $row->bounce_rate, 2),
            'event_rate' => round((float) $row->event_rate, 2),
        ];
        $previousValues = [
            'revenue' => round((float) ($previous?->revenue ?? 0), 2),
            'sessions' => (int) ($previous?->sessions ?? 0),
            'active_users' => (int) ($previous?->active_users ?? 0),
            'new_users' => (int) ($previous?->new_users ?? 0),
            'key_events' => round((float) ($previous?->key_events ?? 0), 1),
            'average_engagement_seconds' => round((float) ($previous?->average_engagement_seconds ?? 0), 1),
            'bounce_rate' => round((float) ($previous?->bounce_rate ?? 0), 2),
            'event_rate' => round((float) ($previous?->event_rate ?? 0), 2),
        ];
        $changes = collect($metrics)->mapWithKeys(function (string $metric) use ($current, $previousValues): array {
            $before = (float) $previousValues[$metric];

            return [$metric => $before !== 0.0 ? round(((float) $current[$metric] - $before) / abs($before) * 100, 1) : null];
        })->all();
        $page = (string) $row->landing_page;

        return [
            'key' => (string) $row->landing_page_hash.'|'.(string) $row->channel_group,
            'page' => $page,
            'page_type' => $this->pageType($page),
            'channel' => (string) $row->channel_group,
            ...$current,
            'previous' => $previousValues,
            'change_percent' => $changes,
            'status' => $previous === null ? 'new' : 'existing',
        ];
    }

    private function anonymousSegment(array $total, array $brand, array $industry): array
    {
        $summary = fn (string $period): array => $this->anonymousSummary($total[$period], $brand[$period], $industry[$period]);
        $current = $summary('current');
        $previous = $summary('previous');
        $year = $summary('year');
        $brandDaily = collect($brand['trend'])->keyBy('date');
        $industryDaily = collect($industry['trend'])->keyBy('date');
        $trend = collect($total['trend'])->map(fn (array $row): array => [
            'date' => $row['date'], 'clicks' => max(0, $row['clicks'] - (int) data_get($brandDaily->get($row['date']), 'clicks', 0) - (int) data_get($industryDaily->get($row['date']), 'clicks', 0)),
            'impressions' => 0, 'ctr' => 0.0, 'position' => 0.0,
        ])->values()->all();
        $previousRatio = $previous['ratio'] / 100;
        $pages = collect($total['pages'])->map(function (array $row) use ($current, $previousRatio): array {
            $currentClicks = (int) round($row['clicks'] * ($current['ratio'] / 100));
            $previousClicks = (int) round((int) data_get($row, 'previous.clicks', 0) * $previousRatio);

            return [...$row, 'clicks' => $currentClicks, 'ctr' => 0.0, 'position' => 0.0,
                'previous' => [...$row['previous'], 'clicks' => $previousClicks, 'ctr' => 0.0, 'position' => 0.0],
                'difference' => ['clicks' => $currentClicks - $previousClicks, 'impressions' => 0, 'ctr' => 0.0, 'position' => 0.0]];
        })->values()->all();

        return [
            'label' => '匿名化查询', 'description' => 'GSC 总点击减去品牌词和行业词可见 Query 点击后的估算。',
            'current' => $current, 'previous' => $previous, 'year' => $year,
            'deltas' => $this->summaryDeltas($current, $previous), 'year_deltas' => $this->summaryDeltas($current, $year),
            'trend' => $trend, 'previous_trend' => [], 'queries' => [], 'pages' => $pages,
        ];
    }

    private function anonymousSummary(array $total, array $brand, array $industry): array
    {
        $visible = $brand['clicks'] + $industry['clicks'];
        $clicks = max(0, $total['clicks'] - $visible);

        return ['clicks' => $clicks, 'impressions' => 0, 'ctr' => 0.0, 'position' => 0.0, 'visible_clicks' => $visible,
            'total_clicks' => $total['clicks'], 'ratio' => $total['clicks'] > 0 ? round($clicks / $total['clicks'] * 100, 2) : 0.0];
    }

    /** @param array<string, int|float> $current @param array<string, int|float> $previous */
    private function summaryDeltas(array $current, array $previous): array
    {
        return collect($current)->filter(fn ($value, string $key): bool => is_numeric($value) && array_key_exists($key, $previous))
            ->mapWithKeys(fn ($value, string $key): array => [$key => (float) $previous[$key] !== 0.0 ? round(((float) $value - (float) $previous[$key]) / abs((float) $previous[$key]) * 100, 1) : null])->all();
    }

    private function ga4Base(Store $store): Builder
    {
        return SeoGa4ChannelDailyMetric::query()->forOrganization($store->organization_id)->forStore($store->id);
    }

    private function gscBase(Store $store, string $segment): Builder
    {
        return SeoGscDailyMetric::query()->forOrganization($store->organization_id)->forStore($store->id)->where('segment', $segment);
    }

    private function dateRange(Builder $query, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return $query->whereBetween('metric_date', [
            $from->startOfDay()->toDateTimeString(),
            $to->endOfDay()->toDateTimeString(),
        ]);
    }
}
