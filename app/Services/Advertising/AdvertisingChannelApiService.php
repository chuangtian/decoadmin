<?php

namespace App\Services\Advertising;

use App\Exceptions\AdvertisingApiException;
use App\Models\Store;
use App\Services\StoreBusinessCredentialService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;
use ZipArchive;

class AdvertisingChannelApiService
{
    private const META_API = 'https://graph.facebook.com/v21.0';

    private const GOOGLE_ADS_API = 'https://googleads.googleapis.com/v24';

    private const GOOGLE_ADD_TO_CART_ACTION = 'Google Shopping App Add To Cart';

    private const GOOGLE_BEGIN_CHECKOUT_ACTION = 'Google Shopping App Begin Checkout';

    private const TIKTOK_API = 'https://business-api.tiktok.com/open_api/v1.3';

    private const CRITEO_API = 'https://api.criteo.com/2026-01';

    private const CRITEO_TIMEZONE = 'UTC';

    private const BING_REPORTING_API = 'https://reporting.api.bingads.microsoft.com/Reporting/v13';

    /** @var array<string, string> */
    private const CHANNEL_NAMES = [
        'facebook' => 'Facebook',
        'google' => 'Google',
        'tiktok' => 'TikTok',
        'bing' => 'Bing',
        'criteo' => 'Criteo',
    ];

    public function __construct(
        private HttpFactory $http,
        private StoreBusinessCredentialService $credentials,
        private BingConversionGoalClassifier $bingConversionGoals,
    ) {}

    /**
     * Fetches bounded period totals from the five configured advertising APIs.
     * Secret values are used only for server-to-server calls and are never returned.
     *
     * @return list<array{key: string, name: string, configured: bool, available: bool, ad_spend: float, attributed_sales: float, message: string|null}>
     */
    public function fetch(Store $store, string $from, string $to): array
    {
        return collect($this->channelKeys())
            ->map(fn (string $key): array => $this->fetchChannel($store, $key, $from, $to))
            ->all();
    }

    /** @return list<string> */
    public function channelKeys(): array
    {
        return array_keys(self::CHANNEL_NAMES);
    }

    /**
     * Return account discovery plus normalized daily rows for the shared
     * history importer. Meta keeps its richer dedicated entity pipeline.
     *
     * @return array{accounts: list<array<string, mixed>>, daily_metrics: list<array<string, mixed>>, campaign_daily_metrics: list<array<string, mixed>>, ad_daily_metrics: list<array<string, mixed>>, search_term_daily_metrics: list<array<string, mixed>>, keyword_daily_metrics: list<array<string, mixed>>}
     */
    public function syncPayload(Store $store, string $key, string $from, string $to, bool $coreOnly = false): array
    {
        if (! in_array($key, ['google', 'tiktok', 'bing', 'criteo'], true)) {
            throw new RuntimeException('Unsupported advertising history platform.');
        }
        if (! $this->configured($store, $key)) {
            throw new RuntimeException('Advertising credential unavailable.');
        }

        $payload = match ($key) {
            'google' => $this->google($store, $from, $to, $coreOnly),
            'tiktok' => $this->tiktok($store, $from, $to),
            'bing' => $this->bing($store, $from, $to),
            'criteo' => $this->criteo($store, $from, $to),
        };

        return [
            'accounts' => (array) ($payload['accounts'] ?? []),
            'daily_metrics' => (array) ($payload['daily_metrics'] ?? []),
            'campaign_daily_metrics' => (array) ($payload['campaign_daily_metrics'] ?? []),
            'ad_daily_metrics' => (array) ($payload['ad_daily_metrics'] ?? []),
            'search_term_daily_metrics' => (array) ($payload['search_term_daily_metrics'] ?? []),
            'keyword_daily_metrics' => (array) ($payload['keyword_daily_metrics'] ?? []),
        ];
    }

    /** @return array{key: string, name: string, configured: bool, available: bool, ad_spend: float, attributed_sales: float, message: string|null} */
    public function fetchChannel(Store $store, string $key, string $from, string $to): array
    {
        if (! isset(self::CHANNEL_NAMES[$key])) {
            throw new RuntimeException('Unsupported advertising platform.');
        }

        $configured = $this->configured($store, $key);
        if (! $configured) {
            return $this->channel($key, false, false, 0, 0, '未配置该广告平台。');
        }

        try {
            $metrics = match ($key) {
                'facebook' => $this->meta($store, $from, $to),
                'google' => $this->google($store, $from, $to),
                'tiktok' => $this->tiktok($store, $from, $to),
                'bing' => $this->bing($store, $from, $to),
                'criteo' => $this->criteo($store, $from, $to),
            };

            return $this->channel(
                $key,
                true,
                true,
                (float) ($metrics['ad_spend'] ?? 0),
                (float) ($metrics['attributed_sales'] ?? 0),
                null,
            );
        } catch (Throwable) {
            return $this->channel($key, true, false, 0, 0, '该平台同步失败，请检查授权或稍后重试。');
        }
    }

    /** @return array{ad_spend: float, attributed_sales: float} */
    private function meta(Store $store, string $from, string $to): array
    {
        $token = $this->required($store, 'meta_ads', 'access_token');
        $accountsResponse = $this->http->acceptJson()->timeout(20)->get(self::META_API.'/me/adaccounts', [
            'access_token' => $token,
            'fields' => 'id,name,account_status',
            'limit' => 50,
        ]);
        $this->assertSuccessful($accountsResponse, 'Meta');
        $accounts = $accountsResponse->json('data', []);

        if (! is_array($accounts) || $accounts === []) {
            throw new RuntimeException('Meta account unavailable.');
        }

        $spend = 0.0;
        $sales = 0.0;
        $successfulAccounts = 0;

        foreach ($accounts as $account) {
            $accountId = is_array($account) ? trim((string) ($account['id'] ?? '')) : '';
            if ($accountId === '') {
                continue;
            }

            $response = $this->http->acceptJson()->timeout(25)->get(self::META_API."/{$accountId}/insights", [
                'access_token' => $token,
                'fields' => 'spend,action_values',
                'time_range' => json_encode(['since' => $from, 'until' => $to], JSON_THROW_ON_ERROR),
                'time_increment' => 1,
                'level' => 'account',
                'limit' => 500,
            ]);

            if (! $response->successful()) {
                continue;
            }

            $successfulAccounts++;
            foreach ((array) $response->json('data', []) as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $spend += $this->number($row['spend'] ?? 0);
                $sales += $this->metaPurchaseValue($row['action_values'] ?? []);
            }
        }

        if ($successfulAccounts === 0) {
            throw new RuntimeException('Meta insights unavailable.');
        }

        return $this->totals($spend, $sales);
    }

    /** @return array{ad_spend: float, attributed_sales: float} */
    private function google(Store $store, string $from, string $to, bool $coreOnly = false): array
    {
        $clientId = $this->required($store, 'google_ads', 'client_id');
        $clientSecret = $this->required($store, 'google_ads', 'client_secret');
        $refreshToken = $this->required($store, 'google_ads', 'refresh_token');
        $developerToken = $this->required($store, 'google_ads', 'developer_token');
        $customerId = preg_replace('/\D+/', '', $this->required($store, 'google_ads', 'customer_id')) ?: '';
        $loginCustomerId = preg_replace('/\D+/', '', (string) $this->credentials->value($store, 'google_ads', 'login_customer_id')) ?: '';

        $tokenResponse = $this->http->asForm()->acceptJson()->timeout(20)->post('https://oauth2.googleapis.com/token', [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]);
        $this->assertSuccessful($tokenResponse, 'Google OAuth');
        $accessToken = trim((string) $tokenResponse->json('access_token', ''));
        if ($accessToken === '') {
            throw new RuntimeException('Google token unavailable.');
        }

        $headers = [
            'Authorization' => "Bearer {$accessToken}",
            'developer-token' => $developerToken,
        ];
        if ($loginCustomerId !== '') {
            $headers['login-customer-id'] = $loginCustomerId;
        }

        $query = "SELECT customer.id, customer.descriptive_name, customer.currency_code, customer.time_zone, segments.date, metrics.cost_micros, metrics.conversions_value, metrics.conversions_value_by_conversion_date, metrics.all_conversions, metrics.all_conversions_value, metrics.all_conversions_value_by_conversion_date, metrics.impressions, metrics.clicks, metrics.conversions FROM customer WHERE segments.date BETWEEN '{$from}' AND '{$to}' ORDER BY segments.date ASC";
        $response = $this->http->withHeaders($headers)->acceptJson()->timeout(30)
            ->post(self::GOOGLE_ADS_API."/customers/{$customerId}/googleAds:search", ['query' => $query]);
        $this->assertSuccessful($response, 'Google Ads');

        $spend = 0.0;
        $sales = 0.0;
        $daily = [];
        $account = [
            'external_account_id' => $customerId,
            'name' => null,
            'currency' => null,
            'timezone' => null,
            'status' => 'active',
            'raw_payload' => ['customer_id' => $customerId],
        ];
        foreach ((array) $response->json('results', []) as $row) {
            $metrics = is_array($row) && is_array($row['metrics'] ?? null) ? $row['metrics'] : [];
            $customer = is_array($row) && is_array($row['customer'] ?? null) ? $row['customer'] : [];
            $segments = is_array($row) && is_array($row['segments'] ?? null) ? $row['segments'] : [];
            $rowSpend = $this->number($metrics['costMicros'] ?? 0) / 1_000_000;
            $rowSales = $this->number($metrics['conversionsValue'] ?? 0);
            $spend += $rowSpend;
            $sales += $rowSales;
            $account = [
                ...$account,
                'external_account_id' => trim((string) ($customer['id'] ?? $customerId)),
                'name' => $customer['descriptiveName'] ?? null,
                'currency' => $customer['currencyCode'] ?? null,
                'timezone' => $customer['timeZone'] ?? null,
                'raw_payload' => $customer ?: ['customer_id' => $customerId],
            ];
            $date = trim((string) ($segments['date'] ?? ''));
            if ($date !== '') {
                $daily[$date] = $this->dailyRow(
                    (string) $account['external_account_id'],
                    $date,
                    $rowSpend,
                    $rowSales,
                    $metrics['impressions'] ?? 0,
                    $metrics['clicks'] ?? 0,
                    $metrics['conversions'] ?? 0,
                    $row,
                    [
                        'conversion_value_by_conversion_date' => $metrics['conversionsValueByConversionDate'] ?? 0,
                        'all_conversions' => $metrics['allConversions'] ?? 0,
                        'all_conversions_value' => $metrics['allConversionsValue'] ?? 0,
                        'all_conversions_value_by_conversion_date' => $metrics['allConversionsValueByConversionDate'] ?? 0,
                    ],
                );
            }
        }

        $actionNames = [self::GOOGLE_ADD_TO_CART_ACTION, self::GOOGLE_BEGIN_CHECKOUT_ACTION];
        $quotedActionNames = collect($actionNames)
            ->map(fn (string $name): string => "'".str_replace("'", "\\'", $name)."'")
            ->implode(', ');
        $conversionQuery = "SELECT segments.date, segments.conversion_action_name, metrics.all_conversions FROM customer WHERE segments.date BETWEEN '{$from}' AND '{$to}' AND segments.conversion_action_name IN ({$quotedActionNames}) ORDER BY segments.date ASC";
        $conversionResponse = $this->http->withHeaders($headers)->acceptJson()->timeout(30)
            ->post(self::GOOGLE_ADS_API."/customers/{$customerId}/googleAds:search", ['query' => $conversionQuery]);
        $this->assertSuccessful($conversionResponse, 'Google Ads conversions');

        foreach ((array) $conversionResponse->json('results', []) as $row) {
            $segments = is_array($row) && is_array($row['segments'] ?? null) ? $row['segments'] : [];
            $metrics = is_array($row) && is_array($row['metrics'] ?? null) ? $row['metrics'] : [];
            $date = trim((string) ($segments['date'] ?? ''));
            $actionName = trim((string) ($segments['conversionActionName'] ?? ''));
            if ($date === '' || ! in_array($actionName, $actionNames, true)) {
                continue;
            }
            if (! isset($daily[$date])) {
                $daily[$date] = $this->dailyRow(
                    (string) $account['external_account_id'],
                    $date,
                    0,
                    0,
                    0,
                    0,
                    0,
                    [],
                );
            }
            $key = $actionName === self::GOOGLE_ADD_TO_CART_ACTION ? 'add_to_cart' : 'initiate_checkout';
            $daily[$date][$key] = round(
                (float) ($daily[$date][$key] ?? 0) + max(0, $this->number($metrics['allConversions'] ?? 0)),
                6,
            );
            $daily[$date]['raw_payload']['conversion_actions'][$actionName] = $row;
        }

        if ($coreOnly) {
            return [
                ...$this->totals($spend, $sales),
                'accounts' => [$account],
                'daily_metrics' => array_values($daily),
                'campaign_daily_metrics' => [],
                'search_term_daily_metrics' => [],
                'keyword_daily_metrics' => [],
            ];
        }

        $campaignQuery = "SELECT customer.id, campaign.id, campaign.name, campaign.status, campaign.advertising_channel_type, segments.date, metrics.cost_micros, metrics.impressions, metrics.clicks, metrics.conversions, metrics.conversions_value, metrics.conversions_value_by_conversion_date, metrics.all_conversions, metrics.all_conversions_value, metrics.all_conversions_value_by_conversion_date FROM campaign WHERE segments.date BETWEEN '{$from}' AND '{$to}' AND campaign.status = 'ENABLED' ORDER BY segments.date ASC";
        $campaignResponse = $this->http->withHeaders($headers)->acceptJson()->timeout(30)
            ->post(self::GOOGLE_ADS_API."/customers/{$customerId}/googleAds:search", ['query' => $campaignQuery]);
        $this->assertSuccessful($campaignResponse, 'Google Ads campaigns');

        $campaignDaily = [];
        foreach ((array) $campaignResponse->json('results', []) as $row) {
            $campaign = is_array($row) && is_array($row['campaign'] ?? null) ? $row['campaign'] : [];
            $metrics = is_array($row) && is_array($row['metrics'] ?? null) ? $row['metrics'] : [];
            $segments = is_array($row) && is_array($row['segments'] ?? null) ? $row['segments'] : [];
            $campaignId = trim((string) ($campaign['id'] ?? ''));
            $date = trim((string) ($segments['date'] ?? ''));
            if ($campaignId === '' || $date === '') {
                continue;
            }

            $campaignDaily[] = [
                'external_account_id' => trim((string) data_get($row, 'customer.id', $account['external_account_id'])),
                'campaign_id' => $campaignId,
                'campaign_name' => $campaign['name'] ?? null,
                'campaign_status' => $campaign['status'] ?? null,
                'advertising_channel_type' => $campaign['advertisingChannelType'] ?? null,
                'date' => $date,
                'spend' => round(max(0, $this->number($metrics['costMicros'] ?? 0) / 1_000_000), 6),
                'impressions' => max(0, (int) round($this->number($metrics['impressions'] ?? 0))),
                'clicks' => max(0, (int) round($this->number($metrics['clicks'] ?? 0))),
                'conversions' => round(max(0, $this->number($metrics['conversions'] ?? 0)), 6),
                'conversions_value' => round(max(0, $this->number($metrics['conversionsValue'] ?? 0)), 6),
                'conversion_value_by_conversion_date' => round(max(0, $this->number($metrics['conversionsValueByConversionDate'] ?? 0)), 6),
                'all_conversions' => round(max(0, $this->number($metrics['allConversions'] ?? 0)), 6),
                'all_conversions_value' => round(max(0, $this->number($metrics['allConversionsValue'] ?? 0)), 6),
                'all_conversions_value_by_conversion_date' => round(max(0, $this->number($metrics['allConversionsValueByConversionDate'] ?? 0)), 6),
                'raw_payload' => $row,
            ];
        }

        $searchTermQuery = "SELECT customer.id, segments.date, search_term_view.search_term, search_term_view.status, segments.keyword.info.text, segments.keyword.info.match_type, campaign.id, campaign.name, campaign.advertising_channel_type, ad_group.id, ad_group.name, metrics.cost_micros, metrics.impressions, metrics.clicks, metrics.conversions, metrics.conversions_value FROM search_term_view WHERE segments.date BETWEEN '{$from}' AND '{$to}' AND metrics.conversions_value > 0 ORDER BY segments.date ASC";
        $searchTermRows = $this->googleSearch($customerId, $headers, $searchTermQuery, 'Google Ads search terms');
        $pmaxSearchTermQuery = "SELECT customer.id, segments.date, campaign_search_term_view.search_term, campaign.id, campaign.name, campaign.advertising_channel_type, metrics.cost_micros, metrics.impressions, metrics.clicks, metrics.conversions, metrics.conversions_value FROM campaign_search_term_view WHERE segments.date BETWEEN '{$from}' AND '{$to}' AND campaign.advertising_channel_type = 'PERFORMANCE_MAX' AND metrics.conversions_value > 0 ORDER BY segments.date ASC";
        $pmaxSearchTermRows = $this->googleSearch($customerId, $headers, $pmaxSearchTermQuery, 'Google Ads Performance Max search terms');

        $searchTermDaily = collect($searchTermRows)->map(function (array $row) use ($account): ?array {
            return $this->googleSearchTermRow($row, (string) $account['external_account_id'], false);
        })->merge(collect($pmaxSearchTermRows)->map(function (array $row) use ($account): ?array {
            return $this->googleSearchTermRow($row, (string) $account['external_account_id'], true);
        }))->filter()->values()->all();

        $keywordQuery = "SELECT customer.id, segments.date, ad_group_criterion.criterion_id, ad_group_criterion.keyword.text, ad_group_criterion.keyword.match_type, ad_group_criterion.status, campaign.id, campaign.name, ad_group.id, ad_group.name, metrics.cost_micros, metrics.impressions, metrics.clicks, metrics.conversions, metrics.conversions_value FROM keyword_view WHERE segments.date BETWEEN '{$from}' AND '{$to}' AND ad_group_criterion.status != 'REMOVED' AND metrics.cost_micros > 0 ORDER BY segments.date ASC";
        $keywordRows = $this->googleSearch($customerId, $headers, $keywordQuery, 'Google Ads keywords');
        $keywordDaily = collect($keywordRows)->map(function (array $row) use ($account): ?array {
            $criterion = is_array($row['adGroupCriterion'] ?? null) ? $row['adGroupCriterion'] : [];
            $keyword = trim((string) data_get($criterion, 'keyword.text', ''));
            $date = trim((string) data_get($row, 'segments.date', ''));
            if ($keyword === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                return null;
            }
            $campaignId = trim((string) data_get($row, 'campaign.id', ''));
            $adGroupId = trim((string) data_get($row, 'adGroup.id', ''));
            $criterionId = trim((string) ($criterion['criterionId'] ?? ''));
            $metrics = is_array($row['metrics'] ?? null) ? $row['metrics'] : [];

            return [
                'external_account_id' => trim((string) data_get($row, 'customer.id', $account['external_account_id'])),
                'date' => $date,
                'dimension_key' => hash('sha256', implode('|', [$criterionId, $campaignId, $adGroupId, mb_strtolower($keyword)])),
                'criterion_id' => $criterionId ?: null,
                'keyword' => $keyword,
                'normalized_keyword' => mb_strtolower($keyword),
                'match_type' => data_get($criterion, 'keyword.matchType'),
                'status' => $criterion['status'] ?? null,
                'campaign_id' => $campaignId ?: null,
                'campaign_name' => data_get($row, 'campaign.name'),
                'ad_group_id' => $adGroupId ?: null,
                'ad_group_name' => data_get($row, 'adGroup.name'),
                ...$this->googlePerformanceMetrics($metrics),
                'raw_payload' => $row,
            ];
        })->filter()->values()->all();

        return [
            ...$this->totals($spend, $sales),
            'accounts' => [$account],
            'daily_metrics' => array_values($daily),
            'campaign_daily_metrics' => $campaignDaily,
            'search_term_daily_metrics' => $searchTermDaily,
            'keyword_daily_metrics' => $keywordDaily,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function googleSearch(string $customerId, array $headers, string $query, string $label): array
    {
        $rows = [];
        $pageToken = null;
        for ($page = 0; $page < 100; $page++) {
            // Google Ads API v19+ fixes the search page size at 10,000 and
            // rejects requests that explicitly send pageSize.
            $body = ['query' => $query];
            if ($pageToken !== null) {
                $body['pageToken'] = $pageToken;
            }
            $response = $this->http->withHeaders($headers)->acceptJson()->timeout(45)
                ->post(self::GOOGLE_ADS_API."/customers/{$customerId}/googleAds:search", $body);
            $this->assertSuccessful($response, $label);
            $pageRows = array_values(array_filter((array) $response->json('results', []), 'is_array'));
            array_push($rows, ...$pageRows);
            $pageToken = trim((string) $response->json('nextPageToken', '')) ?: null;
            if ($pageToken === null) {
                break;
            }
        }

        return $rows;
    }

    /** @return array<string, mixed>|null */
    private function googleSearchTermRow(array $row, string $fallbackAccount, bool $pmax): ?array
    {
        $term = trim((string) data_get($row, $pmax ? 'campaignSearchTermView.searchTerm' : 'searchTermView.searchTerm', ''));
        $date = trim((string) data_get($row, 'segments.date', ''));
        if ($term === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }
        $campaignId = trim((string) data_get($row, 'campaign.id', ''));
        $adGroupId = trim((string) data_get($row, 'adGroup.id', ''));
        $matchedKeyword = $pmax ? null : data_get($row, 'segments.keyword.info.text');
        $matchType = $pmax ? 'PERFORMANCE_MAX' : data_get($row, 'segments.keyword.info.matchType');
        $metrics = is_array($row['metrics'] ?? null) ? $row['metrics'] : [];

        return [
            'external_account_id' => trim((string) data_get($row, 'customer.id', $fallbackAccount)),
            'date' => $date,
            'dimension_key' => hash('sha256', implode('|', [$pmax ? 'pmax' : 'standard', mb_strtolower($term), $campaignId, $adGroupId, (string) $matchedKeyword])),
            'source_type' => $pmax ? 'PERFORMANCE_MAX' : 'STANDARD',
            'search_term' => $term,
            'normalized_search_term' => mb_strtolower($term),
            'status' => $pmax ? 'PERFORMANCE_MAX' : data_get($row, 'searchTermView.status'),
            'matched_keyword' => $matchedKeyword,
            'match_type' => $matchType,
            'campaign_id' => $campaignId ?: null,
            'campaign_name' => data_get($row, 'campaign.name'),
            'ad_group_id' => $adGroupId ?: null,
            'ad_group_name' => data_get($row, 'adGroup.name'),
            'advertising_channel_type' => data_get($row, 'campaign.advertisingChannelType'),
            ...$this->googlePerformanceMetrics($metrics),
            'raw_payload' => $row,
        ];
    }

    /** @return array{spend: float, revenue: float, impressions: int, clicks: int, conversions: float} */
    private function googlePerformanceMetrics(array $metrics): array
    {
        return [
            'spend' => round(max(0, $this->number($metrics['costMicros'] ?? 0) / 1_000_000), 6),
            'revenue' => round(max(0, $this->number($metrics['conversionsValue'] ?? 0)), 6),
            'impressions' => max(0, (int) round($this->number($metrics['impressions'] ?? 0))),
            'clicks' => max(0, (int) round($this->number($metrics['clicks'] ?? 0))),
            'conversions' => round(max(0, $this->number($metrics['conversions'] ?? 0)), 6),
        ];
    }

    /** @return array{ad_spend: float, attributed_sales: float, accounts: list<array<string, mixed>>, daily_metrics: list<array<string, mixed>>, campaign_daily_metrics: list<array<string, mixed>>, ad_daily_metrics: list<array<string, mixed>>} */
    private function tiktok(Store $store, string $from, string $to): array
    {
        $token = $this->required($store, 'tiktok_ads', 'access_token');
        $advertiserIds = collect(explode(',', $this->required($store, 'tiktok_ads', 'advertiser_ids')))
            ->map(fn (string $id): string => trim($id))
            ->filter()
            ->values();

        if ($advertiserIds->isEmpty()) {
            throw new RuntimeException('TikTok advertiser unavailable.');
        }

        $spend = 0.0;
        $sales = 0.0;
        $accounts = [];
        $daily = [];
        $campaignDaily = [];
        $adDaily = [];
        foreach ($advertiserIds as $advertiserId) {
            $dailyRows = $this->tiktokReport($token, (string) $advertiserId, 'AUCTION_ADVERTISER', ['stat_time_day'], $from, $to);
            $campaignRows = $this->tiktokReport($token, (string) $advertiserId, 'AUCTION_CAMPAIGN', ['campaign_id', 'stat_time_day'], $from, $to);
            $adRows = $this->tiktokReport($token, (string) $advertiserId, 'AUCTION_AD', ['ad_id', 'stat_time_day'], $from, $to);
            $adInformation = $this->tiktokAdInformation(
                $token,
                (string) $advertiserId,
                collect($adRows)->map(fn (array $row): string => trim((string) data_get($row, 'dimensions.ad_id', '')))->filter()->unique()->values()->all(),
            );
            $campaignNames = $this->tiktokCampaignNames(
                $token,
                (string) $advertiserId,
                collect($campaignRows)->map(fn (array $row): string => trim((string) data_get($row, 'dimensions.campaign_id', '')))
                    ->merge(collect($adInformation)->map(fn (array $ad): string => trim((string) ($ad['campaign_id'] ?? ''))))
                    ->filter()->unique()->values()->all(),
            );

            $accounts[] = [
                'external_account_id' => (string) $advertiserId,
                'name' => null,
                'currency' => null,
                'timezone' => null,
                'status' => 'active',
                'raw_payload' => ['advertiser_id' => (string) $advertiserId],
            ];

            foreach ($dailyRows as $row) {
                $metrics = is_array($row) && is_array($row['metrics'] ?? null) ? $row['metrics'] : [];
                $dimensions = is_array($row) && is_array($row['dimensions'] ?? null) ? $row['dimensions'] : [];
                $rowSpend = $this->number($metrics['spend'] ?? 0);
                $rowSales = $rowSpend * $this->number($metrics['complete_payment_roas'] ?? 0);
                $spend += $rowSpend;
                $sales += $rowSales;
                $date = trim((string) ($dimensions['stat_time_day'] ?? ''));
                if ($date !== '') {
                    $normalized = $this->dailyRow(
                        (string) $advertiserId,
                        substr($date, 0, 10),
                        $rowSpend,
                        $rowSales,
                        $metrics['impressions'] ?? 0,
                        $metrics['clicks'] ?? 0,
                        $metrics['complete_payment'] ?? 0,
                        $row,
                    );
                    $normalized['add_to_cart'] = round(max(0, $this->number($metrics['web_event_add_to_cart'] ?? 0)), 6);
                    $normalized['initiate_checkout'] = round(max(0, $this->number($metrics['initiate_checkout'] ?? 0)), 6);
                    $daily[] = $normalized;
                }
            }

            foreach ($campaignRows as $row) {
                $metrics = is_array($row['metrics'] ?? null) ? $row['metrics'] : [];
                $dimensions = is_array($row['dimensions'] ?? null) ? $row['dimensions'] : [];
                $campaignId = trim((string) ($dimensions['campaign_id'] ?? ''));
                $date = substr(trim((string) ($dimensions['stat_time_day'] ?? '')), 0, 10);
                if ($campaignId === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                    continue;
                }
                $rowSpend = max(0, $this->number($metrics['spend'] ?? 0));
                $campaign = $campaignNames[$campaignId] ?? [];
                $campaignDaily[] = [
                    'external_account_id' => (string) $advertiserId,
                    'campaign_id' => $campaignId,
                    'campaign_name' => $campaign['campaign_name'] ?? null,
                    'campaign_status' => $campaign['operation_status'] ?? null,
                    'objective_type' => $campaign['objective_type'] ?? null,
                    'date' => $date,
                    'spend' => round($rowSpend, 6),
                    'attributed_sales' => round($rowSpend * max(0, $this->number($metrics['complete_payment_roas'] ?? 0)), 6),
                    'impressions' => max(0, (int) round($this->number($metrics['impressions'] ?? 0))),
                    'clicks' => max(0, (int) round($this->number($metrics['clicks'] ?? 0))),
                    'conversions' => round(max(0, $this->number($metrics['complete_payment'] ?? 0)), 6),
                    'raw_payload' => $row,
                ];
            }

            foreach ($adRows as $row) {
                $metrics = is_array($row['metrics'] ?? null) ? $row['metrics'] : [];
                $dimensions = is_array($row['dimensions'] ?? null) ? $row['dimensions'] : [];
                $adId = trim((string) ($dimensions['ad_id'] ?? ''));
                $date = substr(trim((string) ($dimensions['stat_time_day'] ?? '')), 0, 10);
                if ($adId === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                    continue;
                }

                $ad = $adInformation[$adId] ?? [];
                $campaignId = trim((string) ($ad['campaign_id'] ?? ''));
                $rowSpend = max(0, $this->number($metrics['spend'] ?? 0));
                $adTexts = collect((array) ($ad['ad_texts'] ?? []))
                    ->map(fn (mixed $text): string => trim((string) $text))->filter()->unique()->values()->all();
                $adDaily[] = [
                    'external_account_id' => (string) $advertiserId,
                    'campaign_id' => $campaignId !== '' ? $campaignId : null,
                    'campaign_name' => $campaignNames[$campaignId]['campaign_name'] ?? null,
                    'adgroup_id' => $ad['adgroup_id'] ?? null,
                    'ad_id' => $adId,
                    'ad_name' => $ad['ad_name'] ?? null,
                    'ad_text' => $ad['ad_text'] ?? null,
                    'ad_texts' => $adTexts,
                    'ad_format' => $ad['ad_format'] ?? $ad['creative_type'] ?? null,
                    'video_id' => $ad['video_id'] ?? null,
                    'date' => $date,
                    'spend' => round($rowSpend, 6),
                    'attributed_sales' => round($rowSpend * max(0, $this->number($metrics['complete_payment_roas'] ?? 0)), 6),
                    'impressions' => max(0, (int) round($this->number($metrics['impressions'] ?? 0))),
                    'clicks' => max(0, (int) round($this->number($metrics['clicks'] ?? 0))),
                    'conversions' => round(max(0, $this->number($metrics['complete_payment'] ?? 0)), 6),
                    'video_play_actions' => max(0, (int) round($this->number($metrics['video_play_actions'] ?? 0))),
                    'video_watched_2s' => max(0, (int) round($this->number($metrics['video_watched_2s'] ?? 0))),
                    'average_video_play' => round(max(0, $this->number($metrics['average_video_play'] ?? 0)), 6),
                    'raw_payload' => ['report' => $row, 'ad' => $ad],
                ];
            }
        }

        return [
            ...$this->totals($spend, $sales),
            'accounts' => $accounts,
            'daily_metrics' => $daily,
            'campaign_daily_metrics' => $campaignDaily,
            'ad_daily_metrics' => $adDaily,
        ];
    }

    /** @param list<string> $adIds @return array<string, array<string, mixed>> */
    private function tiktokAdInformation(string $token, string $advertiserId, array $adIds): array
    {
        $ads = [];
        foreach (array_chunk($adIds, 100) as $ids) {
            try {
                $response = $this->http->withHeaders(['Access-Token' => $token])->acceptJson()->timeout(30)
                    ->get(self::TIKTOK_API.'/ad/get/', [
                        'advertiser_id' => $advertiserId,
                        'filtering' => json_encode(['ad_ids' => array_values($ids)], JSON_THROW_ON_ERROR),
                        'fields' => json_encode([
                            'ad_id', 'ad_name', 'ad_text', 'ad_texts', 'campaign_id', 'adgroup_id',
                            'ad_format', 'creative_type', 'video_id',
                        ], JSON_THROW_ON_ERROR),
                        'page' => 1,
                        'page_size' => 100,
                    ]);
                $this->assertSuccessful($response, 'TikTok ad information');
                if ((int) $response->json('code', -1) !== 0) {
                    continue;
                }
                foreach ((array) $response->json('data.list', []) as $ad) {
                    if (! is_array($ad)) {
                        continue;
                    }
                    $id = trim((string) ($ad['ad_id'] ?? ''));
                    if ($id !== '') {
                        $ads[$id] = $ad;
                    }
                }
            } catch (Throwable) {
                continue;
            }
        }

        return $ads;
    }

    /** @param list<string> $dimensions @return list<array<string, mixed>> */
    private function tiktokReport(string $token, string $advertiserId, string $dataLevel, array $dimensions, string $from, string $to): array
    {
        $metrics = [
            'spend', 'impressions', 'clicks', 'ctr', 'cpc', 'conversion', 'cost_per_conversion',
            'conversion_rate', 'complete_payment', 'complete_payment_roas', 'initiate_checkout',
            'web_event_add_to_cart', 'video_play_actions', 'video_watched_2s', 'video_watched_6s',
            'average_video_play',
        ];
        $rows = [];
        for ($page = 1; $page <= 50; $page++) {
            $response = $this->http->withHeaders(['Access-Token' => $token])->acceptJson()->timeout(30)
                ->get(self::TIKTOK_API.'/report/integrated/get/', [
                    'advertiser_id' => $advertiserId,
                    'report_type' => 'BASIC',
                    'data_level' => $dataLevel,
                    'dimensions' => json_encode($dimensions, JSON_THROW_ON_ERROR),
                    'metrics' => json_encode($metrics, JSON_THROW_ON_ERROR),
                    'start_date' => $from,
                    'end_date' => $to,
                    'page' => $page,
                    'page_size' => 1000,
                ]);
            $this->assertSuccessful($response, 'TikTok Ads');
            if ((int) $response->json('code', -1) !== 0) {
                throw new RuntimeException('TikTok report unavailable.');
            }
            $pageRows = array_values(array_filter((array) $response->json('data.list', []), 'is_array'));
            array_push($rows, ...$pageRows);
            $totalPages = max(1, (int) $response->json('data.page_info.total_page', 1));
            if ($page >= $totalPages || count($pageRows) === 0) {
                break;
            }
        }

        return $rows;
    }

    /** @param list<string> $campaignIds @return array<string, array<string, mixed>> */
    private function tiktokCampaignNames(string $token, string $advertiserId, array $campaignIds): array
    {
        $campaigns = [];
        foreach (array_chunk($campaignIds, 100) as $ids) {
            $response = $this->http->withHeaders(['Access-Token' => $token])->acceptJson()->timeout(30)
                ->get(self::TIKTOK_API.'/campaign/get/', [
                    'advertiser_id' => $advertiserId,
                    'filtering' => json_encode(['campaign_ids' => array_values($ids)], JSON_THROW_ON_ERROR),
                    'page' => 1,
                    'page_size' => 100,
                ]);
            $this->assertSuccessful($response, 'TikTok campaign names');
            if ((int) $response->json('code', -1) !== 0) {
                continue;
            }
            foreach ((array) $response->json('data.list', []) as $campaign) {
                if (! is_array($campaign)) {
                    continue;
                }
                $id = trim((string) ($campaign['campaign_id'] ?? ''));
                if ($id !== '') {
                    $campaigns[$id] = $campaign;
                }
            }
        }

        return $campaigns;
    }

    /** @return array{ad_spend: float, attributed_sales: float} */
    private function criteo(Store $store, string $from, string $to): array
    {
        $clientId = $this->required($store, 'criteo', 'api_key');
        $clientSecret = $this->required($store, 'criteo', 'client_secret');
        $advertisers = collect(explode(',', (string) $this->credentials->value($store, 'criteo', 'advertiser_id')))
            ->map(fn (string $id): array => ['id' => trim($id), 'name' => null])
            ->filter(fn (array $advertiser): bool => $advertiser['id'] !== '')
            ->filter()
            ->values();

        $tokenResponse = $this->http->asForm()->acceptJson()->timeout(20)->post('https://api.criteo.com/oauth2/token', [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'grant_type' => 'client_credentials',
        ]);
        $this->assertSuccessful($tokenResponse, 'Criteo OAuth');
        $token = trim((string) $tokenResponse->json('access_token', ''));
        if ($token === '') {
            throw new RuntimeException('Criteo token unavailable.');
        }

        if ($advertisers->isEmpty()) {
            $advertiserResponse = $this->http->withToken($token)->acceptJson()->timeout(20)
                ->get(self::CRITEO_API.'/advertisers/me');
            $this->assertSuccessful($advertiserResponse, 'Criteo advertisers');
            $advertisers = collect($advertiserResponse->json('data', []))
                ->map(fn (mixed $item): array => is_array($item) ? [
                    'id' => trim((string) ($item['id'] ?? '')),
                    'name' => trim((string) data_get($item, 'attributes.advertiserName', '')) ?: null,
                ] : ['id' => '', 'name' => null])
                ->filter(fn (array $advertiser): bool => $advertiser['id'] !== '')
                ->values();
        }

        if ($advertisers->isEmpty()) {
            throw new RuntimeException('Criteo advertiser unavailable.');
        }

        $spend = 0.0;
        $sales = 0.0;
        $accounts = [];
        $daily = [];
        $campaignDaily = [];
        $metrics = [
            'AdvertiserCost',
            'RevenueGeneratedPc7d',
            'RevenueGeneratedPv24h',
            'Displays',
            'Clicks',
            'SalesPc7d',
            'SalesPv24h',
        ];
        foreach ($advertisers as $advertiser) {
            $advertiserId = (string) $advertiser['id'];
            $response = $this->http->withToken($token)->withHeaders(['Accept' => 'text/csv'])->timeout(35)
                ->post(self::CRITEO_API.'/statistics/report', [
                    'advertiserIds' => (string) $advertiserId,
                    'startDate' => $from,
                    'endDate' => $to,
                    'dimensions' => ['Day'],
                    'metrics' => $metrics,
                    'currency' => 'USD',
                    'timezone' => self::CRITEO_TIMEZONE,
                    'format' => 'csv',
                ]);
            $this->assertSuccessful($response, 'Criteo report');
            $accounts[] = [
                'external_account_id' => (string) $advertiserId,
                'name' => $advertiser['name'],
                'currency' => 'USD',
                'timezone' => self::CRITEO_TIMEZONE,
                'status' => 'active',
                'raw_payload' => ['advertiser_id' => (string) $advertiserId],
            ];
            foreach ($this->csv($response->body()) as $row) {
                $rowSpend = $this->number($row['AdvertiserCost'] ?? 0);
                $rowSales = $this->number($row['RevenueGeneratedPc7d'] ?? 0)
                    + $this->number($row['RevenueGeneratedPv24h'] ?? 0);
                $rowConversions = $this->number($row['SalesPc7d'] ?? 0)
                    + $this->number($row['SalesPv24h'] ?? 0);
                $spend += $rowSpend;
                $sales += $rowSales;
                $date = trim((string) ($row['Day'] ?? ''));
                if ($date !== '') {
                    $daily[] = $this->dailyRow(
                        (string) $advertiserId,
                        substr($date, 0, 10),
                        $rowSpend,
                        $rowSales,
                        $row['Displays'] ?? 0,
                        $row['Clicks'] ?? 0,
                        $rowConversions,
                        $row,
                    );
                }
            }

            $campaignResponse = $this->http->withToken($token)->withHeaders(['Accept' => 'text/csv'])->timeout(35)
                ->post(self::CRITEO_API.'/statistics/report', [
                    'advertiserIds' => (string) $advertiserId,
                    'startDate' => $from,
                    'endDate' => $to,
                    'dimensions' => ['CampaignId', 'Campaign', 'Day'],
                    'metrics' => $metrics,
                    'currency' => 'USD',
                    'timezone' => self::CRITEO_TIMEZONE,
                    'format' => 'csv',
                ]);
            $this->assertSuccessful($campaignResponse, 'Criteo campaign report');
            foreach ($this->csv($campaignResponse->body()) as $row) {
                $date = trim((string) ($row['Day'] ?? ''));
                $campaignId = trim((string) ($row['CampaignId'] ?? ''));
                if ($date === '' || $campaignId === '') {
                    continue;
                }

                $campaignDaily[] = [
                    'external_account_id' => (string) $advertiserId,
                    'campaign_id' => $campaignId,
                    'campaign_name' => trim((string) ($row['Campaign'] ?? '')) ?: null,
                    'date' => substr($date, 0, 10),
                    'spend' => $this->number($row['AdvertiserCost'] ?? 0),
                    'attributed_sales' => $this->number($row['RevenueGeneratedPc7d'] ?? 0)
                        + $this->number($row['RevenueGeneratedPv24h'] ?? 0),
                    'impressions' => max(0, (int) round($this->number($row['Displays'] ?? 0))),
                    'clicks' => max(0, (int) round($this->number($row['Clicks'] ?? 0))),
                    'conversions' => $this->number($row['SalesPc7d'] ?? 0)
                        + $this->number($row['SalesPv24h'] ?? 0),
                    'raw_payload' => $row,
                ];
            }
        }

        return [
            ...$this->totals($spend, $sales),
            'accounts' => $accounts,
            'daily_metrics' => $daily,
            'campaign_daily_metrics' => $campaignDaily,
        ];
    }

    /** @return array{ad_spend: float, attributed_sales: float} */
    private function bing(Store $store, string $from, string $to): array
    {
        $clientId = $this->required($store, 'bing_ads', 'client_id');
        $clientSecret = $this->required($store, 'bing_ads', 'client_secret');
        $refreshToken = $this->required($store, 'bing_ads', 'refresh_token');
        $developerToken = $this->required($store, 'bing_ads', 'developer_token');
        $accountId = (int) $this->required($store, 'bing_ads', 'account_id');
        $customerId = trim((string) $this->credentials->value($store, 'bing_ads', 'customer_id'));

        $tokenResponse = $this->http->asForm()->acceptJson()->timeout(20)
            ->post('https://login.microsoftonline.com/common/oauth2/v2.0/token', [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'refresh_token' => $refreshToken,
                'grant_type' => 'refresh_token',
                'scope' => 'https://ads.microsoft.com/msads.manage offline_access',
            ]);
        $this->assertSuccessful($tokenResponse, 'Bing OAuth');
        $token = trim((string) $tokenResponse->json('access_token', ''));
        if ($token === '') {
            throw new RuntimeException('Bing token unavailable.');
        }

        $headers = [
            'Authorization' => "Bearer {$token}",
            'DeveloperToken' => $developerToken,
            'CustomerAccountId' => (string) $accountId,
        ];
        if ($customerId !== '') {
            $headers['CustomerId'] = $customerId;
        }

        $request = [
            'Type' => 'AccountPerformanceReportRequest',
            'ExcludeColumnHeaders' => false,
            'ExcludeReportFooter' => true,
            'ExcludeReportHeader' => true,
            'Format' => 'Csv',
            'FormatVersion' => '2.0',
            'ReportName' => 'BingAccountDailyPerformance',
            'ReturnOnlyCompleteData' => false,
            'Aggregation' => 'Daily',
            'Columns' => ['TimePeriod', 'AccountId', 'AccountName', 'CurrencyCode', 'Spend', 'Revenue', 'Impressions', 'Clicks'],
            'Scope' => ['AccountIds' => [$accountId]],
            'Time' => [
                'CustomDateRangeStart' => $this->bingDate($from),
                'CustomDateRangeEnd' => $this->bingDate($to),
            ],
        ];

        $rows = $this->bingReportRows($headers, $request, ['Spend', 'Revenue']);
        $conversionRows = $this->bingReportRows($headers, [
            'Type' => 'AccountPerformanceReportRequest',
            'ExcludeColumnHeaders' => false,
            'ExcludeReportFooter' => true,
            'ExcludeReportHeader' => true,
            'Format' => 'Csv',
            'FormatVersion' => '2.0',
            'ReportName' => 'BingAccountConversionGoalDailyPerformance',
            'ReturnOnlyCompleteData' => false,
            'Aggregation' => 'Daily',
            'Columns' => ['TimePeriod', 'AccountId', 'Goal', 'GoalType', 'AllConversionsQualified'],
            'Scope' => ['AccountIds' => [$accountId]],
            'Time' => [
                'CustomDateRangeStart' => $this->bingDate($from),
                'CustomDateRangeEnd' => $this->bingDate($to),
            ],
        ], ['Goal', 'AllConversionsQualified']);
        $goalTotals = $this->bingConversionGoals->totalsByDate($conversionRows);
        $campaignRows = $this->bingReportRows($headers, [
            'Type' => 'CampaignPerformanceReportRequest',
            'ExcludeColumnHeaders' => false,
            'ExcludeReportFooter' => true,
            'ExcludeReportHeader' => true,
            'Format' => 'Csv',
            'FormatVersion' => '2.0',
            'ReportName' => 'BingCampaignDailyPerformance',
            'ReturnOnlyCompleteData' => false,
            'Aggregation' => 'Daily',
            'Columns' => [
                'TimePeriod', 'AccountId', 'CampaignId', 'CampaignName', 'CampaignStatus', 'CampaignType',
                'Spend', 'Revenue', 'Impressions', 'Clicks', 'Ctr', 'AverageCpc', 'Conversions',
            ],
            'Scope' => ['AccountIds' => [$accountId]],
            'Time' => [
                'CustomDateRangeStart' => $this->bingDate($from),
                'CustomDateRangeEnd' => $this->bingDate($to),
            ],
        ], ['CampaignId', 'Spend']);

        $daily = collect($rows)->mapWithKeys(function (array $row) use ($accountId, $goalTotals): array {
            $dailyRow = $this->dailyRow(
                trim((string) ($row['AccountId'] ?? $accountId)),
                trim((string) ($row['TimePeriod'] ?? '')),
                $row['Spend'] ?? 0,
                $row['Revenue'] ?? 0,
                $row['Impressions'] ?? 0,
                $row['Clicks'] ?? 0,
                0,
                ['account_performance' => $row],
            );
            $goals = $goalTotals[$dailyRow['date']] ?? $this->bingConversionGoals->emptyTotals();
            $dailyRow['conversions'] = $goals['purchase'];
            $dailyRow['add_to_cart'] = $goals['add_to_cart'];
            $dailyRow['initiate_checkout'] = $goals['checkout'];
            $dailyRow['raw_payload']['conversion_goals'] = $goals['rows'];

            return $dailyRow['date'] !== '' ? [$dailyRow['date'] => $dailyRow] : [];
        });
        foreach ($goalTotals as $date => $goals) {
            if ($daily->has($date)) {
                continue;
            }
            $daily->put($date, $this->dailyRow(
                (string) $accountId,
                $date,
                0,
                0,
                0,
                0,
                $goals['purchase'],
                ['conversion_goals' => $goals['rows']],
                [
                    'add_to_cart' => $goals['add_to_cart'],
                    'initiate_checkout' => $goals['checkout'],
                ],
            ));
        }

        $campaignDaily = collect($campaignRows)->map(function (array $row) use ($accountId): ?array {
            $campaignId = trim((string) ($row['CampaignId'] ?? ''));
            $date = trim((string) ($row['TimePeriod'] ?? ''));
            if ($campaignId === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                return null;
            }

            return [
                'external_account_id' => trim((string) ($row['AccountId'] ?? $accountId)),
                'campaign_id' => $campaignId,
                'campaign_name' => trim((string) ($row['CampaignName'] ?? '')) ?: null,
                'campaign_status' => trim((string) ($row['CampaignStatus'] ?? '')) ?: null,
                'campaign_type' => trim((string) ($row['CampaignType'] ?? '')) ?: null,
                'date' => $date,
                'spend' => round(max(0, $this->number($row['Spend'] ?? 0)), 6),
                'attributed_sales' => round(max(0, $this->number($row['Revenue'] ?? 0)), 6),
                'impressions' => max(0, (int) round($this->number($row['Impressions'] ?? 0))),
                'clicks' => max(0, (int) round($this->number($row['Clicks'] ?? 0))),
                'conversions' => round(max(0, $this->number($row['Conversions'] ?? 0)), 6),
                'raw_payload' => $row,
            ];
        })->filter()->values()->all();

        return [
            ...$this->totals(
                collect($rows)->sum(fn (array $row): float => $this->number($row['Spend'] ?? 0)),
                collect($rows)->sum(fn (array $row): float => $this->number($row['Revenue'] ?? 0)),
            ),
            'accounts' => [[
                'external_account_id' => (string) $accountId,
                'name' => $rows[0]['AccountName'] ?? null,
                'currency' => $rows[0]['CurrencyCode'] ?? null,
                'timezone' => null,
                'status' => 'active',
                'raw_payload' => ['account_id' => $accountId, 'customer_id' => $customerId],
            ]],
            'daily_metrics' => $daily->sortKeys()->values()->all(),
            'campaign_daily_metrics' => $campaignDaily,
        ];
    }

    private function configured(Store $store, string $key): bool
    {
        $required = match ($key) {
            'facebook' => [['meta_ads', 'access_token']],
            'google' => [
                ['google_ads', 'client_id'], ['google_ads', 'client_secret'], ['google_ads', 'refresh_token'],
                ['google_ads', 'developer_token'], ['google_ads', 'customer_id'],
            ],
            'tiktok' => [['tiktok_ads', 'access_token'], ['tiktok_ads', 'advertiser_ids']],
            'bing' => [
                ['bing_ads', 'client_id'], ['bing_ads', 'client_secret'], ['bing_ads', 'refresh_token'],
                ['bing_ads', 'developer_token'], ['bing_ads', 'account_id'],
            ],
            'criteo' => [['criteo', 'api_key'], ['criteo', 'client_secret']],
            default => [],
        };

        return collect($required)->every(
            fn (array $credential): bool => trim((string) $this->credentials->value($store, $credential[0], $credential[1])) !== '',
        );
    }

    private function required(Store $store, string $provider, string $key): string
    {
        $value = trim((string) $this->credentials->value($store, $provider, $key));
        if ($value === '') {
            throw new RuntimeException('Advertising credential unavailable.');
        }

        return $value;
    }

    private function assertSuccessful(Response $response, string $provider): void
    {
        if ($response->successful()) {
            return;
        }

        $payload = $response->json();
        $status = is_array($payload) ? trim((string) data_get($payload, 'error.status', '')) : '';
        $apiCode = '';

        foreach ((array) data_get($payload, 'error.details', []) as $detail) {
            foreach ((array) data_get($detail, 'errors', []) as $error) {
                foreach ((array) data_get($error, 'errorCode', []) as $code) {
                    if (is_scalar($code) && trim((string) $code) !== '') {
                        $apiCode = trim((string) $code);
                        break 3;
                    }
                }
            }
        }

        $context = collect([$status, $apiCode])->filter()->unique()->implode('/');
        $suffix = $context !== '' ? ", {$context}" : '';

        throw new AdvertisingApiException(
            "{$provider} request failed (HTTP {$response->status()}{$suffix}).",
            $response->status(),
            AdvertisingSyncFailurePolicy::retryAfter($response->header('Retry-After')),
        );
    }

    private function metaPurchaseValue(mixed $values): float
    {
        if (! is_array($values)) {
            return 0.0;
        }

        foreach ($values as $value) {
            if (is_array($value) && ($value['action_type'] ?? null) === 'purchase') {
                return $this->number($value['value'] ?? 0);
            }
        }

        return 0.0;
    }

    /** @return array{Day: int, Month: int, Year: int} */
    private function bingDate(string $date): array
    {
        [$year, $month, $day] = array_map('intval', explode('-', $date));

        return ['Day' => $day, 'Month' => $month, 'Year' => $year];
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $request
     * @param  list<string>  $requiredHeaders
     * @return list<array<string, string>>
     */
    private function bingReportRows(array $headers, array $request, array $requiredHeaders): array
    {
        $submit = $this->http->withHeaders($headers)->acceptJson()->timeout(25)
            ->post(self::BING_REPORTING_API.'/GenerateReport/Submit', ['ReportRequest' => $request]);
        $this->assertSuccessful($submit, 'Bing report submit');
        $reportId = trim((string) $submit->json('ReportRequestId', ''));
        if ($reportId === '') {
            throw new RuntimeException('Bing report id unavailable.');
        }

        $downloadUrl = '';
        for ($attempt = 0; $attempt < 15; $attempt++) {
            usleep(1_500_000);
            $poll = $this->http->withHeaders($headers)->acceptJson()->timeout(20)
                ->post(self::BING_REPORTING_API.'/GenerateReport/Poll', ['ReportRequestId' => $reportId]);
            if (! $poll->successful()) {
                continue;
            }
            $status = (string) $poll->json('ReportRequestStatus.Status', '');
            if ($status === 'Error') {
                throw new RuntimeException('Bing report failed.');
            }
            if ($status === 'Success') {
                $downloadUrl = trim((string) $poll->json('ReportRequestStatus.ReportDownloadUrl', ''));
                break;
            }
        }

        if ($downloadUrl === '') {
            throw new RuntimeException('Bing report timeout.');
        }

        $download = $this->http->timeout(30)->get($downloadUrl);
        $this->assertSuccessful($download, 'Bing report download');
        $body = $this->unzipIfNeeded($download->body(), (string) $download->header('content-type'), $downloadUrl);

        return $this->csv($body, $requiredHeaders);
    }

    /** @return list<array<string, string>> */
    private function csv(string $csv, array $requiredHeaders = []): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($csv)) ?: [];
        $lines = array_values(array_filter($lines, fn (string $line): bool => trim($line) !== ''));
        if ($requiredHeaders !== []) {
            $offset = collect($lines)->search(
                fn (string $line): bool => collect($requiredHeaders)->every(fn (string $header): bool => str_contains($line, $header)),
            );
            $lines = is_int($offset) ? array_slice($lines, $offset) : $lines;
        }
        if (count($lines) < 2) {
            return [];
        }

        $delimiter = str_contains($lines[0], ';') ? ';' : ',';
        $headers = array_map(function (string $header): string {
            $withoutBom = preg_replace('/^\xEF\xBB\xBF/', '', $header) ?? $header;

            return trim($withoutBom, "\"' \t\n\r\0\x0B");
        }, str_getcsv($lines[0], $delimiter, '"', ''));

        return collect(array_slice($lines, 1))->map(function (string $line) use ($headers, $delimiter): array {
            $values = str_getcsv($line, $delimiter, '"', '');

            return collect($headers)->mapWithKeys(
                fn (string $header, int $index): array => [$header => trim((string) ($values[$index] ?? ''))],
            )->all();
        })->all();
    }

    private function unzipIfNeeded(string $body, string $contentType, string $url): string
    {
        if (! str_contains(strtolower($contentType), 'zip') && ! str_ends_with(strtolower($url), '.zip')) {
            return $body;
        }

        $path = tempnam(sys_get_temp_dir(), 'bing-report-');
        if ($path === false) {
            throw new RuntimeException('Temporary report unavailable.');
        }

        try {
            file_put_contents($path, $body);
            $zip = new ZipArchive;
            if ($zip->open($path) !== true || $zip->numFiles < 1) {
                throw new RuntimeException('Bing report archive invalid.');
            }
            $content = $zip->getFromIndex(0);
            $zip->close();

            return is_string($content) ? $content : '';
        } finally {
            @unlink($path);
        }
    }

    private function number(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        $normalized = preg_replace('/[^0-9.\-]/', '', (string) $value);

        return is_numeric($normalized) ? (float) $normalized : 0.0;
    }

    /** @return array{ad_spend: float, attributed_sales: float} */
    private function totals(float $spend, float $sales): array
    {
        return [
            'ad_spend' => round(max(0, $spend), 2),
            'attributed_sales' => round(max(0, $sales), 2),
        ];
    }

    /** @return array<string, mixed> */
    private function dailyRow(
        string $accountId,
        string $date,
        mixed $spend,
        mixed $sales,
        mixed $impressions,
        mixed $clicks,
        mixed $conversions,
        array $rawPayload,
        array $extraMetrics = [],
    ): array {
        try {
            $normalizedDate = trim($date) === '' ? '' : CarbonImmutable::parse($date)->toDateString();
        } catch (Throwable) {
            $normalizedDate = '';
        }

        return [
            'external_account_id' => $accountId,
            'date' => $normalizedDate,
            'spend' => round(max(0, $this->number($spend)), 6),
            'attributed_sales' => round(max(0, $this->number($sales)), 6),
            'conversion_value_by_conversion_date' => round(max(0, $this->number($extraMetrics['conversion_value_by_conversion_date'] ?? 0)), 6),
            'impressions' => max(0, (int) round($this->number($impressions))),
            'clicks' => max(0, (int) round($this->number($clicks))),
            'conversions' => round(max(0, $this->number($conversions)), 6),
            'all_conversions' => round(max(0, $this->number($extraMetrics['all_conversions'] ?? 0)), 6),
            'all_conversions_value' => round(max(0, $this->number($extraMetrics['all_conversions_value'] ?? 0)), 6),
            'all_conversions_value_by_conversion_date' => round(max(0, $this->number($extraMetrics['all_conversions_value_by_conversion_date'] ?? 0)), 6),
            'add_to_cart' => round(max(0, $this->number($extraMetrics['add_to_cart'] ?? 0)), 6),
            'initiate_checkout' => round(max(0, $this->number($extraMetrics['initiate_checkout'] ?? 0)), 6),
            'raw_payload' => $rawPayload,
        ];
    }

    /** @return array{key: string, name: string, configured: bool, available: bool, ad_spend: float, attributed_sales: float, message: string|null} */
    private function channel(
        string $key,
        bool $configured,
        bool $available,
        float $spend,
        float $sales,
        ?string $message,
    ): array {
        return [
            'key' => $key,
            'name' => self::CHANNEL_NAMES[$key],
            'configured' => $configured,
            'available' => $available,
            'ad_spend' => round(max(0, $spend), 2),
            'attributed_sales' => round(max(0, $sales), 2),
            'message' => $message,
        ];
    }
}
