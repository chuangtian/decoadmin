<?php

namespace App\Services\SeoAnalytics;

use App\Models\Store;
use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GoogleSeoApiClient
{
    public function __construct(private SeoAnalyticsConfigurationService $configuration) {}

    /**
     * @param  list<string>  $dimensions
     * @param  list<array{dimension: string, operator: string, expression: string}>  $filters
     * @return list<array<string, mixed>>
     */
    public function gscRows(Store $store, string $from, string $to, array $dimensions, array $filters = [], string $searchType = 'web'): array
    {
        $all = [];
        foreach ($this->gscRowPages($store, $from, $to, $dimensions, $filters, $searchType) as $rows) {
            $all = array_merge($all, $rows);
        }

        return $all;
    }

    /**
     * @param  list<string>  $dimensions
     * @param  list<array{dimension: string, operator: string, expression: string}>  $filters
     * @return Generator<int, list<array<string, mixed>>>
     */
    public function gscRowPages(Store $store, string $from, string $to, array $dimensions, array $filters = [], string $searchType = 'web'): Generator
    {
        $config = $this->configuration->forStore($store);
        $url = 'https://www.googleapis.com/webmasters/v3/sites/'.rawurlencode((string) $config['gsc_site_url']).'/searchAnalytics/query';
        $limit = min(25000, max(1000, (int) config('services.google_search_console.detail_row_limit', 25000)));
        $startRow = 0;

        do {
            $body = [
                'startDate' => $from,
                'endDate' => $to,
                'type' => in_array($searchType, ['web', 'image', 'video', 'news'], true) ? $searchType : 'web',
                'dataState' => 'final',
                'dimensions' => $dimensions,
                'rowLimit' => $limit,
                'startRow' => $startRow,
            ];
            if ($filters !== []) {
                $body['dimensionFilterGroups'] = [['groupType' => 'and', 'filters' => $filters]];
            }
            $json = $this->request()
                ->withToken($this->gscAccessToken($store, $config))
                ->post($url, $body)
                ->throw()
                ->json();
            $rows = is_array($json['rows'] ?? null) ? $json['rows'] : [];
            if ($rows !== []) {
                yield $rows;
            }
            $startRow += count($rows);
        } while (count($rows) === $limit && $startRow < 250000);
    }

    /**
     * @param  list<string>  $dimensions
     * @param  list<string>  $metrics
     * @param  list<array{dimension: string, values: list<string>}>  $inListFilters
     * @return list<array<string, mixed>>
     */
    public function ga4Rows(Store $store, string $from, string $to, array $dimensions, array $metrics, array $inListFilters = []): array
    {
        $config = $this->configuration->forStore($store);
        $url = 'https://analyticsdata.googleapis.com/v1beta/properties/'.rawurlencode((string) $config['ga4_property_id']).':runReport';
        $limit = 100000;
        $offset = 0;
        $all = [];

        do {
            $body = [
                'dateRanges' => [['startDate' => $from, 'endDate' => $to]],
                'dimensions' => array_map(fn (string $name): array => ['name' => $name], $dimensions),
                'metrics' => array_map(fn (string $name): array => ['name' => $name], $metrics),
                'limit' => $limit,
                'offset' => $offset,
            ];
            if ($inListFilters !== []) {
                $expressions = array_map(fn (array $filter): array => [
                    'filter' => [
                        'fieldName' => $filter['dimension'],
                        'inListFilter' => ['values' => $filter['values'], 'caseSensitive' => true],
                    ],
                ], $inListFilters);
                $body['dimensionFilter'] = count($expressions) === 1 ? $expressions[0] : ['andGroup' => ['expressions' => $expressions]];
            }
            $json = $this->request()
                ->withToken($this->ga4AccessToken($store, $config))
                ->post($url, $body)
                ->throw()
                ->json();
            $rows = $this->normalizeGa4Rows($json, $dimensions, $metrics);
            $all = array_merge($all, $rows);
            $offset += count($rows);
            $rowCount = (int) ($json['rowCount'] ?? count($rows));
        } while ($rows !== [] && $offset < $rowCount);

        return $all;
    }

    /** @param array<string, mixed> $config */
    private function gscAccessToken(Store $store, array $config): string
    {
        $key = "seo:gsc-token:{$store->getKey()}:".hash('sha256', (string) $config['gsc_refresh_token']);

        return Cache::remember($key, now()->addMinutes(50), function () use ($config): string {
            $json = $this->request()->asForm()->post('https://oauth2.googleapis.com/token', [
                'client_id' => $config['gsc_client_id'],
                'client_secret' => $config['gsc_client_secret'],
                'refresh_token' => $config['gsc_refresh_token'],
                'grant_type' => 'refresh_token',
            ])->throw()->json();
            $token = trim((string) ($json['access_token'] ?? ''));
            throw_if($token === '', new RuntimeException('Google Search Console 未返回访问令牌。'));

            return $token;
        });
    }

    /** @param array<string, mixed> $config */
    private function ga4AccessToken(Store $store, array $config): string
    {
        $key = "seo:ga4-token:{$store->getKey()}:".hash('sha256', (string) $config['ga4_service_account_json']);

        return Cache::remember($key, now()->addMinutes(50), function () use ($config): string {
            $account = json_decode((string) $config['ga4_service_account_json'], true);
            if (! is_array($account) || blank($account['client_email'] ?? null) || blank($account['private_key'] ?? null)) {
                throw new RuntimeException('GA4 服务账号 JSON 无效。');
            }
            $now = time();
            $tokenUri = (string) ($account['token_uri'] ?? 'https://oauth2.googleapis.com/token');
            $header = $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
            $payload = $this->base64Url(json_encode([
                'iss' => $account['client_email'],
                'scope' => 'https://www.googleapis.com/auth/analytics.readonly',
                'aud' => $tokenUri,
                'iat' => $now,
                'exp' => $now + 3600,
            ], JSON_THROW_ON_ERROR));
            $unsigned = $header.'.'.$payload;
            $signed = openssl_sign($unsigned, $signature, (string) $account['private_key'], OPENSSL_ALGO_SHA256);
            throw_unless($signed, new RuntimeException('GA4 服务账号签名失败。'));
            $assertion = $unsigned.'.'.$this->base64Url($signature);
            $json = $this->request()->asForm()->post($tokenUri, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ])->throw()->json();
            $token = trim((string) ($json['access_token'] ?? ''));
            throw_if($token === '', new RuntimeException('GA4 未返回访问令牌。'));

            return $token;
        });
    }

    /** @param array<string, mixed> $json @param list<string> $dimensions @param list<string> $metrics @return list<array<string, mixed>> */
    private function normalizeGa4Rows(array $json, array $dimensions, array $metrics): array
    {
        return collect($json['rows'] ?? [])->map(function (array $row) use ($dimensions, $metrics): array {
            $normalized = [];
            foreach ($dimensions as $index => $name) {
                $normalized[$name] = $row['dimensionValues'][$index]['value'] ?? '';
            }
            foreach ($metrics as $index => $name) {
                $normalized[$name] = $row['metricValues'][$index]['value'] ?? '0';
            }

            return $normalized;
        })->all();
    }

    private function request(): PendingRequest
    {
        return Http::acceptJson()->timeout(90)->retry(3, 500, throw: false);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
