<?php

namespace App\Services\NaturalTraffic;

use App\Models\BrandSocialDailyReview;
use App\Models\BrandSocialPostState;
use App\Models\BrandSocialWeeklyReport;
use App\Models\Customer;
use App\Models\FeishuBitableTable;
use App\Models\Store;
use App\Services\StoreBusinessCredentialService;
use App\Services\YouTubeAnalytics\YouTubeAnalyticsSyncService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class NaturalTrafficDashboardService
{
    private const INSTAGRAM_OUTLIER_THRESHOLD = 100000;

    private const BRAND_POST_PER_PAGE_OPTIONS = [10, 20, 50, 100];

    private const BRAND_POST_PLATFORMS = ['Instagram', 'Facebook', 'YouTube'];

    public function __construct(private StoreBusinessCredentialService $credentials) {}

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function forChannel(Store $store, string $channel, array $filters = []): array
    {
        $period = $this->period($store, $filters);

        return match ($channel) {
            'brand-media' => $this->brandMedia($store, $period, $this->brandPostFilters($filters), $filters),
            'influencer-operations' => $this->influencerOperations($store, $period),
            'edm-email' => $this->edm($store, $period),
            'affiliate-marketing' => $this->affiliate($store, $period, trim((string) ($filters['affiliate'] ?? ''))),
            default => [],
        };
    }

    /** @param array<string, mixed> $period @param array<string, mixed> $postFilters @param array<string, mixed> $filters @return array<string, mixed> */
    private function brandMedia(Store $store, array $period, array $postFilters, array $filters): array
    {
        $period = $this->brandDefaultPeriod($store, $period);
        $source = $this->source($store, [
            'natural-traffic:social',
            BrandSocialCsvImportService::SOURCE_SECTION,
            YouTubeAnalyticsSyncService::SOURCE_SECTION,
        ]);
        $postStates = BrandSocialPostState::query()
            ->forOrganization((int) $store->organization_id)
            ->forStore((int) $store->id)
            ->get()
            ->keyBy(fn (BrandSocialPostState $state): string => $this->brandSourceKey(
                $state->source_section,
                $state->source_table_key,
                $state->source_record_id,
            ));
        $all = collect($source['records'])->map(function (array $row) use ($postStates): array {
            $social = $this->socialRow($row);
            $state = $postStates->get($this->brandSourceKey(
                $social['source_section'],
                $social['source_table_key'],
                $social['record_id'],
            ));

            return [
                ...$social,
                'is_hidden' => (bool) ($state?->is_hidden ?? false),
                'visibility_status' => ($state?->is_hidden ?? false) ? '已隐藏' : '可见',
            ];
        });
        $visible = $all->reject(fn (array $row): bool => $row['is_hidden'])->values();
        $period = $this->latestAvailablePeriod($period, $visible, ['views', 'likes', 'comments', 'shares']);
        $periodAll = $this->within($all, $period['date_from'], $period['date_to']);
        $current = $this->within($visible, $period['date_from'], $period['date_to']);
        $previous = $this->within($visible, $period['compare_from'], $period['compare_to']);
        $metrics = ['posts', 'views', 'likes', 'comments', 'shares'];
        $totals = $this->sums($current, $metrics);
        $previousTotals = $this->sums($previous, $metrics);
        $platforms = collect(self::BRAND_POST_PLATFORMS)->map(function (string $platform) use ($visible, $current, $metrics): array {
            $rows = $current->where('platform', $platform);
            $totals = $this->sums($rows, $metrics);
            $engagement = $totals['likes'] + $totals['comments'] + $totals['shares'];

            return [
                'platform' => $platform,
                'available' => $visible->contains(fn (array $row): bool => $row['platform'] === $platform),
                ...$totals,
                'reach' => round($rows->sum('reach'), 2),
                'clicks' => round($rows->sum('clicks'), 2),
                'engagement_rate' => $totals['views'] > 0 ? round($engagement / $totals['views'] * 100, 2) : 0,
            ];
        })->all();
        $trends = $this->trend($current, $metrics, 'date');
        $platformTrends = $current->filter(fn (array $row): bool => filled($row['date']))
            ->groupBy('date')
            ->map(function (Collection $rows, string $date) use ($metrics): array {
                $point = ['date' => $date];
                foreach (self::BRAND_POST_PLATFORMS as $platform) {
                    $slug = mb_strtolower($platform);
                    $platformTotals = $this->sums($rows->where('platform', $platform), $metrics);
                    foreach ($metrics as $metric) {
                        $point["{$slug}_{$metric}"] = $platformTotals[$metric];
                    }
                }

                return $point;
            })->sortKeys()->values()->all();
        $daily = $current->filter(fn (array $row): bool => filled($row['date']))
            ->groupBy('date')->map(function (Collection $rows, string $date) use ($metrics): array {
                $dailyTotals = $this->sums($rows, $metrics);

                return [
                    'date' => $date,
                    ...$dailyTotals,
                    'engagements' => $dailyTotals['likes'] + $dailyTotals['comments'] + $dailyTotals['shares'],
                    'engagement_rate' => $dailyTotals['views'] > 0
                        ? round(($dailyTotals['likes'] + $dailyTotals['comments'] + $dailyTotals['shares']) / $dailyTotals['views'] * 100, 2)
                        : 0,
                ];
            })->sortKeys()->values()->all();
        $weeklyReportRecords = BrandSocialWeeklyReport::query()
            ->forOrganization((int) $store->organization_id)
            ->forStore((int) $store->id)
            ->orderBy('week_start')
            ->get()
            ->keyBy(fn (BrandSocialWeeklyReport $report): string => $report->week_start
                ->startOfWeek(CarbonInterface::SUNDAY)
                ->toDateString());
        $weeklyGroups = $visible
            ->filter(fn (array $row): bool => filled($row['date']) && $row['platform'] === 'Instagram')
            ->groupBy(fn (array $row): string => CarbonImmutable::parse($row['date'])
                ->startOfWeek(CarbonInterface::SUNDAY)
                ->toDateString());
        $availableWeeks = $weeklyGroups->keys()->merge($weeklyReportRecords->keys())->unique();
        $requestedWeek = trim((string) ($filters['weekly_week'] ?? ''));
        $selectedWeek = $availableWeeks->contains($requestedWeek) ? $requestedWeek : $availableWeeks->sortDesc()->first();
        $weeklyReportMap = $weeklyGroups
            ->map(fn (Collection $rows, string $week): array => $this->brandWeeklyReport($rows, $week, $week === $selectedWeek));
        foreach ($weeklyReportRecords as $week => $record) {
            if (! $weeklyReportMap->has($week)) {
                $weeklyReportMap->put($week, $this->brandWeeklyReport(collect(), $week, $week === $selectedWeek));
            }
        }
        $weeklyReportMap = $weeklyReportMap->map(function (array $report, string $week) use ($weeklyReportRecords): array {
            $record = $weeklyReportRecords->get($week);

            return [
                ...$report,
                'uuid' => $record?->uuid,
                'title' => $record?->title ?? $report['label'],
                'status' => $record?->status ?? 'generated',
                'summary' => $record?->summary,
                'published_at' => $record?->published_at?->toIso8601String(),
            ];
        })->sortKeys();
        $weeklyReports = $weeklyReportMap->values();
        $weekly = $weeklyReports->map(fn (array $report): array => collect($report)
            ->except(['content_types', 'content_efficiency', 'scatter', 'top_views', 'top_engagement', 'excluded_content', 'included_content', 'post_performance', 'summary_items'])->all())->all();
        $selectedWeeklyReport = is_string($selectedWeek) ? $weeklyReportMap->get($selectedWeek) : null;
        $previousWeek = is_string($selectedWeek) ? CarbonImmutable::parse($selectedWeek)->subWeek()->toDateString() : null;
        $previousWeeklyReport = is_string($previousWeek)
            ? ($weeklyReportMap->get($previousWeek) ?? $this->brandWeeklyReport(collect(), $previousWeek, false))
            : null;
        $weeklyComparison = $selectedWeeklyReport
            ? collect(['included_views', 'included_interactions', 'average_views', 'included_posts'])
                ->mapWithKeys(fn (string $metric): array => [$metric => [
                    'current' => $selectedWeeklyReport[$metric],
                    'previous' => $previousWeeklyReport[$metric] ?? 0,
                    'change' => $this->change($selectedWeeklyReport[$metric], $previousWeeklyReport[$metric] ?? 0),
                ]])->all()
            : [];
        $allPosts = $periodAll
            ->filter(fn (array $row): bool => $row['title'] !== '' || $row['permalink'] !== '')
            ->values();
        $postTypes = $allPosts->pluck('post_type')->filter()->unique()->sort()->values()->all();
        $postFilters['post_type'] = collect($postTypes)
            ->first(fn (string $postType): bool => mb_strtolower($postType) === mb_strtolower($postFilters['post_type'])) ?? '';
        $filteredPosts = $this->filterBrandPosts($allPosts, $postFilters)
            ->sort(fn (array $left, array $right): int => $this->compareBrandPosts(
                $left,
                $right,
                $postFilters['sort'],
                $postFilters['direction'],
            ))
            ->values();
        $postsTotal = $filteredPosts->count();
        $postsLastPage = max(1, (int) ceil($postsTotal / $postFilters['per_page']));
        $postsCurrentPage = min($postFilters['page'], $postsLastPage);
        $posts = $filteredPosts
            ->slice(($postsCurrentPage - 1) * $postFilters['per_page'], $postFilters['per_page'])
            ->values()
            ->all();
        $postFilters['page'] = $postsCurrentPage;
        $availablePlatforms = $visible->pluck('platform')->filter()->intersect(self::BRAND_POST_PLATFORMS)->unique()->values()->all();
        $expectedPlatforms = self::BRAND_POST_PLATFORMS;
        $engagements = $totals['likes'] + $totals['comments'] + $totals['shares'];
        $excluded = $current->filter(fn (array $row): bool => $row['excluded_from_aggregates']);
        $dailyReviews = BrandSocialDailyReview::query()
            ->forOrganization((int) $store->organization_id)
            ->forStore((int) $store->id)
            ->whereBetween('review_date', [$period['date_from'], $period['date_to']])
            ->orderByDesc('review_date')
            ->get()
            ->map(fn (BrandSocialDailyReview $review): array => [
                'uuid' => $review->uuid,
                'review_date' => $review->review_date->toDateString(),
                'status' => $review->status,
                'core_data' => $review->core_data,
                'top_content' => $review->top_content,
                'low_content' => $review->low_content,
                'recommendations' => $review->recommendations,
                'published_at' => $review->published_at?->toIso8601String(),
            ])->all();
        $platformLeader = collect($platforms)->sortByDesc('views')->first();

        return [
            'schema' => 'natural-traffic-brand-media-v3',
            'title' => '品牌官媒',
            'description' => 'Instagram / Facebook 手动导入、YouTube 官方 API 同步，以及统一口径的每日复盘与周度汇总。',
            'tabs' => [
                ['key' => 'platforms', 'label' => '平台拆解'],
                ['key' => 'daily', 'label' => '每日复盘'],
                ['key' => 'weekly', 'label' => '周报'],
                ['key' => 'ai', 'label' => 'AI 分析'],
            ],
            'filters' => $period,
            'source' => $this->sourceSummary($source),
            'kpis' => collect([
                ['key' => 'posts', 'label' => '帖子数', 'format' => 'number'],
                ['key' => 'views', 'label' => '浏览量', 'format' => 'compact'],
                ['key' => 'likes', 'label' => '点赞', 'format' => 'compact'],
                ['key' => 'comments', 'label' => '评论', 'format' => 'compact'],
                ['key' => 'shares', 'label' => '分享', 'format' => 'compact'],
            ])->map(fn (array $definition): array => [
                ...$definition,
                'value' => $totals[$definition['key']],
                'previous' => $previousTotals[$definition['key']],
                'change' => $this->change($totals[$definition['key']], $previousTotals[$definition['key']]),
            ])->all(),
            'platforms' => $platforms,
            'platform_coverage' => [
                'available' => $availablePlatforms,
                'missing' => collect($expectedPlatforms)->diff($availablePlatforms)->values()->all(),
            ],
            'platform_sources' => $this->brandPlatformSources($store, $all),
            'exclusion_policy' => [
                'platform' => 'Instagram',
                'threshold' => self::INSTAGRAM_OUTLIER_THRESHOLD,
                'rule' => 'Reels / 视频按播放或浏览量；图片 / 轮播按曝光量，源文件无曝光量时按覆盖人数。',
                'behavior' => '原始明细与平台拆解继续保留，仅在周报总量、周报均值和周报排行中单独排除。',
            ],
            'exclusion_summary' => [
                'posts' => round($excluded->sum('posts'), 2),
                'views' => round($excluded->sum('views'), 2),
                'content' => $excluded->sortByDesc('exclusion_value')->take(100)->values()->all(),
            ],
            'funnel' => [
                ['key' => 'views', 'label' => '播放 / 浏览', 'value' => $totals['views']],
                ['key' => 'reach', 'label' => '触达', 'value' => round($current->sum('reach'), 2)],
                ['key' => 'engagements', 'label' => '互动', 'value' => $engagements],
                ['key' => 'clicks', 'label' => '点击', 'value' => round($current->sum('clicks'), 2)],
            ],
            'trends' => $trends,
            'platform_trends' => $platformTrends,
            'daily' => $daily,
            'weekly' => $weekly,
            'weekly_reports' => $weekly,
            'weekly_options' => $weeklyReports->sortByDesc('week')->map(fn (array $report): array => [
                'value' => $report['week'],
                'label' => $report['label'],
                'posts' => $report['all_posts'],
            ])->values()->all(),
            'selected_week' => $selectedWeek,
            'selected_weekly_report' => $selectedWeeklyReport,
            'weekly_comparison' => $weeklyComparison,
            'daily_review' => [
                'posting_days' => $current->pluck('date')->filter()->unique()->count(),
                'engagements' => $totals['likes'] + $totals['comments'] + $totals['shares'],
                'reach' => round($current->sum('reach'), 2),
                'clicks' => round($current->sum('clicks'), 2),
                'top_post' => $current->sortByDesc('views')->first(),
                'top_engagement_post' => $current->sortByDesc(fn (array $row): float => $row['likes'] + $row['comments'] + $row['shares'])->first(),
            ],
            'daily_reviews' => $dailyReviews,
            'ai_insights' => [
                'platform_leader' => $platformLeader,
                'top_post' => $current->sortByDesc('views')->first(),
                'top_engagement_post' => $current->sortByDesc(fn (array $row): float => $row['likes'] + $row['comments'] + $row['shares'])->first(),
                'views_change' => $this->change($totals['views'], $previousTotals['views']),
                'engagements' => $engagements,
                'generated_from' => 'current-project-mysql',
            ],
            'post_filters' => $postFilters,
            'post_filter_options' => [
                'platforms' => self::BRAND_POST_PLATFORMS,
                'post_types' => $postTypes,
                'origins' => [
                    ['value' => 'official', 'label' => '官媒内容'],
                    ['value' => 'collaboration', 'label' => '合作内容'],
                ],
                'visibility_statuses' => [
                    ['value' => 'visible', 'label' => '可见帖子'],
                    ['value' => 'hidden', 'label' => '已隐藏帖子'],
                    ['value' => 'all', 'label' => '全部帖子'],
                ],
                'aggregation_statuses' => [
                    ['value' => 'included', 'label' => '已纳入'],
                    ['value' => 'excluded', 'label' => '异常爆款，已排除'],
                ],
            ],
            'posts_pagination' => [
                'current_page' => $postsCurrentPage,
                'last_page' => $postsLastPage,
                'per_page' => $postFilters['per_page'],
                'total' => $postsTotal,
                'from' => $postsTotal === 0 ? null : (($postsCurrentPage - 1) * $postFilters['per_page']) + 1,
                'to' => $postsTotal === 0 ? null : min($postsCurrentPage * $postFilters['per_page'], $postsTotal),
            ],
            'posts' => $posts,
            'hidden_posts_count' => $periodAll->where('is_hidden', true)->count(),
            'columns' => $this->columns($source['records']),
            'raw_rows' => $this->sanitizedRows($source['records'], 1000),
        ];
    }

    /** @param array<string, mixed> $filters @return array{keyword: string, platform: string, post_type: string, aggregation_status: string, visibility: string, origin: string, sort: string, direction: string, page: int, per_page: int} */
    private function brandPostFilters(array $filters): array
    {
        $platformInput = mb_strtolower(trim((string) ($filters['content_platform'] ?? '')));
        $platform = collect(self::BRAND_POST_PLATFORMS)
            ->first(fn (string $candidate): bool => mb_strtolower($candidate) === $platformInput) ?? '';
        $status = mb_strtolower(trim((string) ($filters['content_status'] ?? '')));
        $visibility = mb_strtolower(trim((string) ($filters['content_visibility'] ?? 'visible')));
        $origin = mb_strtolower(trim((string) ($filters['content_origin'] ?? '')));
        $sort = mb_strtolower(trim((string) ($filters['content_sort'] ?? 'date')));
        $direction = mb_strtolower(trim((string) ($filters['content_direction'] ?? 'desc')));
        $page = (int) ($filters['content_page'] ?? 1);
        $perPage = (int) ($filters['content_per_page'] ?? 20);

        return [
            'keyword' => mb_substr(trim((string) ($filters['content_keyword'] ?? '')), 0, 100),
            'platform' => $platform,
            'post_type' => mb_substr(trim((string) ($filters['content_type'] ?? '')), 0, 100),
            'aggregation_status' => in_array($status, ['included', 'excluded'], true) ? $status : '',
            'visibility' => in_array($visibility, ['visible', 'hidden', 'all'], true) ? $visibility : 'visible',
            'origin' => in_array($origin, ['official', 'collaboration'], true) ? $origin : '',
            'sort' => in_array($sort, ['date', 'platform', 'views', 'likes', 'comments', 'shares', 'engagement_rate'], true) ? $sort : 'date',
            'direction' => in_array($direction, ['asc', 'desc'], true) ? $direction : 'desc',
            'page' => min(max($page, 1), 10000),
            'per_page' => in_array($perPage, self::BRAND_POST_PER_PAGE_OPTIONS, true) ? $perPage : 20,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $posts
     * @param  array{keyword: string, platform: string, post_type: string, aggregation_status: string, visibility: string, origin: string, sort: string, direction: string, page: int, per_page: int}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function filterBrandPosts(Collection $posts, array $filters): Collection
    {
        return $posts
            ->when($filters['platform'] !== '', fn (Collection $rows): Collection => $rows
                ->filter(fn (array $row): bool => $row['platform'] === $filters['platform']))
            ->when($filters['post_type'] !== '', fn (Collection $rows): Collection => $rows
                ->filter(fn (array $row): bool => mb_strtolower((string) $row['post_type']) === mb_strtolower($filters['post_type'])))
            ->when($filters['aggregation_status'] !== '', fn (Collection $rows): Collection => $rows
                ->filter(fn (array $row): bool => $filters['aggregation_status'] === 'excluded'
                    ? (bool) $row['excluded_from_aggregates']
                    : ! (bool) $row['excluded_from_aggregates']))
            ->when($filters['visibility'] !== 'all', fn (Collection $rows): Collection => $rows
                ->filter(fn (array $row): bool => $filters['visibility'] === 'hidden'
                    ? (bool) $row['is_hidden']
                    : ! (bool) $row['is_hidden']))
            ->when($filters['origin'] !== '', fn (Collection $rows): Collection => $rows
                ->filter(fn (array $row): bool => $row['content_origin'] === $filters['origin']))
            ->when($filters['keyword'] !== '', function (Collection $rows) use ($filters): Collection {
                $keyword = mb_strtolower($filters['keyword']);

                return $rows->filter(function (array $row) use ($keyword): bool {
                    $searchable = implode("\n", [
                        (string) $row['title'],
                        (string) $row['permalink'],
                        (string) $row['record_id'],
                        (string) $row['post_type'],
                    ]);

                    return str_contains(mb_strtolower($searchable), $keyword);
                });
            })
            ->values();
    }

    /** @param array<string, mixed> $left @param array<string, mixed> $right */
    private function compareBrandPosts(array $left, array $right, string $sort, string $direction): int
    {
        $comparison = in_array($sort, ['views', 'likes', 'comments', 'shares', 'engagement_rate'], true)
            ? ((float) ($left[$sort] ?? 0) <=> (float) ($right[$sort] ?? 0))
            : strcmp((string) ($left[$sort] ?? ''), (string) ($right[$sort] ?? ''));
        if ($comparison !== 0) {
            return $direction === 'desc' ? -$comparison : $comparison;
        }

        return [
            (string) ($left['platform'] ?? ''),
            (string) ($left['record_id'] ?? ''),
            (string) ($left['table_name'] ?? ''),
        ] <=> [
            (string) ($right['platform'] ?? ''),
            (string) ($right['record_id'] ?? ''),
            (string) ($right['table_name'] ?? ''),
        ];
    }

    /** @param Collection<int, array<string, mixed>> $rows @return array<string, mixed> */
    private function brandWeeklyReport(Collection $rows, string $week, bool $includeDetails = true): array
    {
        $metrics = ['posts', 'views', 'likes', 'comments', 'shares'];
        $rawTotals = $this->sums($rows, $metrics);
        $included = $rows->reject(fn (array $row): bool => $row['excluded_from_aggregates'])->values();
        $excluded = $rows->filter(fn (array $row): bool => $row['excluded_from_aggregates'])->values();
        $totals = $this->sums($included, $metrics);
        $interactions = $totals['likes'] + $totals['comments'];
        $reportIncluded = $includeDetails ? $included->map(fn (array $row): array => $this->brandReportPost($row)) : collect();
        $reportExcluded = $includeDetails ? $excluded->map(fn (array $row): array => $this->brandReportPost($row)) : collect();
        $weekStart = CarbonImmutable::parse($week)->startOfWeek(CarbonInterface::SUNDAY);
        $weekEnd = $weekStart->endOfWeek(CarbonInterface::SATURDAY);
        $contentTypes = $includeDetails ? $included
            ->groupBy(fn (array $row): string => $row['post_type'] ?: '未分类')
            ->map(function (Collection $items, string $type) use ($totals): array {
                $posts = (float) $items->sum('posts');
                $views = (float) $items->sum('views');
                $interactions = (float) $items->sum(fn (array $row): float => $row['likes'] + $row['comments']);

                return [
                    'type' => $type,
                    'posts' => round($posts, 2),
                    'percentage' => $totals['posts'] > 0 ? round($posts / $totals['posts'] * 100, 2) : 0,
                    'views' => round($views, 2),
                    'interactions' => round($interactions, 2),
                    'average_views' => $posts > 0 ? round($views / $posts, 2) : 0,
                    'average_interactions' => $posts > 0 ? round($interactions / $posts, 2) : 0,
                ];
            })->sortByDesc('posts')->values()->all() : [];
        $postPerformance = $includeDetails ? $included->sortByDesc('views')->values()->map(fn (array $row, int $index): array => [
            'index' => $index + 1,
            'label' => '#'.($index + 1),
            'record_id' => $row['record_id'],
            'title' => $row['title'],
            'platform' => $row['platform'],
            'views' => $row['views'],
            'interactions' => $row['likes'] + $row['comments'],
        ])->all() : [];

        return [
            'week' => $weekStart->toDateString(),
            'week_end' => $weekEnd->toDateString(),
            'label' => $weekStart->format('M j').' – '.$weekEnd->format('M j, Y'),
            ...$totals,
            'all_posts' => $rawTotals['posts'],
            'all_views' => $rawTotals['views'],
            'included_posts' => $totals['posts'],
            'excluded_posts' => round($excluded->sum('posts'), 2),
            'included_views' => $totals['views'],
            'included_interactions' => round($interactions, 2),
            'average_views' => $totals['posts'] > 0 ? round($totals['views'] / $totals['posts'], 2) : 0,
            'content_types' => $contentTypes,
            'content_efficiency' => $contentTypes,
            'scatter' => $includeDetails ? $included->map(fn (array $row): array => [
                'name' => $row['title'] ?: $row['record_id'],
                'platform' => $row['platform'],
                'views' => $row['views'],
                'interactions' => $row['likes'] + $row['comments'],
                'engagement_rate' => $row['views'] > 0 ? round(($row['likes'] + $row['comments']) / $row['views'] * 100, 2) : 0,
            ])->values()->all() : [],
            'top_views' => $includeDetails ? $reportIncluded->sortByDesc('views')->take(5)->values()->all() : [],
            'top_engagement' => $includeDetails ? $reportIncluded->sortByDesc(fn (array $row): float => $row['likes'] + $row['comments'])->take(5)->values()->all() : [],
            'excluded_content' => $includeDetails ? $reportExcluded->sortByDesc('exclusion_value')->values()->all() : [],
            'included_content' => $includeDetails ? $reportIncluded->sortByDesc('views')->values()->all() : [],
            'post_performance' => $postPerformance,
            'summary_items' => $includeDetails ? [
                "本周共发布 {$rawTotals['posts']} 篇内容，计入 {$totals['posts']} 篇",
                "计入口径总浏览 {$totals['views']}，平均每篇 ".($totals['posts'] > 0 ? round($totals['views'] / $totals['posts'], 2) : 0),
                "总互动 {$interactions}（赞 {$totals['likes']} + 评论 {$totals['comments']}）",
                "{$excluded->sum('posts')} 篇超过 10 万的 Instagram 内容已单独列出",
            ] : [],
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function brandReportPost(array $row): array
    {
        return collect($row)->only([
            'record_id', 'platform', 'date', 'account_handle', 'account_name', 'title', 'post_type', 'content_origin', 'content_origin_label',
            'views', 'likes', 'comments', 'shares', 'engagement_rate', 'permalink', 'aggregation_status',
            'exclusion_metric', 'exclusion_value',
        ])->all();
    }

    private function brandSourceKey(string $sourceSection, string $sourceTableKey, string $sourceRecordId): string
    {
        return $sourceSection."\n".$sourceTableKey."\n".$sourceRecordId;
    }

    /** @param array<string, mixed> $period @return array<string, mixed> */
    private function brandDefaultPeriod(Store $store, array $period): array
    {
        if (! ($period['default_range'] ?? false)) {
            return $period;
        }

        $to = CarbonImmutable::now($store->timezone ?: 'UTC')->startOfDay();
        $from = $to->subDays(6);
        $compareTo = $from->subDay();
        $compareFrom = $compareTo->subDays(6);

        return [
            ...$period,
            'date_from' => $from->toDateString(),
            'date_to' => $to->toDateString(),
            'compare_from' => $period['comparison'] === 'none' ? '' : $compareFrom->toDateString(),
            'compare_to' => $period['comparison'] === 'none' ? '' : $compareTo->toDateString(),
        ];
    }

    /** @param array<string, mixed> $period @return array<string, mixed> */
    private function influencerOperations(Store $store, array $period): array
    {
        $source = $this->source($store, ['natural-traffic:kol']);
        $states = \App\Models\InfluencerRecordState::query()
            ->where('organization_id', $store->organization_id)->where('store_id', $store->id)->get()
            ->keyBy(fn ($state) => json_encode([$state->source_table_key, $state->source_record_id]));
        $records = collect($source['records'])->map(function (array $row) use ($states): array {
            $row['status'] = $states->get(json_encode([$row['source_table_key'], $row['record_id']]))?->status ?? 'visible';
            return $row;
        });
        $namedMain = $records->filter(fn (array $row): bool => trim($row['table_name']) === '红人数据');
        $mainRaw = $namedMain->isNotEmpty() ? $namedMain : $records->filter(fn (array $row): bool => $this->hasAny($row['fields'], ['红人title', '红人', 'influencer'])
            && $this->hasAny($row['fields'], ['浏览', '浏览量', 'views'])
            && ! str_contains($row['table_name'], '爆款'));
        $allMain = $mainRaw->map(fn (array $row): array => $this->kolRow($row));
        $main = $allMain->where('status', 'visible');
        $clicksAvailable = $mainRaw->contains(fn (array $row): bool => $this->hasAny($row['fields'], ['clicks', '点击', '点击数']));
        $period = $this->latestAvailablePeriod($period, $allMain, ['views', 'likes', 'comments', 'clicks']);
        $current = $this->sortRowsByDate($this->within($main, $period['date_from'], $period['date_to']));
        $previous = $this->within($main, $period['compare_from'], $period['compare_to']);
        $totals = $this->kolTotals($current);
        $previousTotals = $this->kolTotals($previous);
        $trends = $current->groupBy('date')->filter(fn (Collection $rows, mixed $date): bool => filled($date))
            ->map(function (Collection $rows, string $date): array {
                $count = $rows->count();

                return [
                    'date' => $date,
                    'views' => round($rows->sum('views'), 2),
                    'average_views' => $count > 0 ? round($rows->sum('average_views') / $count, 2) : 0,
                    'likes' => round($rows->sum('likes'), 2),
                    'comments' => round($rows->sum('comments'), 2),
                    'engagement_rate' => $count > 0 ? round($rows->sum('engagement_rate') / $count, 2) : 0,
                ];
            })->sortKeys()->values()->all();
        $top = $current->groupBy(fn (array $row): string => $row['influencer'] ?: '未命名红人')
            ->map(fn (Collection $rows, string $name): array => [
                'name' => $name,
                'platform' => $rows->pluck('platform')->filter()->first() ?? '',
                'collaborations' => $rows->count(),
                'views' => round($rows->sum('views'), 2),
                'clicks' => round($rows->sum('clicks'), 2),
                'engagement_rate' => round($rows->avg('engagement_rate') ?? 0, 2),
            ])->sortByDesc('views')->take(10)->values()->all();
        $platforms = $current->groupBy(fn (array $row): string => $row['platform'] ?: '未分类')
            ->map(fn (Collection $rows, string $name): array => [
                'platform' => $name,
                'collaborations' => $rows->count(),
                'influencers' => $rows->pluck('influencer')->filter()->unique()->count(),
                'views' => round($rows->sum('views'), 2),
                'clicks' => round($rows->sum('clicks'), 2),
                'engagement_rate' => round($rows->avg('engagement_rate') ?? 0, 2),
            ])->sortByDesc('views')->values()->all();
        $yearly = $records->filter(fn (array $row): bool => $this->hasAny($row['fields'], ['车型', '合作红人数量', '人均曝光']))
            ->map(fn (array $row): array => [
                'model' => $this->text($this->pick($row['fields'], ['车型', 'model'])),
                'influencers' => $this->number($this->pick($row['fields'], ['合作红人数量', '红人数量'])),
                'views' => $this->number($this->pick($row['fields'], ['曝光量', '浏览量'])),
                'views_per_influencer' => $this->number($this->pick($row['fields'], ['人均曝光', '人均浏览'])),
                'source_fields' => $this->sanitizeFields($row['fields']),
            ])->values()->all();
        $viral = $records->filter(fn (array $row): bool => str_contains($row['table_name'], '爆款')
            && $this->hasAny($row['fields'], ['浏览', '浏览量'])
            && $this->hasAny($row['fields'], ['红人title', '红人']))
            ->map(fn (array $row): array => $this->kolRow($row));
        $viral = $this->sortRowsByDate($viral->where('status', 'visible'))->all();
        $detailColumns = [
            'influencer', 'platform', 'type', 'fee', 'date', 'average_views', 'views', 'likes', 'comments',
            ...($clicksAvailable ? ['clicks'] : []),
            'engagement_rate', 'link',
        ];

        return [
            'schema' => 'natural-traffic-influencer-operations-v1',
            'title' => '红人运营',
            'description' => '合作表现与内容效率；不包含 AI 推荐与增长洞察。',
            'tabs' => [
                ['key' => 'tracking', 'label' => '合作数据明细'],
            ],
            'detail_columns' => $detailColumns,
            'viral_columns' => array_values(array_diff($detailColumns, ['platform', 'type'])),
            'filters' => $period,
            'source' => $this->sourceSummary($source),
            'kpis' => collect([
                ['key' => 'collaborations', 'label' => '合作记录', 'format' => 'number'],
                ['key' => 'influencers', 'label' => '合作红人数', 'format' => 'number'],
                ['key' => 'views', 'label' => '总浏览量', 'format' => 'compact'],
                ['key' => 'average_views', 'label' => '平均浏览', 'format' => 'compact'],
                ['key' => 'engagement_rate', 'label' => '平均互动率', 'format' => 'percent'],
                ...($clicksAvailable ? [['key' => 'clicks', 'label' => '总点击', 'format' => 'compact']] : []),
            ])->map(fn (array $definition): array => [
                ...$definition,
                'value' => $totals[$definition['key']],
                'previous' => $previousTotals[$definition['key']],
                'change' => $this->change($totals[$definition['key']], $previousTotals[$definition['key']]),
            ])->all(),
            'trends' => $trends,
            'top_influencers' => $top,
            'platforms' => $platforms,
            'scatter' => $current->filter(fn (array $row): bool => $row['views'] > 0)->map(fn (array $row): array => [
                'name' => $row['influencer'], 'platform' => $row['platform'], 'views' => $row['views'], 'engagement_rate' => $row['engagement_rate'],
            ])->take(500)->values()->all(),
            'model_summary' => $yearly,
            'viral_content' => $viral,
            'details' => $current->values()->all(),
            'excluded_details' => $this->sortRowsByDate($this->within($allMain->where('status', '!=', 'visible'), $period['date_from'], $period['date_to']))->values()->all(),
            'columns' => $this->columns($mainRaw->all()),
        ];
    }

    /** @param array<string, mixed> $period @return array<string, mixed> */
    private function edm(Store $store, array $period): array
    {
        $source = $this->source($store, ['natural-traffic:sequence', 'natural-traffic:edm']);
        $records = collect($source['records']);
        $sequenceSection = $records->filter(fn (array $row): bool => $row['source_section'] === 'natural-traffic:sequence');
        $namedSequences = $sequenceSection->filter(fn (array $row): bool => str_contains($row['table_name'], '序列表现'));
        $sequenceRaw = $namedSequences->isNotEmpty() ? $namedSequences : $sequenceSection->filter(fn (array $row): bool => $this->hasAny($row['fields'], ['打开率', '点击率', '营收']));
        $sequences = $sequenceRaw->map(fn (array $row): array => $this->edmRow($row));
        $period = $this->latestAvailablePeriod($period, $sequences, ['revenue', 'open_rate', 'click_rate']);
        $current = $this->within($sequences, $period['date_from'], $period['date_to'], includeUndated: true);
        $previous = $this->within($sequences, $period['compare_from'], $period['compare_to']);
        $totals = $this->edmTotals($current);
        $previousTotals = $this->edmTotals($previous);
        $customerBase = Customer::query()->forOrganization((int) $store->organization_id)->forStore((int) $store->id);
        $customers = (clone $customerBase)->count();
        $avgLifetime = (float) ((clone $customerBase)->avg('total_spent') ?? 0);
        $databaseSegments = [
            ['key' => 'all', 'label' => '全部客户', 'count' => $customers, 'description' => '当前项目数据库内已同步客户'],
            ['key' => 'repeat', 'label' => '复购客户', 'count' => (clone $customerBase)->where('orders_count', '>=', 2)->count(), 'description' => '累计订单不少于 2 单'],
            ['key' => 'high_value', 'label' => '高价值客户', 'count' => $avgLifetime > 0 ? (clone $customerBase)->where('total_spent', '>=', $avgLifetime * 2)->count() : 0, 'description' => '终身消费不低于店铺平均值 2 倍'],
            ['key' => 'one_time', 'label' => '单次购买客户', 'count' => (clone $customerBase)->where('orders_count', 1)->count(), 'description' => '累计订单为 1 单'],
            ['key' => 'prospects', 'label' => '未购买客户', 'count' => (clone $customerBase)->where('orders_count', 0)->count(), 'description' => '已同步但暂无订单'],
        ];
        $syncedSegments = $sequenceSection->filter(fn (array $row): bool => str_contains($row['table_name'], '用户分层'))
            ->map(fn (array $row): array => [
                'key' => $row['record_id'],
                'label' => $this->text($this->pick($row['fields'], ['用户层级', '层级', '名称'])) ?: '未命名分层',
                'count' => (int) round($this->number($this->pick($row['fields'], ['数量', '人数', '客户数']))),
                'description' => $this->text($this->pick($row['fields'], ['规则', '描述'])),
                'average_lifetime_value' => $this->number($this->pick($row['fields'], ['平均LTV', 'LTV'])),
            ])->values()->all();
        $segments = $syncedSegments !== [] ? $syncedSegments : $databaseSegments;
        // Use the reporting date, not spreadsheet formula text, as the trend key.
        $buildTrends = fn (Collection $items): array => $items->groupBy(fn (array $row): string => $row['date']
            ? CarbonImmutable::parse($row['date'])->startOfWeek()->toDateString()
            : (preg_match('/^(?:W)?\d{1,2}(?:周)?$/i', $row['week']) ? 'W'.str_pad(preg_replace('/\D/', '', $row['week']), 2, '0', STR_PAD_LEFT) : '未标注日期'))
            ->map(function (Collection $rows, string $label): array {
                $totals = $this->edmTotals($rows);
                $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $label) ? $label : null;
                $dateTo = $rows->pluck('date_to')->filter()->max();

                return [
                    'label' => $label,
                    'date' => $date,
                    'date_to' => $dateTo ?: ($date ? CarbonImmutable::parse($date)->addDays(6)->toDateString() : null),
                    ...$totals,
                ];
            })->sortKeys()->values()->all();
        $trends = $buildTrends($current);
        $historyFrom = CarbonImmutable::parse($period['date_to'])->subWeeks(19)->startOfWeek()->toDateString();
        $trendHistory = $buildTrends($this->within($sequences, $historyFrom, $period['date_to']));
        $sequenceSummaryCollection = $current->groupBy(fn (array $row): string => $row['name'] ?: '未命名序列')
            ->map(function (Collection $rows, string $name): array {
                $totals = $this->edmTotals($rows);

                return ['name' => $name, 'records' => $rows->count(), ...$totals, 'latest_date' => $rows->max('date')];
            })->sortByDesc('revenue')->values();
        $sequenceSummary = $sequenceSummaryCollection->all();
        $topFlows = $sequenceSummaryCollection->take(5)->map(fn (array $row): array => [
            ...$row,
            'revenue_share' => $totals['revenue'] > 0 ? round((float) $row['revenue'] / $totals['revenue'] * 100, 2) : 0,
        ])->values()->all();
        $targetRecords = $records->filter(fn (array $row): bool => $row['source_section'] === 'natural-traffic:edm');
        $targetOrder = ['订阅目标' => 0, '销售额目标' => 1, 'Campaign主题' => 2];
        $targetSheets = $targetRecords
            ->groupBy('table_name')->map(fn (Collection $rows, string $name): array => [
                'name' => $name ?: '未命名工作表',
                'columns' => $this->columns($rows->all()),
                'rows' => $this->sanitizedRows($rows->all(), 1000),
            ])->values()->sortBy(fn (array $sheet): int => $targetOrder[$sheet['name']] ?? 100)->values()->all();
        $targetSubscriberValue = $targetRecords
            ->max(fn (array $row): float => $this->number($this->pick($row['fields'], ['当月用户订阅数', 'Subscribers', '订阅者'])));
        $subscriberValue = $current->max('subscribers') ?: ($targetSubscriberValue ?: $customers);
        $hasSubscriberData = $current->max('subscribers') > 0 || $targetSubscriberValue > 0;

        return [
            'schema' => 'natural-traffic-edm-email-v1',
            'title' => 'EDM 邮件',
            'description' => '邮件收入、序列表现、客户分层与目标数据；不包含 AI 分析。',
            'tabs' => [
                ['key' => 'overview', 'label' => '总览'],
                ['key' => 'sequences', 'label' => '序列表现'],
                ['key' => 'segments', 'label' => '用户分层'],
                ['key' => 'targets', 'label' => '目标看板'],
            ],
            'filters' => $period,
            'source' => $this->sourceSummary($source),
            'kpis' => collect([
                ['key' => 'revenue', 'label' => 'Email Revenue', 'format' => 'currency'],
                ['key' => 'open_rate', 'label' => 'Open Rate', 'format' => 'percent'],
                ['key' => 'click_rate', 'label' => 'CTR', 'format' => 'percent'],
                ['key' => 'conversion_rate', 'label' => 'CVR', 'format' => 'percent'],
                ['key' => 'subscribers', 'label' => $hasSubscriberData ? 'Subscribers' : '数据库客户', 'format' => 'number'],
                ['key' => 'unsubscribe_rate', 'label' => 'Unsubscribe Rate', 'format' => 'percent'],
            ])->map(function (array $definition) use ($totals, $previousTotals, $subscriberValue): array {
                $value = $definition['key'] === 'subscribers' ? $subscriberValue : $totals[$definition['key']];
                $previous = $definition['key'] === 'subscribers' ? 0 : $previousTotals[$definition['key']];

                return [...$definition, 'value' => $value, 'previous' => $previous, 'change' => $this->change($value, $previous)];
            })->all(),
            'trends' => $trends,
            'trend_history' => $trendHistory,
            'sequences' => $sequenceSummary,
            'top_flows' => $topFlows,
            'sequence_columns' => $this->columns($sequenceRaw->all()),
            'sequence_rows' => $this->sanitizedRows($sequenceRaw->all(), 1000),
            'segments' => $segments,
            'segment_summary' => ['customers' => $customers, 'average_lifetime_value' => round($avgLifetime, 2)],
            'target_progress' => $this->edmTargetProgress($targetRecords),
            'target_sheets' => $targetSheets,
        ];
    }

    /** @param array<string, mixed> $period @return array<string, mixed> */
    private function affiliate(Store $store, array $period, string $affiliateFilter): array
    {
        $source = $this->source($store, ['natural-traffic:affiliate']);
        $all = collect($source['records'])->map(fn (array $row): array => $this->affiliateRow($row));
        $period = $this->latestAvailablePeriod($period, $all, ['gmv', 'clicks']);
        $current = $this->within($all, $period['date_from'], $period['date_to'])
            ->when($affiliateFilter !== '', fn (Collection $rows): Collection => $rows->where('affiliate', $affiliateFilter));
        $previous = $this->within($all, $period['compare_from'], $period['compare_to'])
            ->when($affiliateFilter !== '', fn (Collection $rows): Collection => $rows->where('affiliate', $affiliateFilter));
        $totals = $this->affiliateTotals($current);
        $previousTotals = $this->affiliateTotals($previous);
        $weekly = $current->groupBy('date')->filter(fn (Collection $rows, mixed $date): bool => filled($date))
            ->map(function (Collection $rows, string $date): array {
                $clicks = (float) $rows->sum('clicks');
                $gmv = (float) $rows->sum('gmv');

                return [
                    'date' => $date,
                    'week' => $rows->pluck('week')->filter()->first() ?? $date,
                    'clicks' => round($clicks, 2),
                    'gmv' => round($gmv, 2),
                    'gmv_per_click' => $clicks > 0 ? round($gmv / $clicks, 2) : 0,
                ];
            })->sortKeys()->values()->all();
        $partners = $current->groupBy(fn (array $row): string => $row['affiliate'] ?: '未命名联盟')
            ->map(function (Collection $rows, string $name): array {
                $metrics = $this->affiliateTotals($rows);

                return ['name' => $name, 'weeks' => $rows->pluck('date')->filter()->unique()->count(), ...$metrics];
            })->sortByDesc('gmv')->values()->all();

        return [
            'schema' => 'natural-traffic-affiliate-marketing-v1',
            'title' => '联盟营销',
            'description' => '联盟伙伴点击与 GMV 周度表现，数据来自当前项目数据库。',
            'tabs' => [['key' => 'overview', 'label' => '联盟看板']],
            'filters' => [...$period, 'affiliate' => $affiliateFilter],
            'source' => $this->sourceSummary($source),
            'affiliate_options' => $all->pluck('affiliate')->filter()->unique()->sort()->values()->all(),
            'kpis' => collect([
                ['key' => 'gmv', 'label' => '总 GMV', 'format' => 'currency'],
                ['key' => 'clicks', 'label' => '总点击数', 'format' => 'compact'],
                ['key' => 'gmv_per_click', 'label' => '平均 GMV / Click', 'format' => 'currency'],
            ])->map(fn (array $definition): array => [
                ...$definition,
                'value' => $totals[$definition['key']],
                'previous' => $previousTotals[$definition['key']],
                'change' => $this->change($totals[$definition['key']], $previousTotals[$definition['key']]),
            ])->all(),
            'weekly' => $weekly,
            'partners' => $partners,
            'columns' => $this->columns($source['records']),
            'rows' => $this->sanitizedRows($source['records'], 1000),
        ];
    }

    /** @param list<string> $sections @return array{tables: list<array<string, mixed>>, records: list<array<string, mixed>>} */
    private function source(Store $store, array $sections): array
    {
        $tables = FeishuBitableTable::query()
            ->forOrganization((int) $store->organization_id)
            ->forStore((int) $store->id)
            ->whereIn('source_section', $sections)
            ->with(['fields' => fn ($query) => $query->orderBy('field_order'), 'records' => fn ($query) => $query->orderBy('id')->limit(50000)])
            ->orderBy('id')->get();
        $records = [];

        foreach ($tables as $table) {
            $sourceColumns = $table->fields->pluck('name')->filter()->values()->all();
            foreach ($table->records as $sourceOrder => $record) {
                $fields = is_array($record->fields_encrypted) ? $record->fields_encrypted : [];
                $records[] = [
                    'table_id' => (int) $table->id,
                    'source_table_key' => (string) $table->source_table_id,
                    'table_name' => (string) ($table->name ?? ''),
                    'source_section' => (string) $table->source_section,
                    'record_id' => (string) $record->source_record_id,
                    'source_order' => (int) $sourceOrder,
                    'columns' => $sourceColumns !== [] ? $sourceColumns : array_keys($fields),
                    'fields' => $fields,
                    'synced_at' => $record->synced_at?->toIso8601String(),
                ];
            }
        }

        return [
            'tables' => $tables->map(fn ($table): array => [
                'id' => (int) $table->id,
                'name' => (string) ($table->name ?? ''),
                'section' => (string) $table->source_section,
                'record_count' => $table->records->count(),
                'synced_at' => $table->synced_at?->toIso8601String(),
            ])->all(),
            'records' => $records,
        ];
    }

    /** @param array{tables: list<array<string, mixed>>, records: list<array<string, mixed>>} $source @return array<string, mixed> */
    private function sourceSummary(array $source): array
    {
        $latest = collect($source['tables'])->pluck('synced_at')->filter()->sortDesc()->first();

        return [
            'schema' => 'natural-traffic-source-status-v1',
            'ready' => $source['records'] !== [],
            'table_count' => count($source['tables']),
            'record_count' => count($source['records']),
            'last_synced_at' => $latest,
            'storage' => 'current-project-mysql',
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function socialRow(array $row): array
    {
        $fields = $row['fields'];
        $postsValue = $this->pick($fields, ['帖子数', 'posts', '发布数']);
        $isAggregate = $postsValue !== null;
        $views = $this->number($this->pick($fields, ['浏览量', '观看量', '浏览', 'views', '曝光量']));
        $likes = $this->number($this->pick($fields, ['点赞', '点赞数', '赞', '心情', 'likes']));
        $comments = $this->number($this->pick($fields, ['评论', '评论数', '评', 'comments']));
        $shares = $this->number($this->pick($fields, ['分享', '分享数', '分享次数', 'shares']));
        $platform = $this->socialPlatform($this->text($this->pick($fields, ['平台', 'platform', '渠道'])), $row['table_name']);
        $postType = $this->text($this->pick($fields, ['帖子类型', 'post type', '类型']));
        $title = $this->text($this->pick($fields, ['标题', 'Name', 'name']));
        $title = $title !== '' ? $title : $this->text($this->pick($fields, ['描述', '内容', '文案', '文本']));
        $accountHandle = $this->text($this->pick($fields, ['账户账号', '账号', 'account username', 'account handle', 'username']));
        $accountName = $this->text($this->pick($fields, ['账户名称', '公共主页名称', '频道名称', 'account name', 'channel title']));
        $reach = $this->number($this->pick($fields, ['触达', 'reach', '覆盖人数']));
        $impressions = $this->number($this->pick($fields, ['曝光量', '展示次数', 'impressions']));
        $isVideo = (bool) preg_match('/reel|视频|video|short/i', $postType);
        if ($isVideo) {
            $exclusionMetric = '播放量';
            $exclusionValue = $views > 0 ? $views : $reach;
        } elseif ($impressions > 0) {
            $exclusionMetric = '曝光量';
            $exclusionValue = $impressions;
        } elseif ($reach > 0) {
            $exclusionMetric = '覆盖人数';
            $exclusionValue = $reach;
        } else {
            $exclusionMetric = '浏览量';
            $exclusionValue = $views;
        }
        $excluded = ! $isAggregate
            && $platform === 'Instagram'
            && $exclusionValue > self::INSTAGRAM_OUTLIER_THRESHOLD;

        return [
            'record_id' => $row['record_id'], 'table_name' => $row['table_name'],
            'source_order' => $row['source_order'] ?? PHP_INT_MAX,
            'synced_at' => $row['synced_at'],
            'source_section' => $row['source_section'],
            'source_table_key' => $row['source_table_key'],
            'source_mode' => match ($row['source_section']) {
                BrandSocialCsvImportService::SOURCE_SECTION => '手动 CSV',
                YouTubeAnalyticsSyncService::SOURCE_SECTION => '官方 API',
                default => '已同步数据源',
            },
            'platform' => $platform,
            'date' => $this->date($this->pick($fields, ['发布日期', '发布时间', '日期', '开始日期', '日期周期', 'date'])),
            'account_handle' => $accountHandle,
            'account_name' => $accountName,
            'title' => $title,
            'post_type' => $postType,
            'content_origin' => $this->socialContentOrigin($fields),
            'content_origin_label' => $this->socialContentOrigin($fields) === 'collaboration' ? '合作内容' : '官媒内容',
            'posts' => $isAggregate ? $this->number($postsValue) : 1,
            'views' => $views,
            'likes' => $likes,
            'comments' => $comments,
            'shares' => $shares,
            'saves' => $this->number($this->pick($fields, ['收藏次数', '收藏', '保存', 'saves'])),
            'reach' => $reach,
            'impressions' => $impressions,
            'clicks' => $this->number($this->pick($fields, ['点击', '点击数', '总点击量', '链接点击量', 'total clicks', 'link clicks'])),
            'followers' => $this->number($this->pick($fields, ['关注者数量', '关注者数', '粉丝数', 'followers'])),
            'permalink' => $this->link($this->pick($fields, ['固定链接', '链接', '帖子链接', 'permalink', 'URL'])),
            'engagement_rate' => $views > 0 ? round(($likes + $comments + $shares) / $views * 100, 2) : 0,
            'excluded_from_aggregates' => $excluded,
            'aggregation_status' => $excluded ? '异常爆款，已排除' : '已纳入',
            'exclusion_metric' => $exclusionMetric,
            'exclusion_value' => round($exclusionValue, 2),
            'exclusion_reason' => $excluded
                ? "Instagram {$exclusionMetric}超过 ".number_format(self::INSTAGRAM_OUTLIER_THRESHOLD)
                : '',
            'source_fields' => $this->sanitizeFields($fields),
        ];
    }

    /** @param array<string, mixed> $fields */
    private function socialContentOrigin(array $fields): string
    {
        $origin = mb_strtolower($this->text($this->pick($fields, [
            '内容来源', '来源类型', '内容归属', '是否合作', '合作类型', 'content origin', 'origin',
        ])));

        return preg_match('/合作|达人|红人|collab|partner|influencer/i', $origin) === 1
            ? 'collaboration'
            : 'official';
    }

    /** @param Collection<int, array<string, mixed>> $all @return list<array<string, mixed>> */
    private function brandPlatformSources(Store $store, Collection $all): array
    {
        $youtubeConfigured = collect(['client_id', 'client_secret', 'refresh_token'])
            ->every(fn (string $key): bool => filled($this->credentials->value($store, 'youtube_analytics', $key)));

        return collect([
            ['platform' => 'Instagram', 'mode' => 'manual_csv', 'mode_label' => '手动 CSV 导入'],
            ['platform' => 'Facebook', 'mode' => 'manual_csv', 'mode_label' => '手动 CSV 导入'],
            ['platform' => 'YouTube', 'mode' => 'official_api', 'mode_label' => '官方 API 同步'],
        ])->map(function (array $source) use ($all, $youtubeConfigured): array {
            $rows = $all->where('platform', $source['platform']);
            $available = $rows->isNotEmpty();
            $configured = $source['platform'] === 'YouTube' ? $youtubeConfigured : true;
            $status = $available
                ? '已有数据'
                : ($source['platform'] === 'YouTube'
                    ? ($configured ? '已授权，待同步' : '待 OAuth 授权')
                    : '待导入 CSV');

            return [
                ...$source,
                'available' => $available,
                'configured' => $configured,
                'status' => $status,
                'record_count' => $rows->count(),
                'last_synced_at' => $rows->pluck('synced_at')->filter()->sortDesc()->first(),
            ];
        })->all();
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function kolRow(array $row): array
    {
        $fields = $row['fields'];

        return [
            'record_id' => $row['record_id'], 'table_name' => $row['table_name'],
            'source_table_key' => $row['source_table_key'], 'status' => $row['status'] ?? 'visible',
            'source_order' => $row['source_order'] ?? PHP_INT_MAX,
            'influencer' => $this->text($this->pick($fields, ['红人title', '红人', 'influencer', '达人'])),
            'platform' => $this->text($this->pick($fields, ['平台', 'platform'])),
            'type' => $this->text($this->pick($fields, ['合作类型', '类型'])),
            'fee' => $this->text($this->pick($fields, ['合作价格', '合作费用', '费用', 'price'])),
            'date' => $this->date($this->pick($fields, ['发布日期', '合作日期', '日期'])),
            'average_views' => $this->number($this->pick($fields, ['均播', '平均浏览', 'avg views'])),
            'views' => $this->number($this->pick($fields, ['浏览', '浏览量', 'views', '曝光量'])),
            'likes' => $this->number($this->pick($fields, ['赞', '点赞', 'likes'])),
            'comments' => $this->number($this->pick($fields, ['评', '评论', 'comments'])),
            'clicks' => $this->number($this->pick($fields, ['clicks', '点击', '点击数'])),
            'conversion_rate' => $this->percent($this->pick($fields, ['转化率', 'conversion rate'])),
            'engagement_rate' => $this->percent($this->pick($fields, ['互动率', 'engagement rate', 'ER'])),
            'link' => $this->link($this->pick($fields, ['合作链接', '链接', 'URL'])),
            'commission_link' => $this->link($this->pick($fields, ['佣金链接'])),
            'copy' => $this->text($this->pick($fields, ['文案', '内容'])),
            'source_fields' => $this->displayKolSourceFields($fields),
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function edmRow(array $row): array
    {
        $fields = $row['fields'];

        return [
            'record_id' => $row['record_id'], 'table_name' => $row['table_name'],
            'name' => $this->text($this->pick($fields, ['Name', '名称', '序列', 'flow'])),
            'week' => $this->text($this->pick($fields, ['周', 'week', 'wk'])),
            'date' => $this->date($this->pick($fields, ['开始日期', '日期', 'date'])),
            'date_to' => $this->date($this->pick($fields, ['结束日期'])),
            'revenue' => $this->number($this->pick($fields, ['Email Revenue', 'Revenue', '营收', '收入', 'GMV'])),
            'open_rate' => $this->percent($this->pick($fields, ['Open Rate', '打开率', 'OR'])),
            'click_rate' => $this->percent($this->pick($fields, ['Click Rate', '点击率', 'CTR'])),
            'conversion_rate' => $this->percent($this->pick($fields, ['Conversion Rate', '转化率', 'CVR'])),
            'unsubscribe_rate' => $this->percent($this->pick($fields, ['Unsubscribe Rate', '退订率', '取消订阅'])),
            'subscribers' => $this->number($this->pick($fields, ['Subscribers', '订阅者', '订阅人数'])),
            'source_fields' => $this->sanitizeFields($fields),
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function affiliateRow(array $row): array
    {
        $fields = $row['fields'];
        $date = $this->date($this->pick($fields, ['开始日期', '日期', 'date']));
        $week = $this->text($this->pick($fields, ['周', 'week']));

        return [
            'record_id' => $row['record_id'], 'table_name' => $row['table_name'],
            'affiliate' => $this->text($this->pick($fields, ['Name', '联盟', '联盟名称', 'affiliate'])),
            'week' => $week !== '' ? 'W'.str_pad(ltrim($week, 'Ww'), 2, '0', STR_PAD_LEFT) : ($date ?? ''),
            'date' => $date,
            'date_to' => $this->date($this->pick($fields, ['结束日期'])),
            'clicks' => $this->number($this->pick($fields, ['点击', '点击数', 'clicks'])),
            'gmv' => $this->number($this->pick($fields, ['GMV', '营收', 'revenue'])),
            'source_fields' => $this->sanitizeFields($fields),
        ];
    }

    private function socialPlatform(string $platform, string $tableName): string
    {
        if ($platform !== '') {
            return match (mb_strtoupper($platform)) {
                'IG', 'INS', 'INSTAGRAM' => 'Instagram',
                'FB', 'FACEBOOK' => 'Facebook',
                'YT', 'YOUTUBE' => 'YouTube',
                default => $platform,
            };
        }

        $name = mb_strtoupper($tableName);

        return str_contains($name, 'IG') || str_contains($name, 'INS') ? 'Instagram'
            : (str_contains($name, 'FB') ? 'Facebook'
                : (str_contains($name, 'YT') || str_contains($name, 'YOUTUBE') ? 'YouTube' : '未分类'));
    }

    /** @param Collection<int, array<string, mixed>> $rows @return array<string, float|int> */
    private function kolTotals(Collection $rows): array
    {
        $count = $rows->count();

        return [
            'collaborations' => $count,
            'influencers' => $rows->pluck('influencer')->filter()->unique()->count(),
            'views' => round($rows->sum('views'), 2),
            'average_views' => $count > 0 ? round($rows->sum('views') / $count, 2) : 0,
            'engagement_rate' => $count > 0 ? round($rows->sum('engagement_rate') / $count, 2) : 0,
            'clicks' => round($rows->sum('clicks'), 2),
        ];
    }

    /** @param Collection<int, array<string, mixed>> $rows @return array<string, float|int> */
    private function edmTotals(Collection $rows): array
    {
        $count = $rows->count();

        return [
            'revenue' => round($rows->sum('revenue'), 2),
            'open_rate' => $count > 0 ? round($rows->sum('open_rate') / $count, 2) : 0,
            'click_rate' => $count > 0 ? round($rows->sum('click_rate') / $count, 2) : 0,
            'conversion_rate' => $count > 0 ? round($rows->sum('conversion_rate') / $count, 2) : 0,
            'unsubscribe_rate' => $count > 0 ? round($rows->sum('unsubscribe_rate') / $count, 2) : 0,
            'subscribers' => round($rows->max('subscribers') ?? 0, 2),
        ];
    }

    /** @param Collection<int, array<string, mixed>> $rows @return array<string, float> */
    private function affiliateTotals(Collection $rows): array
    {
        $clicks = (float) $rows->sum('clicks');
        $gmv = (float) $rows->sum('gmv');

        return ['gmv' => round($gmv, 2), 'clicks' => round($clicks, 2), 'gmv_per_click' => $clicks > 0 ? round($gmv / $clicks, 2) : 0];
    }

    /** @param Collection<int, array<string, mixed>> $rows @param list<string> $metrics @return array<string, float> */
    private function sums(Collection $rows, array $metrics): array
    {
        return collect($metrics)->mapWithKeys(fn (string $metric): array => [$metric => round($rows->sum($metric), 2)])->all();
    }

    /** @param Collection<int, array<string, mixed>> $rows @param list<string> $metrics @return list<array<string, mixed>> */
    private function trend(Collection $rows, array $metrics, string $dateKey): array
    {
        return $rows->filter(fn (array $row): bool => filled($row[$dateKey] ?? null))->groupBy($dateKey)
            ->map(fn (Collection $items, string $date): array => ['date' => $date, ...$this->sums($items, $metrics)])
            ->sortKeys()->values()->all();
    }

    /** @param Collection<int, array<string, mixed>> $rows @return Collection<int, array<string, mixed>> */
    private function within(Collection $rows, string $from, string $to, bool $includeUndated = false): Collection
    {
        return $rows->filter(function (array $row) use ($from, $to, $includeUndated): bool {
            $date = $row['date'] ?? null;

            return $date === null || $date === '' ? $includeUndated : $date >= $from && $date <= $to;
        })->values();
    }

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    private function period(Store $store, array $filters): array
    {
        $timezone = $store->timezone ?: 'UTC';
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $defaultTo = $today->subDay();
        $defaultFrom = $defaultTo->subDays(6);
        $from = $this->validDate($filters['date_from'] ?? null) ?? $defaultFrom;
        $to = $this->validDate($filters['date_to'] ?? null) ?? $defaultTo;
        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }
        if ($from->diffInDays($to) > 366) {
            $from = $to->subDays(366);
        }
        $days = $from->diffInDays($to) + 1;
        $compareTo = $from->subDay();
        $compareFrom = $compareTo->subDays($days - 1);
        $comparison = ($filters['comparison'] ?? 'previous') === 'none' ? 'none' : 'previous';

        return [
            'date_from' => $from->toDateString(), 'date_to' => $to->toDateString(),
            'comparison' => $comparison,
            'compare_from' => $comparison === 'none' ? '' : $compareFrom->toDateString(),
            'compare_to' => $comparison === 'none' ? '' : $compareTo->toDateString(),
            'default_range' => blank($filters['date_from'] ?? null) && blank($filters['date_to'] ?? null),
        ];
    }

    /** @param array<string, mixed> $period @param Collection<int, array<string, mixed>> $rows @param list<string> $metricKeys @return array<string, mixed> */
    private function latestAvailablePeriod(array $period, Collection $rows, array $metricKeys): array
    {
        if (! ($period['default_range'] ?? false)) {
            return $period;
        }

        $meaningfulRows = $rows->filter(fn (array $row): bool => collect($metricKeys)->contains(fn (string $key): bool => abs((float) ($row[$key] ?? 0)) > 0.000001));
        $candidateRows = $meaningfulRows->isNotEmpty() ? $meaningfulRows : $rows;
        $latest = $candidateRows->pluck('date')->filter(fn (mixed $date): bool => is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1)->max();
        if (! is_string($latest) || $latest === '' || $candidateRows->contains(fn (array $row): bool => filled($row['date'] ?? null)
            && $row['date'] >= $period['date_from'] && $row['date'] <= $period['date_to'])) {
            return $period;
        }

        $to = CarbonImmutable::parse($latest);
        $from = $to->subDays(6);
        $compareTo = $from->subDay();
        $compareFrom = $compareTo->subDays(6);

        return [
            ...$period,
            'date_from' => $from->toDateString(),
            'date_to' => $to->toDateString(),
            'compare_from' => $period['comparison'] === 'none' ? '' : $compareFrom->toDateString(),
            'compare_to' => $period['comparison'] === 'none' ? '' : $compareTo->toDateString(),
        ];
    }

    private function validDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<string, mixed> $fields @param list<string> $names */
    private function pick(array $fields, array $names): mixed
    {
        foreach ($names as $name) {
            foreach ($fields as $key => $value) {
                if (mb_strtolower(trim((string) $key)) === mb_strtolower($name)) {
                    return $value;
                }
            }
        }
        foreach ($names as $name) {
            foreach ($fields as $key => $value) {
                if (str_contains(mb_strtolower((string) $key), mb_strtolower($name))) {
                    return $value;
                }
            }
        }

        return null;
    }

    /** @param array<string, mixed> $fields @param list<string> $names */
    private function hasAny(array $fields, array $names): bool
    {
        return $this->pick($fields, $names) !== null;
    }

    private function text(mixed $value): string
    {
        if (is_array($value)) {
            return collect($value)->map(function (mixed $item): string {
                if (is_array($item)) {
                    return trim((string) ($item['text'] ?? $item['name'] ?? $item['value'] ?? $item['link'] ?? ''));
                }

                return is_scalar($item) ? trim((string) $item) : '';
            })->filter()->implode(', ');
        }

        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function number(mixed $value): float
    {
        $text = $this->text($value);
        if ($text === '' || in_array($text, ['/', '-', '—'], true)) {
            return 0;
        }
        $multiplier = str_ends_with(mb_strtoupper($text), 'M') ? 1000000 : (str_ends_with(mb_strtoupper($text), 'K') ? 1000 : 1);
        $number = preg_replace('/[^0-9.\-]/u', '', $text);

        return is_numeric($number) ? round((float) $number * $multiplier, 4) : 0;
    }

    private function percent(mixed $value): float
    {
        $number = $this->number($value);
        $text = $this->text($value);

        return round(! str_contains($text, '%') && abs($number) <= 1 ? $number * 100 : $number, 4);
    }

    private function date(mixed $value): ?string
    {
        $text = $this->text($value);
        if ($text === '') {
            return null;
        }
        if (is_numeric($text)) {
            $number = (int) $text;
            if ($number > 1000000000) {
                return ($number > 9999999999
                    ? CarbonImmutable::createFromTimestampMs($number)
                    : CarbonImmutable::createFromTimestamp($number))->setTimezone('Asia/Shanghai')->toDateString();
            }
            if ($number >= 20000 && $number <= 80000) {
                return CarbonImmutable::create(1899, 12, 30)->addDays($number)->toDateString();
            }
        }
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})(?:\s|$)/', $text, $matches)) {
            return CarbonImmutable::create((int) $matches[3], (int) $matches[1], (int) $matches[2])->toDateString();
        }
        if (preg_match('/^(\d{1,2})[.\/-](\d{1,2})\s*[-~至]/u', $text, $matches)) {
            return CarbonImmutable::create((int) date('Y'), (int) $matches[1], (int) $matches[2])->toDateString();
        }

        try {
            return CarbonImmutable::parse(str_replace('/', '-', $text))->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function link(mixed $value): string
    {
        if (is_array($value)) {
            if (filled($value['link'] ?? null)) {
                return trim((string) $value['link']);
            }

            foreach ($value as $item) {
                if (is_array($item) && filled($item['link'] ?? null)) {
                    return trim((string) $item['link']);
                }
            }
        }
        $text = $this->text($value);

        return preg_match('#^https?://#i', $text) ? $text : '';
    }

    /** @param Collection<int, array<string, mixed>> $records @return list<array<string, mixed>> */
    private function edmTargetProgress(Collection $records): array
    {
        $subscription = $records->first(fn (array $row): bool => $row['table_name'] === '订阅目标');
        $sales = $records->first(fn (array $row): bool => $row['table_name'] === '销售额目标');
        $campaign = $records->first(fn (array $row): bool => $row['table_name'] === 'Campaign主题');
        $cards = [];

        if ($subscription) {
            $fields = $subscription['fields'];
            $subscribers = $this->targetNumber($this->pick($fields, ['当月用户订阅数']));
            $users = $this->targetNumber($this->pick($fields, ['当月用户总数']));
            $actual = $this->targetNumber($this->pick($fields, ['邮件订阅率']), true);
            $actual ??= $subscribers !== null && $users !== null && $users > 0 ? round($subscribers / $users * 100, 2) : null;
            $cards[] = $this->targetCard('subscription_rate', '邮件订阅率', $actual, $this->targetNumber($this->pick($fields, ['目标']), true), 'percent');
        }

        if ($sales) {
            $fields = $sales['fields'];
            $cards[] = $this->targetCard('sales_share', '邮件销售额占比', $this->targetNumber($this->pick($fields, ['销售额完成占比']), true), $this->targetNumber($this->pick($fields, ['销售额目标占比']), true), 'percent');
        }

        if ($campaign) {
            $fields = $campaign['fields'];
            $cards[] = $this->targetCard('campaign_count', 'Campaign 发送数', $this->targetNumber($this->pick($fields, ['Campaign主题发送数'])), $this->targetNumber($this->pick($fields, ['目标'])), 'number');
            $cards[] = $this->targetCard('campaign_open_rate', 'Campaign 平均打开率', $this->targetNumber($this->pick($fields, ['Campaign主题平均打开率']), true), $this->targetNumber($this->pick($fields, ['目标_2']), true), 'percent');
            $cards[] = $this->targetCard('campaign_click_rate', 'Campaign 平均点击率', $this->targetNumber($this->pick($fields, ['Campaign主题平均点击率']), true), $this->targetNumber($this->pick($fields, ['目标_3']), true), 'percent');
        }

        return $cards;
    }

    /** @return array<string, mixed> */
    private function targetCard(string $key, string $label, ?float $actual, ?float $target, string $format): array
    {
        $progress = $actual !== null && $target !== null && $target > 0 ? round($actual / $target * 100, 2) : null;

        return [
            'key' => $key,
            'label' => $label,
            'actual' => $actual,
            'target' => $target,
            'progress' => $progress,
            'format' => $format,
            'status' => $progress === null ? 'unconfigured' : ($progress >= 100 ? 'complete' : ($progress >= 80 ? 'attention' : 'behind')),
        ];
    }

    private function targetNumber(mixed $value, bool $percent = false): ?float
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);
        if ($text === '' || str_starts_with($text, '=') || preg_match('/[A-Z]+\d+/i', $text)) {
            return null;
        }

        $number = preg_replace('/[^0-9.\-]/u', '', $text);
        if (! is_numeric($number)) {
            return null;
        }

        $result = (float) $number;

        return round($percent && ! str_contains($text, '%') && abs($result) <= 1 ? $result * 100 : $result, 4);
    }

    private function change(float|int $current, float|int $previous): ?float
    {
        return abs((float) $previous) > 0.000001 ? round(((float) $current - (float) $previous) / abs((float) $previous) * 100, 2) : null;
    }

    /** @param array<string, mixed> $fields @return array<string, mixed> */
    private function sanitizeFields(array $fields): array
    {
        return collect($fields)->reject(fn (mixed $value, string $key): bool => (bool) preg_match('/email|邮箱|phone|手机|电话|token|secret|password|openid/i', $key))
            ->map(fn (mixed $value): mixed => is_string($value) ? mb_substr($value, 0, 2000) : $value)->all();
    }

    /** @param array<string, mixed> $fields @return array<string, mixed> */
    private function displayKolSourceFields(array $fields): array
    {
        $fields = $this->sanitizeFields($fields);

        foreach ($fields as $key => $value) {
            if (in_array(mb_strtolower(trim((string) $key)), ['发布日期', '合作日期', '日期', '发布时间'], true)) {
                $fields[$key] = $this->date($value) ?? $value;
            }
        }

        return $fields;
    }

    /** @param Collection<int, array<string, mixed>> $rows @return Collection<int, array<string, mixed>> */
    private function sortRowsByDate(Collection $rows): Collection
    {
        return $rows->sort(function (array $left, array $right): int {
            $byDate = ((string) ($right['date'] ?? '')) <=> ((string) ($left['date'] ?? ''));

            return $byDate !== 0
                ? $byDate
                : ((int) ($left['source_order'] ?? PHP_INT_MAX)) <=> ((int) ($right['source_order'] ?? PHP_INT_MAX));
        })->values();
    }

    /** @param list<array<string, mixed>> $records @return list<string> */
    private function columns(array $records): array
    {
        return collect($records)->flatMap(function (array $row): array {
            $columns = is_array($row['columns'] ?? null) ? $row['columns'] : array_keys($row['fields'] ?? []);

            return array_keys($this->sanitizeFields(array_fill_keys($columns, null)));
        })
            ->unique()->take(100)->values()->all();
    }

    /** @param list<array<string, mixed>> $records @return list<array<string, mixed>> */
    private function sanitizedRows(array $records, int $limit): array
    {
        return collect($records)->take($limit)->map(fn (array $row): array => [
            'record_id' => $row['record_id'], 'table_name' => $row['table_name'], ...$this->sanitizeFields($row['fields'] ?? []),
        ])->all();
    }
}
