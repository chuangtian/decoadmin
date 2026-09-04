<?php

namespace App\Services\MetaAds;

use App\Exceptions\MetaAdsApiException;
use App\Models\Store;
use App\Services\StoreBusinessCredentialService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Throwable;

class MetaAdsApiClient
{
    private const RATE_LIMIT_ERROR_CODES = [4, 17, 32, 613, 80004];

    private const ACCOUNT_FIELDS = [
        'id', 'name', 'account_status', 'currency', 'timezone_name',
        'timezone_offset_hours_utc', 'business_name', 'disable_reason',
        'spend_cap', 'amount_spent', 'balance',
    ];

    private const CAMPAIGN_FIELDS = [
        'id', 'account_id', 'name', 'status', 'effective_status', 'objective',
        'buying_type', 'daily_budget', 'lifetime_budget', 'budget_remaining',
        'start_time', 'stop_time', 'created_time', 'updated_time', 'special_ad_categories',
    ];

    private const AD_SET_FIELDS = [
        'id', 'account_id', 'campaign_id', 'name', 'status', 'effective_status',
        'optimization_goal', 'billing_event', 'bid_strategy', 'daily_budget',
        'lifetime_budget', 'budget_remaining', 'start_time', 'end_time',
        'created_time', 'updated_time', 'targeting', 'promoted_object',
    ];

    private const AD_FIELDS = [
        'id', 'account_id', 'campaign_id', 'adset_id', 'name', 'status',
        'effective_status', 'tracking_specs', 'conversion_specs', 'created_time', 'updated_time',
        'creative{id,name,title,body,call_to_action_type,object_story_id,image_url,thumbnail_url,asset_feed_spec,object_story_spec,url_tags}',
    ];

    private const INSIGHT_FIELDS = [
        'date_start', 'date_stop', 'account_id', 'account_name', 'campaign_id', 'campaign_name',
        'adset_id', 'adset_name', 'ad_id', 'ad_name', 'spend', 'impressions', 'reach', 'clicks',
        'inline_link_clicks', 'frequency', 'actions', 'action_values',
    ];

    /** @var array<string, string> */
    private array $tokens = [];

    public function __construct(
        private HttpFactory $http,
        private StoreBusinessCredentialService $credentials,
        private ?MetaAdsRateLimitService $rateLimits = null,
    ) {}

    /** @return list<array<string, mixed>> */
    public function accounts(Store $store): array
    {
        $accounts = [];
        $this->eachPage($store, '/me/adaccounts', [
            'fields' => implode(',', self::ACCOUNT_FIELDS),
        ], function (array $rows) use (&$accounts): void {
            array_push($accounts, ...$rows);
        });

        return $accounts;
    }

    /** @param callable(list<array<string, mixed>>): void $consume */
    public function eachCampaignPage(Store $store, string $accountId, callable $consume): void
    {
        $this->eachCampaignPageForAccounts(
            $store,
            [$accountId],
            fn (string $ignoredAccountId, array $rows) => $consume($rows),
        );
    }

    /** @param callable(list<array<string, mixed>>): void $consume */
    public function eachAdSetPage(Store $store, string $accountId, callable $consume): void
    {
        $this->eachAdSetPageForAccounts(
            $store,
            [$accountId],
            fn (string $ignoredAccountId, array $rows) => $consume($rows),
        );
    }

    /** @param callable(list<array<string, mixed>>): void $consume */
    public function eachAdPage(Store $store, string $accountId, callable $consume): void
    {
        $this->eachAdPageForAccounts(
            $store,
            [$accountId],
            fn (string $ignoredAccountId, array $rows) => $consume($rows),
        );
    }

    /**
     * @param  list<string>  $accountIds
     * @param  callable(string, list<array<string, mixed>>): void  $consume
     */
    public function eachCampaignPageForAccounts(
        Store $store,
        array $accountIds,
        callable $consume,
        ?int $updatedSince = null,
        array $afterByAccount = [],
        ?callable $checkpoint = null,
    ): void {
        $this->eachAccountEdgePage(
            $store,
            $accountIds,
            'campaigns',
            self::CAMPAIGN_FIELDS,
            $consume,
            $updatedSince,
            $afterByAccount,
            $checkpoint,
        );
    }

    /**
     * @param  list<string>  $accountIds
     * @param  callable(string, list<array<string, mixed>>): void  $consume
     */
    public function eachAdSetPageForAccounts(
        Store $store,
        array $accountIds,
        callable $consume,
        ?int $updatedSince = null,
        array $afterByAccount = [],
        ?callable $checkpoint = null,
    ): void {
        $this->eachAccountEdgePage(
            $store,
            $accountIds,
            'adsets',
            self::AD_SET_FIELDS,
            $consume,
            $updatedSince,
            $afterByAccount,
            $checkpoint,
        );
    }

    /**
     * @param  list<string>  $accountIds
     * @param  callable(string, list<array<string, mixed>>): void  $consume
     */
    public function eachAdPageForAccounts(
        Store $store,
        array $accountIds,
        callable $consume,
        ?int $updatedSince = null,
        array $afterByAccount = [],
        ?callable $checkpoint = null,
    ): void {
        $this->eachAccountEdgePage(
            $store,
            $accountIds,
            'ads',
            self::AD_FIELDS,
            $consume,
            $updatedSince,
            $afterByAccount,
            $checkpoint,
        );
    }

    /**
     * @param  callable(list<array<string, mixed>>): void  $consume
     */
    public function eachInsightPage(
        Store $store,
        string $accountId,
        string $level,
        ?string $since,
        ?string $until,
        callable $consume,
        bool $hourly = false,
    ): void {
        if (! in_array($level, ['account', 'campaign', 'adset', 'ad'], true)) {
            throw new MetaAdsApiException('Meta Ads 洞察层级无效。', 'meta_ads_invalid_level');
        }

        $this->eachInsightPageForAccounts(
            $store,
            [$accountId],
            [$level],
            $since,
            $until,
            fn (string $ignoredAccountId, string $ignoredLevel, array $rows) => $consume($rows),
            $hourly,
        );
    }

    /**
     * Read one aggregate row per entity for an exact date range. Meta reach is
     * deduplicated only at this level, so this request is required for an exact
     * campaign frequency value; daily reach values must never be added.
     *
     * @param  callable(list<array<string, mixed>>): void  $consume
     */
    public function eachInsightPeriodPage(
        Store $store,
        string $accountId,
        string $level,
        string $since,
        string $until,
        callable $consume,
    ): void {
        if (! in_array($level, ['account', 'campaign', 'adset', 'ad'], true)) {
            throw new MetaAdsApiException('Meta Ads 洞察层级无效。', 'meta_ads_invalid_level');
        }

        $this->eachPage($store, "/{$accountId}/insights", [
            'fields' => implode(',', self::INSIGHT_FIELDS),
            'level' => $level,
            'time_range' => json_encode(['since' => $since, 'until' => $until], JSON_THROW_ON_ERROR),
        ], $consume);
    }

    /**
     * Submit a historical Insights query as a Meta async report. Large daily
     * reports regularly time out on the synchronous endpoint even though Meta
     * can finish the same work in the background.
     */
    public function startAsyncInsightReport(
        Store $store,
        string $accountId,
        string $level,
        string $since,
        string $until,
    ): string {
        if (! in_array($level, ['account', 'campaign', 'adset', 'ad'], true)) {
            throw new MetaAdsApiException('Meta Ads 洞察层级无效。', 'meta_ads_invalid_level');
        }

        $response = $this->post($store, "/{$accountId}/insights", [
            'fields' => implode(',', self::INSIGHT_FIELDS),
            'level' => $level,
            'time_increment' => 1,
            'time_range' => json_encode(['since' => $since, 'until' => $until], JSON_THROW_ON_ERROR),
        ]);
        $reportId = trim((string) $response->json('report_run_id', ''));
        if ($reportId === '' || ! preg_match('/^\d+$/', $reportId)) {
            throw new MetaAdsApiException('Meta Ads 异步报表编号无效。', 'meta_ads_async_report_invalid');
        }

        return $reportId;
    }

    /** @return array{state: 'pending'|'completed'|'failed', percent: int} */
    public function asyncInsightReportStatus(Store $store, string $reportId): array
    {
        $this->assertReportId($reportId);
        $response = $this->request($store, "/{$reportId}", [
            'fields' => 'async_status,async_percent_completion',
        ]);
        $status = mb_strtolower(trim((string) $response->json('async_status', '')));
        $percent = min(100, max(0, (int) $response->json('async_percent_completion', 0)));

        if (str_contains($status, 'completed')) {
            return ['state' => 'completed', 'percent' => 100];
        }
        if (str_contains($status, 'failed') || str_contains($status, 'skipped')) {
            return ['state' => 'failed', 'percent' => $percent];
        }

        return ['state' => 'pending', 'percent' => $percent];
    }

    /** @param callable(list<array<string, mixed>>): void $consume */
    public function eachAsyncInsightReportPage(
        Store $store,
        string $reportId,
        callable $consume,
    ): void {
        $this->assertReportId($reportId);
        $this->eachPage($store, "/{$reportId}/insights", [], $consume);
    }

    /**
     * @param  list<string>  $accountIds
     * @param  list<string>  $levels
     * @param  callable(string, string, list<array<string, mixed>>): void  $consume
     */
    public function eachInsightPageForAccounts(
        Store $store,
        array $accountIds,
        array $levels,
        ?string $since,
        ?string $until,
        callable $consume,
        bool $hourly = false,
    ): void {
        $streams = [];
        $ranges = $this->insightDateRanges($since, $until, $hourly);

        foreach ($accountIds as $accountId) {
            foreach ($levels as $level) {
                if (! in_array($level, ['account', 'campaign', 'adset', 'ad'], true)) {
                    throw new MetaAdsApiException('Meta Ads 洞察层级无效。', 'meta_ads_invalid_level');
                }

                foreach ($ranges as $rangeIndex => $range) {
                    $parameters = [
                        'fields' => implode(',', self::INSIGHT_FIELDS),
                        'level' => $level,
                        'time_increment' => 1,
                    ];
                    if ($range['since'] !== null && $range['until'] !== null) {
                        $parameters['time_range'] = json_encode($range, JSON_THROW_ON_ERROR);
                    } else {
                        $parameters['date_preset'] = 'maximum';
                    }
                    if ($hourly) {
                        $parameters['breakdowns'] = 'hourly_stats_aggregated_by_advertiser_time_zone';
                    }

                    $streams["insights:{$accountId}:{$level}:{$rangeIndex}"] = [
                        'account_id' => $accountId,
                        'level' => $level,
                        'path' => "/{$accountId}/insights",
                        'parameters' => $parameters,
                    ];
                }
            }
        }

        $this->eachConcurrentPage(
            $store,
            $streams,
            fn (array $stream, array $rows) => $consume($stream['account_id'], $stream['level'], $rows),
            (int) config('services.meta_ads.insight_page_size', 500),
        );
    }

    /**
     * Large synchronous Insights requests are prone to server timeouts. Split
     * historical windows into non-overlapping slices while keeping the hourly
     * request as a single advertiser-local day.
     *
     * @return list<array{since: string|null, until: string|null}>
     */
    private function insightDateRanges(?string $since, ?string $until, bool $hourly): array
    {
        if ($since === null || $until === null || $hourly) {
            return [['since' => $since, 'until' => $until]];
        }

        try {
            $cursor = CarbonImmutable::createFromFormat('!Y-m-d', $since);
            $end = CarbonImmutable::createFromFormat('!Y-m-d', $until);
        } catch (Throwable) {
            return [['since' => $since, 'until' => $until]];
        }

        if ($cursor->greaterThan($end)) {
            return [];
        }

        $windowDays = max(1, (int) config('services.meta_ads.insight_window_days', 31));
        $ranges = [];

        while ($cursor->lessThanOrEqualTo($end)) {
            $rangeEnd = $cursor->addDays($windowDays - 1);
            if ($rangeEnd->greaterThan($end)) {
                $rangeEnd = $end;
            }
            $ranges[] = [
                'since' => $cursor->toDateString(),
                'until' => $rangeEnd->toDateString(),
            ];
            $cursor = $rangeEnd->addDay();
        }

        return $ranges;
    }

    /**
     * @param  list<string>  $accountIds
     * @param  list<string>  $fields
     * @param  callable(string, list<array<string, mixed>>): void  $consume
     */
    private function eachAccountEdgePage(
        Store $store,
        array $accountIds,
        string $edge,
        array $fields,
        callable $consume,
        ?int $updatedSince = null,
        array $afterByAccount = [],
        ?callable $checkpoint = null,
    ): void {
        $streams = [];

        foreach ($accountIds as $accountId) {
            $parameters = ['fields' => implode(',', $fields)];
            if ($updatedSince !== null) {
                $parameters['filtering'] = json_encode([[
                    'field' => 'updated_time',
                    'operator' => 'GREATER_THAN',
                    'value' => $updatedSince,
                ]], JSON_THROW_ON_ERROR);
            }

            $streams["{$edge}:{$accountId}"] = [
                'account_id' => $accountId,
                'level' => $edge,
                'path' => "/{$accountId}/{$edge}",
                'parameters' => $parameters,
                'initial_after' => $afterByAccount[$accountId] ?? null,
            ];
        }

        $this->eachConcurrentPage(
            $store,
            $streams,
            fn (array $stream, array $rows) => $consume($stream['account_id'], $rows),
            match ($edge) {
                'adsets' => (int) config('services.meta_ads.ad_set_page_size', 100),
                'ads' => (int) config('services.meta_ads.ad_page_size', 100),
                default => (int) config('services.meta_ads.page_size', 500),
            },
            $checkpoint,
        );
    }

    /**
     * @param  array<string, array{account_id: string, level: string, path: string, parameters: array<string, int|string>}>  $streams
     * @param  callable(array{account_id: string, level: string, path: string, parameters: array<string, int|string>}, list<array<string, mixed>>): void  $consume
     */
    private function eachConcurrentPage(
        Store $store,
        array $streams,
        callable $consume,
        ?int $pageSize = null,
        ?callable $checkpoint = null,
    ): void {
        $maxPages = max(1, (int) config('services.meta_ads.max_pages', 2000));
        $limit = min(500, max(1, $pageSize ?? (int) config('services.meta_ads.page_size', 500)));

        foreach ($streams as &$stream) {
            $initialAfter = trim((string) ($stream['initial_after'] ?? ''));
            $stream['after'] = $initialAfter !== '' ? $initialAfter : null;
            $stream['page'] = 0;
            $stream['limit'] = $limit;
            $stream['seen_cursors'] = $stream['after'] === null ? [] : [$stream['after'] => true];
        }
        unset($stream);

        while ($streams !== []) {
            $requests = [];
            foreach ($streams as $key => &$stream) {
                $stream['page']++;
                if ($stream['page'] > $maxPages) {
                    throw new MetaAdsApiException('Meta Ads 数据页数超过安全上限。', 'meta_ads_page_limit');
                }

                $query = [...$stream['parameters'], 'limit' => $stream['limit']];
                if ($stream['after'] !== null) {
                    $query['after'] = $stream['after'];
                }
                $requests[$key] = ['path' => $stream['path'], 'parameters' => $query];
            }
            unset($stream);

            foreach ($this->requestPool($store, $requests) as $key => $response) {
                $stream = $streams[$key];
                $streams[$key]['limit'] = (int) $requests[$key]['parameters']['limit'];
                $data = $response->json('data', []);
                if (! is_array($data)) {
                    throw new MetaAdsApiException('Meta Ads 返回的数据格式无效。', 'meta_ads_invalid_response');
                }

                $rows = array_values(array_filter($data, 'is_array'));
                if ($rows !== []) {
                    $consume($stream, $rows);
                }

                $next = $response->json('paging.next');
                $cursor = trim((string) $response->json('paging.cursors.after', ''));
                if (! is_string($next) || $next === '') {
                    if ($checkpoint !== null) {
                        $checkpoint($stream['account_id'], null);
                    }
                    unset($streams[$key]);

                    continue;
                }
                if ($cursor === '' || isset($stream['seen_cursors'][$cursor])) {
                    throw new MetaAdsApiException('Meta Ads 分页游标无效。', 'meta_ads_invalid_cursor');
                }

                $streams[$key]['seen_cursors'][$cursor] = true;
                $streams[$key]['after'] = $cursor;
                if ($checkpoint !== null) {
                    $checkpoint($stream['account_id'], $cursor);
                }
            }
        }
    }

    /**
     * The cursor is rebuilt into the next request instead of following
     * paging.next because Meta may include the access token in that URL.
     *
     * @param  array<string, int|string>  $parameters
     * @param  callable(list<array<string, mixed>>): void  $consume
     */
    private function eachPage(Store $store, string $path, array $parameters, callable $consume): void
    {
        $maxPages = max(1, (int) config('services.meta_ads.max_pages', 2000));
        $limit = min(500, max(1, (int) config('services.meta_ads.page_size', 500)));
        $after = null;
        $seenCursors = [];

        for ($page = 1; $page <= $maxPages; $page++) {
            $query = [...$parameters, 'limit' => $limit];
            if ($after !== null) {
                $query['after'] = $after;
            }

            $response = $this->request($store, $path, $query);
            $data = $response->json('data', []);
            if (! is_array($data)) {
                throw new MetaAdsApiException('Meta Ads 返回的数据格式无效。', 'meta_ads_invalid_response');
            }

            $rows = array_values(array_filter($data, 'is_array'));
            if ($rows !== []) {
                $consume($rows);
            }

            $next = $response->json('paging.next');
            $cursor = trim((string) $response->json('paging.cursors.after', ''));
            if (! is_string($next) || $next === '') {
                return;
            }
            if ($cursor === '' || isset($seenCursors[$cursor])) {
                throw new MetaAdsApiException('Meta Ads 分页游标无效。', 'meta_ads_invalid_cursor');
            }

            $seenCursors[$cursor] = true;
            $after = $cursor;
        }

        throw new MetaAdsApiException('Meta Ads 数据页数超过安全上限。', 'meta_ads_page_limit');
    }

    /** @param array<string, int|string> $parameters */
    private function request(Store $store, string $path, array $parameters): Response
    {
        return $this->send($store, 'get', $path, $parameters);
    }

    /** @param array<string, int|string> $parameters */
    private function post(Store $store, string $path, array $parameters): Response
    {
        return $this->send($store, 'post', $path, $parameters);
    }

    /** @param array<string, int|string> $parameters */
    private function send(Store $store, string $method, string $path, array $parameters): Response
    {
        $token = $this->token($store);
        $delays = collect((array) config('services.meta_ads.retry_delays_ms', [500, 1500, 5000]))
            ->map(fn (mixed $delay): int => max(0, (int) $delay))
            ->values()
            ->all();
        $attempts = count($delays) + 1;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $this->rateLimits?->wait($store);

            try {
                $request = $this->http
                    ->withToken($token)
                    ->acceptJson()
                    ->timeout(max(5, (int) config('services.meta_ads.timeout', 30)));
                $url = rtrim((string) config('services.meta_ads.api_base'), '/').$path;
                $response = $method === 'post'
                    ? $request->asForm()->post($url, $parameters)
                    : $request->get($url, $parameters);
            } catch (ConnectionException) {
                if ($attempt === $attempts) {
                    throw new MetaAdsApiException('Meta Ads API 连接失败。', 'meta_ads_connection_failed');
                }

                $this->pause($delays[$attempt - 1] ?? 0);

                continue;
            }

            $this->rateLimits?->observe($store, $response);

            if ($response->successful()) {
                return $response;
            }

            $retryable = $this->isRetryableResponse($response);
            if ($retryable && $attempt < $attempts) {
                $this->pause($this->retryDelayMilliseconds($response, $delays[$attempt - 1] ?? 0));

                continue;
            }

            throw $this->responseException($response, $path);
        }

        throw new MetaAdsApiException('Meta Ads API 请求失败。');
    }

    private function assertReportId(string $reportId): void
    {
        if (! preg_match('/^\d+$/', $reportId)) {
            throw new MetaAdsApiException('Meta Ads 异步报表编号无效。', 'meta_ads_async_report_invalid');
        }
    }

    /**
     * @param  array<string, array{path: string, parameters: array<string, int|string>}>  $requests
     * @return array<string, Response>
     */
    private function requestPool(Store $store, array &$requests): array
    {
        if ($requests === []) {
            return [];
        }

        $token = $this->token($store);
        $baseUrl = rtrim((string) config('services.meta_ads.api_base'), '/');
        $timeout = max(5, (int) config('services.meta_ads.timeout', 30));
        $concurrency = max(1, (int) config('services.meta_ads.concurrency', 4));
        $delays = collect((array) config('services.meta_ads.retry_delays_ms', [500, 1500, 5000]))
            ->map(fn (mixed $delay): int => max(0, (int) $delay))
            ->values()
            ->all();
        $pending = $requests;
        $responses = [];

        for ($attempt = 1; $attempt <= count($delays) + 1; $attempt++) {
            $this->rateLimits?->wait($store);
            $results = $this->http->pool(function (Pool $pool) use ($pending, $token, $baseUrl, $timeout): void {
                foreach ($pending as $key => $request) {
                    $pool->as($key)
                        ->withToken($token)
                        ->acceptJson()
                        ->timeout($timeout)
                        ->get($baseUrl.$request['path'], $request['parameters']);
                }
            }, $concurrency);
            $retry = [];
            $retryDelay = $delays[$attempt - 1] ?? 0;

            foreach ($results as $key => $result) {
                if ($result instanceof Response) {
                    $this->rateLimits?->observe($store, $result);
                }

                if ($result instanceof Response && $result->successful()) {
                    $responses[$key] = $result;

                    continue;
                }

                $retryable = $result instanceof Throwable
                    || ($result instanceof Response && $this->isRetryableResponse($result));
                if ($retryable && $attempt <= count($delays)) {
                    $retryRequest = $pending[$key];
                    if ($result instanceof Response && $this->shouldReducePageSize($result)) {
                        $currentLimit = max(1, (int) ($retryRequest['parameters']['limit'] ?? 1));
                        $minimumLimit = min(
                            $currentLimit,
                            max(1, (int) config('services.meta_ads.min_page_size', 25)),
                        );
                        $retryRequest['parameters']['limit'] = max(
                            $minimumLimit,
                            (int) floor($currentLimit / 5),
                        );
                    }
                    $requests[$key] = $retryRequest;
                    $retry[$key] = $retryRequest;
                    if ($result instanceof Response) {
                        $retryDelay = max($retryDelay, $this->retryDelayMilliseconds($result, 0));
                    }

                    continue;
                }

                if ($result instanceof Response) {
                    throw $this->responseException($result, $pending[$key]['path']);
                }

                throw new MetaAdsApiException('Meta Ads API 连接失败。', 'meta_ads_connection_failed');
            }

            if ($retry === []) {
                return $responses;
            }

            $pending = $retry;
            $this->pause($retryDelay);
        }

        throw new MetaAdsApiException('Meta Ads API 请求失败。');
    }

    private function responseException(Response $response, ?string $path = null): MetaAdsApiException
    {
        $metaCode = filter_var($response->json('error.code'), FILTER_VALIDATE_INT);
        $status = $response->status();
        $errorCode = match (true) {
            $status === 429 || in_array($metaCode, self::RATE_LIMIT_ERROR_CODES, true) => 'meta_ads_rate_limited',
            in_array($status, [401, 403], true), in_array($metaCode, [190, 200], true) => 'meta_ads_auth_invalid',
            $status >= 500 => 'meta_ads_server_error',
            default => 'meta_ads_api_error',
        };

        return new MetaAdsApiException(
            sprintf(
                'Meta Ads API 请求失败（HTTP %d%s%s）。',
                $status,
                $metaCode === false ? '' : ", Meta {$metaCode}",
                $path === null ? '' : ", endpoint {$path}",
            ),
            $errorCode,
            $status,
            $metaCode === false ? null : $metaCode,
        );
    }

    private function isRetryableResponse(Response $response): bool
    {
        $metaCode = filter_var($response->json('error.code'), FILTER_VALIDATE_INT);

        return $response->status() === 429
            || $response->serverError()
            || in_array($metaCode, self::RATE_LIMIT_ERROR_CODES, true);
    }

    private function retryDelayMilliseconds(Response $response, int $fallback): int
    {
        $retryAfter = filter_var($response->header('Retry-After'), FILTER_VALIDATE_INT);

        return $retryAfter === false
            ? max(0, $fallback)
            : max($fallback, min(300_000, $retryAfter * 1000));
    }

    private function shouldReducePageSize(Response $response): bool
    {
        return $response->serverError()
            && (int) $response->json('error.code') === 1;
    }

    private function token(Store $store): string
    {
        $key = $store->organization_id.':'.$store->getKey();
        if (isset($this->tokens[$key])) {
            return $this->tokens[$key];
        }

        $token = trim((string) $this->credentials->value($store, 'meta_ads', 'access_token'));
        if ($token === '') {
            throw new MetaAdsApiException('当前店铺尚未配置 Meta Ads Access Token。', 'meta_ads_not_configured');
        }

        return $this->tokens[$key] = $token;
    }

    private function pause(int $milliseconds): void
    {
        if ($milliseconds > 0 && ! app()->environment('testing')) {
            usleep($milliseconds * 1000);
        }
    }
}
