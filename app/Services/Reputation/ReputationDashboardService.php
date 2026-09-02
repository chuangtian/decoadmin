<?php

namespace App\Services\Reputation;

use App\Models\ReputationGoal;
use App\Models\ReputationMention;
use App\Models\ReputationResourceRequest;
use App\Models\ReputationRisk;
use App\Models\ReputationSyncRun;
use App\Models\Store;
use App\Services\StoreFeishuDataLinkService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

class ReputationDashboardService
{
    public const GOAL_METRICS = ['satisfied_reviews', 'reddit_views', 'reddit_comments'];

    private const REDDIT_TOPICS = [
        '购买建议 / 对比' => [
            'buy', 'buying', 'bought', 'purchase', 'recommend', 'recommendation', 'recommended',
            'worth', 'versus', 'compare', 'compared', 'comparison', 'choose', 'choice',
            'which bike', 'which model', 'should i', '购买', '选购', '推荐', '对比', '比较', '值得', '怎么选',
        ],
        '产品技术 / 故障' => [
            'battery', 'motor', 'brake', 'controller', 'display', 'charger', 'charging', 'range',
            'error', 'issue', 'problem', 'fault', 'broken', 'repair', 'fix', 'technical', 'spec',
            'firmware', 'tire', 'chain', 'suspension', '电池', '电机', '刹车', '控制器', '充电',
            '续航', '故障', '问题', '维修', '修理', '技术', '参数',
        ],
        '品牌声音 / 抱怨' => [
            'scam', 'complaint', 'complain', 'complained', 'disappointed', 'terrible', 'awful',
            'refund', 'warranty', 'customer service', 'support', 'brand', 'company', 'ripoff', 'avoid',
            '投诉', '抱怨', '失望', '退款', '保修', '客服', '售后', '品牌', '避雷', '垃圾',
        ],
        '社区互动 / 问答' => [
            'question', 'help', 'advice', 'anyone', 'thoughts', 'opinion', 'tips', 'how do',
            'what do', 'where can', 'community', 'discuss', 'discussion', '请问', '求助', '建议',
            '大家', '有人', '怎么', '如何', '讨论', '问答',
        ],
        '骑行生活 / 展示' => [
            'ride', 'riding', 'commute', 'commuting', 'trail', 'adventure', 'trip', 'tour',
            'photo', 'picture', 'setup', 'build', 'showcase', 'new bike', 'my bike', '骑行',
            '通勤', '旅行', '越野', '晒车', '分享', '照片', '改装', '我的车',
        ],
        '其他' => [],
    ];

    public function __construct(private StoreFeishuDataLinkService $dataLinks) {}

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function overview(Store $store, array $filters): array
    {
        [$dateFrom, $dateTo] = $this->dateRange($store, $filters);
        $periodQuery = $this->periodQuery($store, $dateFrom, $dateTo);
        $summary = $this->summary(clone $periodQuery);
        $summary['undated_records'] = ReputationMention::query()
            ->forOrganization((int) $store->organization_id)
            ->forStore((int) $store->id)
            ->where('is_active', true)
            ->whereNull('published_at')
            ->count();
        $social = $this->socialMetrics(clone $periodQuery);
        $goals = $this->goals($store, $dateTo);
        $comparison = $this->comparison($store, $filters, $dateFrom, $dateTo, $summary, $social);

        return [
            'schema' => 'reputation-overview-v1',
            'filters' => [
                'date_from' => $dateFrom->toDateString(),
                'date_to' => $dateTo->toDateString(),
                'tab' => in_array($filters['tab'] ?? null, ['targets', 'reviews', 'reddit', 'threads'], true) ? $filters['tab'] : 'targets',
                'source' => (string) ($filters['source'] ?? ''),
                'rating' => (string) ($filters['rating'] ?? ''),
                'status' => (string) ($filters['status'] ?? ''),
                'search' => trim((string) ($filters['search'] ?? '')),
                'comparison' => in_array($filters['comparison'] ?? null, ['none', 'previous', 'custom'], true) ? $filters['comparison'] : 'none',
                'compare_date_from' => (string) ($filters['compare_date_from'] ?? ''),
                'compare_date_to' => (string) ($filters['compare_date_to'] ?? ''),
            ],
            'summary' => [...$summary, 'reddit' => $social['reddit'], 'threads' => $social['threads']],
            'comparison' => $comparison,
            'reddit_topics' => $this->redditTopics(clone $periodQuery, $dateFrom, $dateTo),
            'source_breakdown' => $this->sourceBreakdown(clone $periodQuery),
            'star_distribution' => $this->starDistribution(clone $periodQuery),
            'trends' => $this->trends(clone $periodQuery, $dateFrom, $dateTo),
            'models' => $this->modelStats(clone $periodQuery),
            'goals' => $this->goalCards($goals, $summary, $social),
            'records' => $this->records($store, $filters, $dateFrom, $dateTo),
            'freshness' => $this->freshness($store),
            'source_status' => $this->dataLinks->sectionStatusForFrontend($store, 'reputation'),
        ];
    }

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function riskSync(Store $store, array $filters): array
    {
        $riskQuery = ReputationRisk::query()
            ->forOrganization((int) $store->organization_id)
            ->forStore((int) $store->id)
            ->when(filled($filters['status'] ?? null), fn (Builder $query) => $query->where('status', $filters['status']))
            ->when(filled($filters['severity'] ?? null), fn (Builder $query) => $query->where('severity', $filters['severity']))
            ->when(filled($filters['source'] ?? null), fn (Builder $query) => $query->where('source', $filters['source']))
            ->when(filled($filters['date_from'] ?? null), fn (Builder $query) => $query->whereDate('occurred_at', '>=', $filters['date_from']))
            ->when(filled($filters['date_to'] ?? null), fn (Builder $query) => $query->whereDate('occurred_at', '<=', $filters['date_to']));

        $risks = (clone $riskQuery)->orderByRaw("CASE severity WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 ELSE 4 END")
            ->latest('occurred_at')
            ->latest('id')
            ->paginate(20, ['*'], 'risk_page')
            ->withQueryString()
            ->through(fn (ReputationRisk $risk): array => [
                'uuid' => $risk->uuid,
                'origin' => $risk->origin,
                'description' => $risk->description,
                'severity' => $risk->severity,
                'source' => $risk->source,
                'recommended_action' => $risk->recommended_action,
                'status' => $risk->status,
                'occurred_at' => $risk->occurred_at?->toIso8601String(),
                'created_at' => $risk->created_at?->toIso8601String(),
            ]);

        $resources = ReputationResourceRequest::query()
            ->forOrganization((int) $store->organization_id)
            ->forStore((int) $store->id)
            ->latest('id')
            ->paginate(15, ['*'], 'resource_page')
            ->withQueryString()
            ->through(fn (ReputationResourceRequest $request): array => [
                'uuid' => $request->uuid,
                'description' => $request->description,
                'request_type' => $request->request_type,
                'priority' => $request->priority,
                'owner_name' => $request->owner_name,
                'status' => $request->status,
                'due_date' => $request->due_date?->toDateString(),
                'created_at' => $request->created_at?->toIso8601String(),
            ]);

        $counts = ReputationRisk::query()
            ->forOrganization((int) $store->organization_id)
            ->forStore((int) $store->id)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn (mixed $value): int => (int) $value)
            ->all();
        $severity = ReputationRisk::query()
            ->forOrganization((int) $store->organization_id)
            ->forStore((int) $store->id)
            ->whereNotIn('status', ['resolved', 'dismissed'])
            ->selectRaw('severity, COUNT(*) as total')
            ->groupBy('severity')
            ->pluck('total', 'severity')
            ->map(fn (mixed $value): int => (int) $value)
            ->all();

        return [
            'schema' => 'reputation-risk-sync-v1',
            'filters' => [
                'date_from' => (string) ($filters['date_from'] ?? ''),
                'date_to' => (string) ($filters['date_to'] ?? ''),
                'status' => (string) ($filters['status'] ?? ''),
                'severity' => (string) ($filters['severity'] ?? ''),
                'source' => (string) ($filters['source'] ?? ''),
            ],
            'counts' => [
                'total' => array_sum($counts),
                'pending' => $counts['pending'] ?? 0,
                'processing' => $counts['processing'] ?? 0,
                'watching' => $counts['watching'] ?? 0,
                'resolved' => $counts['resolved'] ?? 0,
                'high_open' => ($severity['critical'] ?? 0) + ($severity['high'] ?? 0),
            ],
            'risks' => $risks,
            'resources' => $resources,
            'freshness' => $this->freshness($store),
            'source_status' => $this->dataLinks->sectionStatusForFrontend($store, 'reputation'),
        ];
    }

    /** @return array<string, mixed> */
    public function syncStatus(Store $store, string $uuid): array
    {
        $run = ReputationSyncRun::query()
            ->forOrganization((int) $store->organization_id)
            ->forStore((int) $store->id)
            ->where('uuid', $uuid)
            ->firstOrFail();

        return [
            'uuid' => $run->uuid,
            'status' => $run->status,
            'progress_percent' => $run->progress_percent,
            'processed_rows' => $run->processed_rows,
            'message' => $run->last_error,
            'completed_at' => $run->completed_at?->toIso8601String(),
        ];
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function dateRange(Store $store, array $filters): array
    {
        $timezone = $store->timezone ?: 'UTC';
        $now = CarbonImmutable::now($timezone);
        try {
            $from = filled($filters['date_from'] ?? null) ? CarbonImmutable::parse((string) $filters['date_from'], $timezone)->startOfDay() : $now->startOfMonth();
            $to = filled($filters['date_to'] ?? null) ? CarbonImmutable::parse((string) $filters['date_to'], $timezone)->endOfDay() : $now->endOfDay();
        } catch (\Throwable) {
            $from = $now->startOfMonth();
            $to = $now->endOfDay();
        }
        if ($from->gt($to) || $from->diffInDays($to) > 730) {
            $from = $now->startOfMonth();
            $to = $now->endOfDay();
        }

        return [$from, $to];
    }

    private function periodQuery(Store $store, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return ReputationMention::query()
            ->forOrganization((int) $store->organization_id)
            ->forStore((int) $store->id)
            ->where('is_active', true)
            ->whereBetween('published_at', [$from->utc(), $to->utc()]);
    }

    /** @return array<string, int|float> */
    private function summary(Builder $query): array
    {
        $reviews = (clone $query)->whereNotNull('rating');
        $reviewCount = (clone $reviews)->count();
        $satisfied = (clone $reviews)->where('rating', '>=', 4)->count();
        $lowRating = (clone $reviews)->where('rating', '<=', 2)->count();

        return [
            'total' => (clone $query)->count(),
            'reviews' => $reviewCount,
            'average_rating' => round((float) ((clone $reviews)->avg('rating') ?? 0), 2),
            'satisfied_reviews' => $satisfied,
            'low_rating_reviews' => $lowRating,
            'neutral_reviews' => (clone $reviews)->where('rating', 3)->count(),
            'positive_rate' => $reviewCount > 0 ? round(($satisfied / $reviewCount) * 100, 2) : 0.0,
            'negative_rate' => $reviewCount > 0 ? round(($lowRating / $reviewCount) * 100, 2) : 0.0,
            'pending_low_rating' => (clone $reviews)->where('rating', '<', 4)
                ->where(fn (Builder $builder) => $builder->whereNull('processing_status')->orWhereNotIn('processing_status', ['已处理', '完成', 'resolved']))
                ->count(),
            'negative_records' => (clone $query)->where('is_negative', true)->count(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, int|float>  $currentSummary
     * @param  array<string, array<string, int|float>>  $currentSocial
     * @return array<string, mixed>|null
     */
    private function comparison(
        Store $store,
        array $filters,
        CarbonImmutable $from,
        CarbonImmutable $to,
        array $currentSummary,
        array $currentSocial,
    ): ?array {
        $mode = in_array($filters['comparison'] ?? null, ['none', 'previous', 'custom'], true) ? $filters['comparison'] : 'none';
        if ($mode === 'none') {
            return null;
        }

        $timezone = $store->timezone ?: 'UTC';
        $days = (int) $from->startOfDay()->diffInDays($to->startOfDay()) + 1;
        $compareTo = $from->subDay()->endOfDay();
        $compareFrom = $compareTo->startOfDay()->subDays($days - 1);

        if ($mode === 'custom') {
            try {
                $customFrom = CarbonImmutable::parse((string) ($filters['compare_date_from'] ?? ''), $timezone)->startOfDay();
                $customTo = CarbonImmutable::parse((string) ($filters['compare_date_to'] ?? ''), $timezone)->endOfDay();
                if ($customFrom->lte($customTo) && $customFrom->diffInDays($customTo) <= 730) {
                    $compareFrom = $customFrom;
                    $compareTo = $customTo;
                } else {
                    $mode = 'previous';
                }
            } catch (\Throwable) {
                $mode = 'previous';
            }
        }

        $query = $this->periodQuery($store, $compareFrom, $compareTo);
        $previousSummary = $this->summary(clone $query);
        $previousSocial = $this->socialMetrics(clone $query);
        $current = [
            'reviews' => $currentSummary['reviews'],
            'satisfied_reviews' => $currentSummary['satisfied_reviews'],
            'average_rating' => $currentSummary['average_rating'],
            'positive_rate' => $currentSummary['positive_rate'],
            'negative_rate' => $currentSummary['negative_rate'],
            'pending_low_rating' => $currentSummary['pending_low_rating'],
            'reddit_posts' => $currentSocial['reddit']['posts'],
            'reddit_views' => $currentSocial['reddit']['views'],
            'reddit_upvotes' => $currentSocial['reddit']['upvotes'],
            'reddit_comments' => $currentSocial['reddit']['comments'],
            'reddit_spend' => $currentSocial['reddit']['spend'],
            'threads_posts' => $currentSocial['threads']['posts'],
            'threads_likes' => $currentSocial['threads']['likes'],
            'threads_replies' => $currentSocial['threads']['replies'],
            'threads_reposts' => $currentSocial['threads']['reposts'],
            'threads_shares' => $currentSocial['threads']['shares'],
            'threads_engagement' => $currentSocial['threads']['engagement'],
        ];
        $previous = [
            'reviews' => $previousSummary['reviews'],
            'satisfied_reviews' => $previousSummary['satisfied_reviews'],
            'average_rating' => $previousSummary['average_rating'],
            'positive_rate' => $previousSummary['positive_rate'],
            'negative_rate' => $previousSummary['negative_rate'],
            'pending_low_rating' => $previousSummary['pending_low_rating'],
            'reddit_posts' => $previousSocial['reddit']['posts'],
            'reddit_views' => $previousSocial['reddit']['views'],
            'reddit_upvotes' => $previousSocial['reddit']['upvotes'],
            'reddit_comments' => $previousSocial['reddit']['comments'],
            'reddit_spend' => $previousSocial['reddit']['spend'],
            'threads_posts' => $previousSocial['threads']['posts'],
            'threads_likes' => $previousSocial['threads']['likes'],
            'threads_replies' => $previousSocial['threads']['replies'],
            'threads_reposts' => $previousSocial['threads']['reposts'],
            'threads_shares' => $previousSocial['threads']['shares'],
            'threads_engagement' => $previousSocial['threads']['engagement'],
        ];

        return [
            'mode' => $mode,
            'date_from' => $compareFrom->toDateString(),
            'date_to' => $compareTo->toDateString(),
            'summary' => [...$previousSummary, 'reddit' => $previousSocial['reddit'], 'threads' => $previousSocial['threads']],
            'metrics' => collect($current)->mapWithKeys(fn (int|float $value, string $key): array => [
                $key => $this->delta((float) $value, (float) $previous[$key]),
            ])->all(),
        ];
    }

    /** @return array{current: float, previous: float, difference: float, change_percent: float|null} */
    private function delta(float $current, float $previous): array
    {
        return [
            'current' => round($current, 2),
            'previous' => round($previous, 2),
            'difference' => round($current - $previous, 2),
            'change_percent' => $previous == 0.0 ? null : round((($current - $previous) / abs($previous)) * 100, 2),
        ];
    }

    /** @return array<string, array<string, int|float>> */
    private function socialMetrics(Builder $query): array
    {
        $result = [
            'reddit' => ['posts' => 0, 'views' => 0, 'upvotes' => 0, 'comments' => 0, 'spend' => 0.0],
            'threads' => ['posts' => 0, 'likes' => 0, 'replies' => 0, 'reposts' => 0, 'shares' => 0, 'engagement' => 0],
        ];

        (clone $query)->whereIn('source', ['reddit', 'threads'])->select(['id', 'source', 'metrics'])->lazyById(500)->each(function (ReputationMention $mention) use (&$result): void {
            $metrics = $mention->metrics ?? [];
            if ($mention->source === 'reddit') {
                $result['reddit']['posts']++;
                foreach (['views', 'upvotes', 'comments'] as $key) {
                    $result['reddit'][$key] += (int) round((float) ($metrics[$key] ?? 0));
                }
                $result['reddit']['spend'] += (float) ($metrics['spend'] ?? 0);
            } else {
                $result['threads']['posts']++;
                foreach (['likes', 'replies', 'reposts', 'shares'] as $key) {
                    $value = (int) round((float) ($metrics[$key] ?? 0));
                    $result['threads'][$key] += $value;
                    $result['threads']['engagement'] += $value;
                }
            }
        });

        $result['reddit']['spend'] = round((float) $result['reddit']['spend'], 2);

        return $result;
    }

    /** @return array<string, mixed> */
    private function redditTopics(Builder $query, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $topicTotals = collect(array_keys(self::REDDIT_TOPICS))->mapWithKeys(fn (string $topic): array => [
            $topic => ['posts' => 0, 'views' => 0.0, 'comments' => 0.0, 'upvotes' => 0.0],
        ])->all();
        $weeks = [];

        for ($cursor = $from->startOfWeek(); $cursor->lte($to); $cursor = $cursor->addWeek()) {
            $week = $cursor->toDateString();
            $weeks[$week] = [
                'week' => $week,
                'label' => $cursor->format('m/d').' - '.$cursor->addDays(6)->format('m/d'),
                'posts' => 0,
                'topics' => array_fill_keys(array_keys(self::REDDIT_TOPICS), 0),
            ];
        }

        (clone $query)
            ->where('source', 'reddit')
            ->select(['id', 'title', 'content', 'metrics', 'published_at'])
            ->lazyById(500)
            ->each(function (ReputationMention $mention) use (&$topicTotals, &$weeks, $from): void {
                $topic = $this->redditTopicFor($mention->title, $mention->content);
                $metrics = is_array($mention->metrics) ? $mention->metrics : [];
                $topicTotals[$topic]['posts']++;
                foreach (['views', 'comments', 'upvotes'] as $metric) {
                    $topicTotals[$topic][$metric] += $this->nonNegativeMetric($metrics[$metric] ?? null);
                }

                $week = $mention->published_at?->setTimezone($from->timezone)->startOfWeek()->toDateString();
                if ($week !== null && isset($weeks[$week])) {
                    $weeks[$week]['posts']++;
                    $weeks[$week]['topics'][$topic]++;
                }
            });

        return [
            'method' => 'keyword-rules-v1',
            'source_fields' => ['title', 'content'],
            'topic_averages' => collect($topicTotals)->map(function (array $totals, string $topic): array {
                $posts = (int) $totals['posts'];

                return [
                    'topic' => $topic,
                    'views' => $posts > 0 ? round($totals['views'] / $posts, 2) : 0.0,
                    'comments' => $posts > 0 ? round($totals['comments'] / $posts, 2) : 0.0,
                    'upvotes' => $posts > 0 ? round($totals['upvotes'] / $posts, 2) : 0.0,
                    'posts' => $posts,
                ];
            })->values()->all(),
            'weekly_trends' => collect($weeks)->map(function (array $week): array {
                $posts = (int) $week['posts'];

                return [
                    'week' => $week['week'],
                    'label' => $week['label'],
                    'posts' => $posts,
                    'topic_distribution' => collect($week['topics'])->map(fn (int $count, string $topic): array => [
                        'topic' => $topic,
                        'count' => $count,
                        'percent' => $posts > 0 ? round(($count / $posts) * 100, 2) : 0.0,
                    ])->values()->all(),
                ];
            })->values()->all(),
        ];
    }

    private function redditTopicFor(?string $title, ?string $content): string
    {
        $text = mb_strtolower(trim(implode(' ', array_filter([$title, $content], fn (?string $value): bool => filled($value)))));
        $normalized = trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text));
        $scores = [];

        foreach (self::REDDIT_TOPICS as $topic => $keywords) {
            if ($topic === '其他') {
                continue;
            }

            $scores[$topic] = collect($keywords)->filter(
                fn (string $keyword): bool => $this->containsKeyword($normalized, $keyword)
            )->count();
        }

        $highestScore = max($scores ?: [0]);
        if ($highestScore === 0) {
            return '其他';
        }

        foreach (array_keys(self::REDDIT_TOPICS) as $topic) {
            if (($scores[$topic] ?? 0) === $highestScore) {
                return $topic;
            }
        }

        return '其他';
    }

    private function containsKeyword(string $normalizedText, string $keyword): bool
    {
        $normalizedKeyword = mb_strtolower(trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $keyword)));
        if ($normalizedKeyword === '') {
            return false;
        }

        if (preg_match('/\p{Han}/u', $normalizedKeyword) === 1) {
            return str_contains($normalizedText, $normalizedKeyword);
        }

        return str_contains(' '.$normalizedText.' ', ' '.$normalizedKeyword.' ');
    }

    private function nonNegativeMetric(mixed $value): float
    {
        return is_numeric($value) ? max(0.0, (float) $value) : 0.0;
    }

    /** @return list<array<string, int|float|string|null>> */
    private function sourceBreakdown(Builder $query): array
    {
        return (clone $query)->selectRaw('source, COUNT(*) as total, AVG(rating) as average_rating, SUM(CASE WHEN is_negative = 1 THEN 1 ELSE 0 END) as negative_count')
            ->groupBy('source')
            ->orderByDesc('total')
            ->get()
            ->map(fn (ReputationMention $row): array => [
                'source' => $row->source,
                'total' => (int) $row->getAttribute('total'),
                'average_rating' => $row->getAttribute('average_rating') === null ? null : round((float) $row->getAttribute('average_rating'), 2),
                'negative_count' => (int) $row->getAttribute('negative_count'),
            ])->all();
    }

    /** @return list<array{rating: int, count: int, percent: float}> */
    private function starDistribution(Builder $query): array
    {
        $rows = (clone $query)->whereNotNull('rating')->selectRaw('ROUND(rating) as star, COUNT(*) as total')->groupByRaw('ROUND(rating)')->pluck('total', 'star');
        $total = (int) $rows->sum();

        return collect(range(5, 1))->map(fn (int $star): array => [
            'rating' => $star,
            'count' => (int) ($rows[$star] ?? 0),
            'percent' => $total > 0 ? round(((int) ($rows[$star] ?? 0) / $total) * 100, 2) : 0,
        ])->all();
    }

    /** @return list<array<string, int|float|string>> */
    private function trends(Builder $query, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $days = [];
        for ($cursor = $from->startOfDay(); $cursor->lte($to); $cursor = $cursor->addDay()) {
            $days[$cursor->toDateString()] = [
                'date' => $cursor->toDateString(),
                'reviews' => 0,
                'satisfied' => 0,
                'negative' => 0,
                'reddit' => 0,
                'threads' => 0,
                'star_1' => 0,
                'star_2' => 0,
                'star_3' => 0,
                'star_4' => 0,
                'star_5' => 0,
                'reddit_views' => 0,
                'reddit_upvotes' => 0,
                'reddit_comments' => 0,
                'reddit_spend' => 0.0,
                'threads_likes' => 0,
                'threads_replies' => 0,
                'threads_reposts' => 0,
                'threads_shares' => 0,
            ];
        }

        (clone $query)->select(['id', 'source', 'rating', 'metrics', 'is_negative', 'published_at'])->lazyById(500)->each(function (ReputationMention $mention) use (&$days, $from): void {
            $date = $mention->published_at?->setTimezone($from->timezone)->toDateString();
            if (! $date || ! isset($days[$date])) {
                return;
            }
            if ($mention->rating !== null) {
                $days[$date]['reviews']++;
                $star = min(5, max(1, (int) round($mention->rating)));
                $days[$date]['star_'.$star]++;
                if ($mention->rating >= 4) {
                    $days[$date]['satisfied']++;
                }
            }
            if ($mention->is_negative) {
                $days[$date]['negative']++;
            }
            if (in_array($mention->source, ['reddit', 'threads'], true)) {
                $days[$date][$mention->source]++;
            }
            $metrics = $mention->metrics ?? [];
            if ($mention->source === 'reddit') {
                foreach (['views', 'upvotes', 'comments'] as $key) {
                    $days[$date]['reddit_'.$key] += (int) round((float) ($metrics[$key] ?? 0));
                }
                $days[$date]['reddit_spend'] += (float) ($metrics['spend'] ?? 0);
            } elseif ($mention->source === 'threads') {
                foreach (['likes', 'replies', 'reposts', 'shares'] as $key) {
                    $days[$date]['threads_'.$key] += (int) round((float) ($metrics[$key] ?? 0));
                }
            }
        });

        return array_values(array_map(function (array $day): array {
            $day['reddit_spend'] = round((float) $day['reddit_spend'], 2);

            return $day;
        }, $days));
    }

    /** @return list<array{model: string, key: string, count: int, average_rating: float}> */
    private function modelStats(Builder $query): array
    {
        return (clone $query)
            ->where('source', 'website')
            ->whereNotNull('rating')
            ->whereNotNull('model_name')
            ->whereRaw("TRIM(model_name) <> ''")
            ->selectRaw('LOWER(TRIM(model_name)) as model_key, COUNT(*) as total, AVG(rating) as average_rating')
            ->groupByRaw('LOWER(TRIM(model_name))')
            ->orderByDesc('total')
            ->orderBy('model_key')
            ->get()
            ->map(fn (ReputationMention $row): array => [
                'model' => (string) $row->getAttribute('model_key'),
                'key' => (string) $row->getAttribute('model_key'),
                'count' => (int) $row->getAttribute('total'),
                'average_rating' => round((float) ($row->getAttribute('average_rating') ?? 0), 2),
            ])->all();
    }

    /** @return array<string, float> */
    private function goals(Store $store, CarbonImmutable $dateTo): array
    {
        return ReputationGoal::query()->forOrganization((int) $store->organization_id)->forStore((int) $store->id)
            ->whereDate('month', $dateTo->startOfMonth()->toDateString())
            ->whereIn('metric', self::GOAL_METRICS)
            ->pluck('target_value', 'metric')->map(fn (mixed $value): float => (float) $value)->all();
    }

    /** @param array<string, float> $targets @param array<string, int|float> $summary @param array<string, array<string, int|float>> $social @return list<array<string, mixed>> */
    private function goalCards(array $targets, array $summary, array $social): array
    {
        $definitions = [
            'satisfied_reviews' => ['label' => '满意评价目标', 'actual' => (float) $summary['satisfied_reviews']],
            'reddit_views' => ['label' => 'Reddit 曝光目标', 'actual' => (float) $social['reddit']['views']],
            'reddit_comments' => ['label' => 'Reddit 评论目标', 'actual' => (float) $social['reddit']['comments']],
        ];

        $cards = collect($definitions)->map(function (array $definition, string $metric) use ($targets): array {
            $target = (float) ($targets[$metric] ?? 0);
            $actual = (float) $definition['actual'];
            $completion = $target > 0 ? round(($actual / $target) * 100, 2) : null;
            $status = $completion === null ? 'not_configured' : match (true) {
                $completion >= 100 => 'achieved',
                $completion >= 80 => 'near',
                $completion >= 50 => 'behind',
                default => 'risk',
            };

            return [
                'metric' => $metric,
                'label' => $definition['label'],
                'actual' => $actual,
                'target' => $target,
                'completion_percent' => $completion,
                'gap' => $target > 0 ? round($actual - $target, 2) : null,
                'status' => $status,
            ];
        })->values()->all();

        usort($cards, fn (array $left, array $right): int => ($right['completion_percent'] ?? -1) <=> ($left['completion_percent'] ?? -1));

        return $cards;
    }

    private function records(Store $store, array $filters, CarbonImmutable $from, CarbonImmutable $to): LengthAwarePaginator
    {
        $tab = in_array($filters['tab'] ?? null, ['targets', 'reviews', 'reddit', 'threads'], true) ? $filters['tab'] : 'targets';
        $query = ReputationMention::query()->forOrganization((int) $store->organization_id)->forStore((int) $store->id)
            ->with(['productMatches' => function ($matches) use ($store): void {
                $matches->forOrganization((int) $store->organization_id)
                    ->forStore((int) $store->id)
                    ->with(['product' => fn ($product) => $product
                        ->forOrganization((int) $store->organization_id)
                        ->forStore((int) $store->id)]);
            }])
            ->where('is_active', true)
            ->where(function (Builder $builder) use ($from, $to, $filters): void {
                $builder->whereBetween('published_at', [$from->utc(), $to->utc()]);
                if (! filled($filters['date_from'] ?? null) && ! filled($filters['date_to'] ?? null)) {
                    $builder->orWhereNull('published_at');
                }
            });

        $query->when($tab === 'targets', fn (Builder $builder) => $builder->whereIn('source', ['trustpilot', 'website', 'facebook']))
            ->when($tab === 'reddit', fn (Builder $builder) => $builder->where('source', 'reddit'))
            ->when($tab === 'threads', fn (Builder $builder) => $builder->where('source', 'threads'))
            ->when(filled($filters['source'] ?? null), fn (Builder $builder) => $builder->where('source', $filters['source']))
            ->when(filled($filters['rating'] ?? null), fn (Builder $builder) => $builder->where('rating', (int) $filters['rating']))
            ->when(($filters['status'] ?? '') === 'pending', fn (Builder $builder) => $builder->whereNotNull('rating')->where('rating', '<', 4)
                ->where(fn (Builder $nested) => $nested->whereNull('processing_status')->orWhereNotIn('processing_status', ['已处理', '完成', 'resolved'])))
            ->when(($filters['status'] ?? '') === 'done', fn (Builder $builder) => $builder->whereIn('processing_status', ['已处理', '完成', 'resolved']))
            ->when(filled($filters['search'] ?? null), function (Builder $builder) use ($filters, $store): void {
                $search = trim((string) $filters['search']);
                $builder->where(fn (Builder $nested) => $nested->where('title', 'like', "%{$search}%")
                    ->orWhere('content', 'like', "%{$search}%")
                    ->orWhere('reviewer_name', 'like', "%{$search}%")
                    ->orWhere('model_name', 'like', "%{$search}%")
                    ->orWhereHas('productMatches', fn (Builder $matches) => $matches
                        ->forOrganization((int) $store->organization_id)
                        ->forStore((int) $store->id)
                        ->whereHas('product', fn (Builder $product) => $product
                            ->forOrganization((int) $store->organization_id)
                            ->forStore((int) $store->id)
                            ->where(fn (Builder $fields) => $fields
                                ->where('title', 'like', "%{$search}%")
                                ->orWhere('handle', 'like', "%{$search}%")))));
            });

        $perPage = min(50, max(10, (int) ($filters['per_page'] ?? 20)));

        return $query->latest('published_at')->latest('id')->paginate($perPage)->withQueryString()->through(fn (ReputationMention $mention): array => [
            'uuid' => $mention->uuid,
            'source' => $mention->source,
            'url' => $mention->url,
            'title' => $mention->title,
            'reviewer_name' => $mention->reviewer_name,
            'content' => $mention->content,
            'rating' => $mention->rating,
            'week_number' => $mention->week_number,
            'published_at' => $mention->published_at?->toIso8601String(),
            'model_name' => $mention->model_name,
            'processing_status' => $mention->processing_status,
            'response_note' => $mention->response_note,
            'metrics' => $mention->metrics ?? [],
            'is_negative' => $mention->is_negative,
            'origin' => $mention->origin ?: 'source',
            'matched_products' => $mention->productMatches
                ->filter(fn ($match): bool => $match->product !== null
                    && (int) $match->organization_id === (int) $store->organization_id
                    && (int) $match->store_id === (int) $store->id
                    && (int) $match->product->organization_id === (int) $store->organization_id
                    && (int) $match->product->store_id === (int) $store->id)
                ->sortBy(fn ($match): string => ($match->match_role === 'primary' ? '0' : '1').mb_strtolower((string) $match->product->title))
                ->map(fn ($match): array => [
                    'title' => (string) $match->product->title,
                    'handle' => (string) $match->product->handle,
                    'role' => (string) $match->match_role,
                ])
                ->values()
                ->all(),
            'source_sheets' => collect((array) $mention->source_sheets)
                ->filter(fn (mixed $sheet): bool => is_string($sheet) && trim($sheet) !== '')
                ->map(fn (string $sheet): string => $this->displaySheetName($sheet))
                ->unique()
                ->values()
                ->all(),
            'order_reference_masked' => $this->maskOrderReference($mention->order_reference_encrypted),
            'order_reference' => $mention->order_reference_encrypted,
        ]);
    }

    private function maskOrderReference(?string $reference): ?string
    {
        $reference = trim((string) $reference);
        if ($reference === '') {
            return null;
        }
        if (mb_strlen($reference) <= 4) {
            return '••••';
        }

        return '••••'.mb_substr($reference, -4);
    }

    private function displaySheetName(string $sheet): string
    {
        return preg_match('/\bai\b/iu', $sheet) === 1 ? 'Reddit 指标' : $sheet;
    }

    /** @return array<string, mixed> */
    private function freshness(Store $store): array
    {
        $run = ReputationSyncRun::query()->forOrganization((int) $store->organization_id)->forStore((int) $store->id)->latest('id')->first();

        return [
            'last_synced_at' => ReputationMention::query()->forOrganization((int) $store->organization_id)->forStore((int) $store->id)->max('synced_at'),
            'database_total' => ReputationMention::query()->forOrganization((int) $store->organization_id)->forStore((int) $store->id)->where('is_active', true)->count(),
            'sync' => $run ? [
                'uuid' => $run->uuid,
                'status' => $run->status,
                'progress_percent' => $run->progress_percent,
                'processed_rows' => $run->processed_rows,
                'message' => $run->last_error,
            ] : null,
        ];
    }
}
