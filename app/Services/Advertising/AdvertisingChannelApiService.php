<?php

namespace App\Services\Advertising;

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

    private const TIKTOK_API = 'https://business-api.tiktok.com/open_api/v1.3';

    private const CRITEO_API = 'https://api.criteo.com/2026-01';

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
     * @return array{accounts: list<array<string, mixed>>, daily_metrics: list<array<string, mixed>>}
     */
    public function syncPayload(Store $store, string $key, string $from, string $to): array
    {
        if (! in_array($key, ['google', 'tiktok', 'bing', 'criteo'], true)) {
            throw new RuntimeException('Unsupported advertising history platform.');
        }
        if (! $this->configured($store, $key)) {
            throw new RuntimeException('Advertising credential unavailable.');
        }

        $payload = match ($key) {
            'google' => $this->google($store, $from, $to),
            'tiktok' => $this->tiktok($store, $from, $to),
            'bing' => $this->bing($store, $from, $to),
            'criteo' => $this->criteo($store, $from, $to),
        };

        return [
            'accounts' => (array) ($payload['accounts'] ?? []),
            'daily_metrics' => (array) ($payload['daily_metrics'] ?? []),
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
    private function google(Store $store, string $from, string $to): array
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

        $query = "SELECT customer.id, customer.descriptive_name, customer.currency_code, customer.time_zone, segments.date, metrics.cost_micros, metrics.conversions_value, metrics.impressions, metrics.clicks, metrics.conversions FROM customer WHERE segments.date BETWEEN '{$from}' AND '{$to}' ORDER BY segments.date ASC";
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
                $daily[] = $this->dailyRow(
                    (string) $account['external_account_id'],
                    $date,
                    $rowSpend,
                    $rowSales,
                    $metrics['impressions'] ?? 0,
                    $metrics['clicks'] ?? 0,
                    $metrics['conversions'] ?? 0,
                    $row,
                );
            }
        }

        return [...$this->totals($spend, $sales), 'accounts' => [$account], 'daily_metrics' => $daily];
    }

    /** @return array{ad_spend: float, attributed_sales: float} */
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
        foreach ($advertiserIds as $advertiserId) {
            $response = $this->http->withHeaders(['Access-Token' => $token])->acceptJson()->timeout(30)
                ->get(self::TIKTOK_API.'/report/integrated/get/', [
                    'advertiser_id' => $advertiserId,
                    'report_type' => 'BASIC',
                    'data_level' => 'AUCTION_ADVERTISER',
                    'dimensions' => json_encode(['stat_time_day'], JSON_THROW_ON_ERROR),
                    'metrics' => json_encode(['spend', 'complete_payment', 'complete_payment_roas', 'impressions', 'clicks'], JSON_THROW_ON_ERROR),
                    'start_date' => $from,
                    'end_date' => $to,
                    'page' => 1,
                    'page_size' => 90,
                ]);
            $this->assertSuccessful($response, 'TikTok Ads');
            if ((int) $response->json('code', -1) !== 0) {
                throw new RuntimeException('TikTok report unavailable.');
            }

            $accounts[] = [
                'external_account_id' => (string) $advertiserId,
                'name' => null,
                'currency' => null,
                'timezone' => null,
                'status' => 'active',
                'raw_payload' => ['advertiser_id' => (string) $advertiserId],
            ];

            foreach ((array) $response->json('data.list', []) as $row) {
                $metrics = is_array($row) && is_array($row['metrics'] ?? null) ? $row['metrics'] : [];
                $dimensions = is_array($row) && is_array($row['dimensions'] ?? null) ? $row['dimensions'] : [];
                $rowSpend = $this->number($metrics['spend'] ?? 0);
                $rowSales = $rowSpend * $this->number($metrics['complete_payment_roas'] ?? 0);
                $spend += $rowSpend;
                $sales += $rowSales;
                $date = trim((string) ($dimensions['stat_time_day'] ?? ''));
                if ($date !== '') {
                    $daily[] = $this->dailyRow(
                        (string) $advertiserId,
                        substr($date, 0, 10),
                        $rowSpend,
                        $rowSales,
                        $metrics['impressions'] ?? 0,
                        $metrics['clicks'] ?? 0,
                        $metrics['complete_payment'] ?? 0,
                        $row,
                    );
                }
            }
        }

        return [...$this->totals($spend, $sales), 'accounts' => $accounts, 'daily_metrics' => $daily];
    }

    /** @return array{ad_spend: float, attributed_sales: float} */
    private function criteo(Store $store, string $from, string $to): array
    {
        $clientId = $this->required($store, 'criteo', 'api_key');
        $clientSecret = $this->required($store, 'criteo', 'client_secret');
        $advertiserIds = collect(explode(',', (string) $this->credentials->value($store, 'criteo', 'advertiser_id')))
            ->map(fn (string $id): string => trim($id))
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

        if ($advertiserIds->isEmpty()) {
            $advertiserResponse = $this->http->withToken($token)->acceptJson()->timeout(20)
                ->get(self::CRITEO_API.'/advertisers/me');
            $this->assertSuccessful($advertiserResponse, 'Criteo advertisers');
            $advertiserIds = collect($advertiserResponse->json('data', []))
                ->map(fn (mixed $item): string => is_array($item) ? trim((string) ($item['id'] ?? '')) : '')
                ->filter()
                ->values();
        }

        if ($advertiserIds->isEmpty()) {
            throw new RuntimeException('Criteo advertiser unavailable.');
        }

        $spend = 0.0;
        $sales = 0.0;
        $accounts = [];
        $daily = [];
        $metrics = ['AdvertiserCost', 'RevenueGeneratedPc7d', 'RevenueGeneratedPv24h', 'Displays', 'Clicks'];
        foreach ($advertiserIds as $advertiserId) {
            $response = $this->http->withToken($token)->withHeaders(['Accept' => 'text/csv'])->timeout(35)
                ->post(self::CRITEO_API.'/statistics/report', [
                    'advertiserIds' => (string) $advertiserId,
                    'startDate' => $from,
                    'endDate' => $to,
                    'dimensions' => ['Day'],
                    'metrics' => $metrics,
                    'currency' => 'USD',
                    'timezone' => 'UTC',
                    'format' => 'csv',
                ]);
            $this->assertSuccessful($response, 'Criteo report');
            $accounts[] = [
                'external_account_id' => (string) $advertiserId,
                'name' => null,
                'currency' => 'USD',
                'timezone' => 'UTC',
                'status' => 'active',
                'raw_payload' => ['advertiser_id' => (string) $advertiserId],
            ];
            foreach ($this->csv($response->body()) as $row) {
                $rowSpend = $this->number($row['AdvertiserCost'] ?? 0);
                $rowSales = $this->number($row['RevenueGeneratedPc7d'] ?? 0)
                    + $this->number($row['RevenueGeneratedPv24h'] ?? 0);
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
                        0,
                        $row,
                    );
                }
            }
        }

        return [...$this->totals($spend, $sales), 'accounts' => $accounts, 'daily_metrics' => $daily];
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
            'ReportName' => 'CampaignChannelPeriodPerformance',
            'ReturnOnlyCompleteData' => false,
            'Aggregation' => 'Daily',
            'Columns' => ['TimePeriod', 'AccountId', 'AccountName', 'CurrencyCode', 'Spend', 'Revenue', 'Impressions', 'Clicks', 'Conversions'],
            'Scope' => ['AccountIds' => [$accountId]],
            'Time' => [
                'CustomDateRangeStart' => $this->bingDate($from),
                'CustomDateRangeEnd' => $this->bingDate($to),
            ],
        ];

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
        $rows = $this->csv($body, true);

        $daily = collect($rows)->map(function (array $row) use ($accountId): array {
            return $this->dailyRow(
                trim((string) ($row['AccountId'] ?? $accountId)),
                trim((string) ($row['TimePeriod'] ?? '')),
                $row['Spend'] ?? 0,
                $row['Revenue'] ?? 0,
                $row['Impressions'] ?? 0,
                $row['Clicks'] ?? 0,
                $row['Conversions'] ?? 0,
                $row,
            );
        })->filter(fn (array $row): bool => $row['date'] !== '')->values()->all();

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
            'daily_metrics' => $daily,
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
        if (! $response->successful()) {
            throw new RuntimeException("{$provider} request failed.");
        }
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

    /** @return list<array<string, string>> */
    private function csv(string $csv, bool $findHeader = false): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($csv)) ?: [];
        $lines = array_values(array_filter($lines, fn (string $line): bool => trim($line) !== ''));
        if ($findHeader) {
            $offset = collect($lines)->search(fn (string $line): bool => str_contains($line, 'Spend') && str_contains($line, 'Revenue'));
            $lines = is_int($offset) ? array_slice($lines, $offset) : $lines;
        }
        if (count($lines) < 2) {
            return [];
        }

        $delimiter = str_contains($lines[0], ';') ? ';' : ',';
        $headers = array_map('trim', str_getcsv($lines[0], $delimiter, '"', ''));

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
            'impressions' => max(0, (int) round($this->number($impressions))),
            'clicks' => max(0, (int) round($this->number($clicks))),
            'conversions' => round(max(0, $this->number($conversions)), 6),
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
