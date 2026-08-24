<?php

namespace App\Services\NaturalTraffic;

use App\Models\Customer;
use App\Models\FeishuBitableTable;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class NaturalTrafficDashboardService
{
    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function forChannel(Store $store, string $channel, array $filters = []): array
    {
        $period = $this->period($store, $filters);

        return match ($channel) {
            'brand-media' => $this->brandMedia($store, $period),
            'influencer-operations' => $this->influencerOperations($store, $period),
            'edm-email' => $this->edm($store, $period),
            'affiliate-marketing' => $this->affiliate($store, $period, trim((string) ($filters['affiliate'] ?? ''))),
            default => [],
        };
    }

    /** @param array<string, mixed> $period @return array<string, mixed> */
    private function brandMedia(Store $store, array $period): array
    {
        $source = $this->source($store, ['natural-traffic:social']);
        $all = collect($source['records'])->map(fn (array $row): array => $this->socialRow($row));
        $period = $this->latestAvailablePeriod($period, $all, ['views', 'likes', 'comments', 'shares']);
        $current = $this->within($all, $period['date_from'], $period['date_to']);
        $previous = $this->within($all, $period['compare_from'], $period['compare_to']);
        $metrics = ['posts', 'views', 'likes', 'comments', 'shares'];
        $totals = $this->sums($current, $metrics);
        $previousTotals = $this->sums($previous, $metrics);
        $platforms = $current->groupBy(fn (array $row): string => $row['platform'] ?: '未分类')
            ->map(function (Collection $rows, string $platform) use ($metrics): array {
                $totals = $this->sums($rows, $metrics);
                $engagement = $totals['likes'] + $totals['comments'] + $totals['shares'];

                return [
                    'platform' => $platform,
                    ...$totals,
                    'reach' => round($rows->sum('reach'), 2),
                    'clicks' => round($rows->sum('clicks'), 2),
                    'engagement_rate' => $totals['views'] > 0 ? round($engagement / $totals['views'] * 100, 2) : 0,
                ];
            })->sortByDesc('views')->values()->all();
        $trends = $this->trend($current, $metrics, 'date');
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
        $weeklyReports = $current->filter(fn (array $row): bool => filled($row['date']))
            ->groupBy(fn (array $row): string => CarbonImmutable::parse($row['date'])->startOfWeek()->toDateString())
            ->map(function (Collection $rows, string $week) use ($metrics): array {
                $totals = $this->sums($rows, $metrics);
                $included = $rows->filter(fn (array $row): bool => (float) $row['views'] <= 100000);
                $excluded = $rows->filter(fn (array $row): bool => (float) $row['views'] > 100000);
                $includedTotals = $this->sums($included, $metrics);
                $interactions = $includedTotals['likes'] + $includedTotals['comments'];

                return [
                    'week' => $week,
                    ...$totals,
                    'included_posts' => $includedTotals['posts'],
                    'excluded_posts' => $excluded->sum('posts'),
                    'included_views' => $includedTotals['views'],
                    'included_interactions' => $interactions,
                    'average_views' => $includedTotals['posts'] > 0 ? round($includedTotals['views'] / $includedTotals['posts'], 2) : 0,
                    'content_types' => $included->groupBy(fn (array $row): string => $row['post_type'] ?: '未分类')
                        ->map(fn (Collection $items, string $type): array => ['type' => $type, 'posts' => $items->sum('posts'), 'views' => round($items->sum('views'), 2)])
                        ->sortByDesc('posts')->values()->all(),
                    'top_views' => $included->sortByDesc('views')->take(5)->values()->all(),
                    'top_engagement' => $included->sortByDesc(fn (array $row): float => $row['likes'] + $row['comments'])->take(5)->values()->all(),
                    'excluded_content' => $excluded->sortByDesc('views')->values()->all(),
                ];
            })->sortKeys()->values();
        $weekly = $weeklyReports->map(fn (array $report): array => collect($report)
            ->except(['content_types', 'top_views', 'top_engagement', 'excluded_content'])->all())->all();
        $posts = $current->filter(fn (array $row): bool => $row['title'] !== '' || $row['permalink'] !== '')
            ->sortByDesc('date')->values();
        $availablePlatforms = collect($platforms)->pluck('platform')->filter()->values()->all();
        $expectedPlatforms = ['Instagram', 'Facebook', 'YouTube'];
        $engagements = $totals['likes'] + $totals['comments'] + $totals['shares'];

        return [
            'schema' => 'natural-traffic-brand-media-v1',
            'title' => '品牌官媒',
            'description' => '跨平台内容表现、每日复盘与周度汇总；页面只读取当前项目数据库。',
            'tabs' => [
                ['key' => 'platforms', 'label' => '平台拆解'],
                ['key' => 'daily', 'label' => '每日复盘'],
                ['key' => 'weekly', 'label' => '周报'],
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
            'funnel' => [
                ['key' => 'views', 'label' => '浏览', 'value' => $totals['views']],
                ['key' => 'reach', 'label' => '触达', 'value' => round($current->sum('reach'), 2)],
                ['key' => 'engagements', 'label' => '互动', 'value' => $engagements],
                ['key' => 'clicks', 'label' => '点击', 'value' => round($current->sum('clicks'), 2)],
            ],
            'trends' => $trends,
            'daily' => $daily,
            'weekly' => $weekly,
            'weekly_reports' => $weeklyReports->all(),
            'daily_review' => [
                'posting_days' => $current->pluck('date')->filter()->unique()->count(),
                'engagements' => $totals['likes'] + $totals['comments'] + $totals['shares'],
                'reach' => round($current->sum('reach'), 2),
                'clicks' => round($current->sum('clicks'), 2),
                'top_post' => $posts->sortByDesc('views')->first(),
                'top_engagement_post' => $posts->sortByDesc(fn (array $row): float => $row['likes'] + $row['comments'] + $row['shares'])->first(),
            ],
            'posts' => $posts->take(500)->all(),
            'columns' => $this->columns($source['records']),
            'raw_rows' => $this->sanitizedRows($source['records'], 1000),
        ];
    }

    /** @param array<string, mixed> $period @return array<string, mixed> */
    private function influencerOperations(Store $store, array $period): array
    {
        $source = $this->source($store, ['natural-traffic:kol']);
        $records = collect($source['records']);
        $namedMain = $records->filter(fn (array $row): bool => trim($row['table_name']) === '红人数据');
        $mainRaw = $namedMain->isNotEmpty() ? $namedMain : $records->filter(fn (array $row): bool => $this->hasAny($row['fields'], ['红人title', '红人', 'influencer'])
            && $this->hasAny($row['fields'], ['浏览', '浏览量', 'views'])
            && ! str_contains($row['table_name'], '爆款'));
        $main = $mainRaw->map(fn (array $row): array => $this->kolRow($row));
        $clicksAvailable = $mainRaw->contains(fn (array $row): bool => $this->hasAny($row['fields'], ['clicks', '点击', '点击数']));
        $period = $this->latestAvailablePeriod($period, $main, ['views', 'likes', 'comments', 'clicks']);
        $current = $this->within($main, $period['date_from'], $period['date_to']);
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
            ->map(fn (array $row): array => $this->kolRow($row))->sortByDesc('views')->values()->all();

        return [
            'schema' => 'natural-traffic-influencer-operations-v1',
            'title' => '红人运营',
            'description' => '合作表现、红人资源和内容效率；不包含 AI 推荐与增长洞察。',
            'tabs' => [
                ['key' => 'tracking', 'label' => '合作数据明细'],
                ['key' => 'resources', 'label' => '资源库'],
            ],
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
            'details' => $current->sortByDesc('date')->take(1000)->values()->all(),
            'resources' => $main->groupBy(fn (array $row): string => $row['influencer'].'|'.$row['platform'])
                ->map(function (Collection $rows): array {
                    $latest = $rows->sortByDesc('date')->first();

                    return [
                        'influencer' => $latest['influencer'], 'platform' => $latest['platform'],
                        'type' => $latest['type'], 'average_views' => round($rows->avg('average_views') ?? 0, 2),
                        'fee' => $latest['fee'], 'engagement_rate' => round($rows->avg('engagement_rate') ?? 0, 2),
                        'views' => round($rows->sum('views'), 2), 'collaborations' => $rows->count(), 'link' => $latest['link'],
                    ];
                })->sortByDesc('views')->values()->all(),
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
        $trends = $current->groupBy(fn (array $row): string => $row['week'] ?: ($row['date'] ?? '未标注'))
            ->map(function (Collection $rows, string $label): array {
                $totals = $this->edmTotals($rows);

                return ['label' => $label, ...$totals];
            })->sortKeys()->values()->all();
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
            foreach ($table->records as $record) {
                $records[] = [
                    'table_id' => (int) $table->id,
                    'table_name' => (string) ($table->name ?? ''),
                    'source_section' => (string) $table->source_section,
                    'record_id' => (string) $record->source_record_id,
                    'fields' => is_array($record->fields_encrypted) ? $record->fields_encrypted : [],
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

        return [
            'record_id' => $row['record_id'], 'table_name' => $row['table_name'],
            'platform' => $this->socialPlatform($this->text($this->pick($fields, ['平台', 'platform', '渠道'])), $row['table_name']),
            'date' => $this->date($this->pick($fields, ['发布日期', '发布时间', '日期', '开始日期', '日期周期', 'date'])),
            'title' => $this->text($this->pick($fields, ['描述', '内容', '标题', 'Name', 'name', '文案', '文本'])),
            'post_type' => $this->text($this->pick($fields, ['帖子类型', 'post type', '类型'])),
            'posts' => $isAggregate ? $this->number($postsValue) : 1,
            'views' => $views,
            'likes' => $likes,
            'comments' => $comments,
            'shares' => $shares,
            'saves' => $this->number($this->pick($fields, ['收藏', '保存', 'saves'])),
            'reach' => $this->number($this->pick($fields, ['触达', 'reach', '覆盖人数'])),
            'clicks' => $this->number($this->pick($fields, ['点击', '点击数', '总点击量', '链接点击量', 'total clicks', 'link clicks'])),
            'followers' => $this->number($this->pick($fields, ['关注者数量', '关注者数', '粉丝数', 'followers'])),
            'permalink' => $this->link($this->pick($fields, ['固定链接', '链接', '帖子链接', 'permalink', 'URL'])),
            'engagement_rate' => $views > 0 ? round(($likes + $comments + $shares) / $views * 100, 2) : 0,
            'source_fields' => $this->sanitizeFields($fields),
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function kolRow(array $row): array
    {
        $fields = $row['fields'];

        return [
            'record_id' => $row['record_id'], 'table_name' => $row['table_name'],
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
            'source_fields' => $this->sanitizeFields($fields),
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

    /** @param list<array<string, mixed>> $records @return list<string> */
    private function columns(array $records): array
    {
        return collect($records)->flatMap(fn (array $row): array => array_keys($this->sanitizeFields($row['fields'] ?? [])))
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
