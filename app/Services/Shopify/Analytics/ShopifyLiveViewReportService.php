<?php

namespace App\Services\Shopify\Analytics;

use App\Exceptions\ShopifyApiException;
use App\Models\Store;
use App\Services\Shopify\ShopifyGraphQLClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

class ShopifyLiveViewReportService
{
    private const REPORT_KEYS = ['sales', 'sessions', 'locations', 'sources', 'customers', 'products'];

    private const QUERY = <<<'GRAPHQL'
        query ShopifyLiveViewReports(
          $sales: String!
          $sessions: String!
          $locations: String!
          $sources: String!
          $customers: String!
          $products: String!
        ) {
          sales: shopifyqlQuery(query: $sales) { tableData { rows } parseErrors }
          sessions: shopifyqlQuery(query: $sessions) { tableData { rows } parseErrors }
          locations: shopifyqlQuery(query: $locations) { tableData { rows } parseErrors }
          sources: shopifyqlQuery(query: $sources) { tableData { rows } parseErrors }
          customers: shopifyqlQuery(query: $customers) { tableData { rows } parseErrors }
          products: shopifyqlQuery(query: $products) { tableData { rows } parseErrors }
        }
        GRAPHQL;

    public function __construct(private ShopifyGraphQLClient $client) {}

    /** @return array<string, mixed> */
    public function snapshot(Store $store, CarbonImmutable $now): array
    {
        $date = $now->toDateString();
        $ttl = max(15, (int) config('shopify.live_view_cache_seconds', 30));
        $key = implode(':', [
            'shopify-live-view-v1',
            'organization',
            $store->organization_id,
            'store',
            $store->getKey(),
            $date,
        ]);

        return Cache::remember($key, now()->addSeconds($ttl), fn (): array => $this->load($store, $date));
    }

    /** @return array<string, mixed> */
    private function load(Store $store, string $date): array
    {
        $connection = $store->shopifyConnection;
        if (! $connection || ! in_array($connection->status, ['connected', 'warning'], true)) {
            return $this->unavailable('Shopify 连接不可用。', false);
        }

        if (! in_array('read_reports', $connection->scopes ?? [], true)) {
            return $this->unavailable('缺少 read_reports，请重新授权店铺。', false);
        }

        $range = "SINCE {$date} UNTIL {$date}";

        try {
            $payload = $this->client->query($connection, self::QUERY, [
                'sales' => "FROM sales SHOW total_sales, orders TIMESERIES hour WITH TOTALS {$range} ORDER BY hour",
                'sessions' => "FROM sessions SHOW sessions WHERE human_or_bot_session IN ('human', 'bot') TIMESERIES hour WITH TOTALS {$range} ORDER BY hour",
                'locations' => "FROM sessions SHOW sessions WHERE human_or_bot_session IN ('human', 'bot') GROUP BY session_country, session_region, session_city {$range} ORDER BY sessions DESC LIMIT 3",
                'sources' => "FROM sessions SHOW sessions WHERE human_or_bot_session IN ('human', 'bot') GROUP BY referrer_source, referrer_name {$range} ORDER BY sessions DESC LIMIT 8",
                'customers' => "FROM sales SHOW customers WHERE new_or_returning_customer IS NOT NULL GROUP BY new_or_returning_customer WITH TOTALS {$range} ORDER BY new_or_returning_customer ASC LIMIT 2",
                'products' => "FROM sales SHOW total_sales WHERE product_title IS NOT NULL GROUP BY product_title, product_vendor WITH TOTALS {$range} ORDER BY total_sales DESC LIMIT 10",
            ], 30);
        } catch (ShopifyApiException) {
            return $this->unavailable('Shopify 实时统计暂时不可用。', true);
        } catch (Throwable) {
            return $this->unavailable('Shopify 实时统计暂时不可用。', true);
        }

        $reports = collect(self::REPORT_KEYS)->mapWithKeys(
            fn (string $key): array => [$key => $this->report($payload, $key)],
        )->all();
        $errors = collect($reports)
            ->filter(fn (array $report): bool => ! $report['available'])
            ->mapWithKeys(fn (array $report, string $key): array => [$key => $report['error']])
            ->all();

        return [
            'scope_granted' => true,
            'available' => count($errors) < count(self::REPORT_KEYS),
            'complete' => $errors === [],
            'source' => 'shopifyql',
            'reports' => $reports,
            'errors' => $errors,
            'date' => $date,
            'fetched_at' => now()->toIso8601String(),
        ];
    }

    /** @return array{available: bool, rows: list<array<string, mixed>>, error: string|null} */
    private function report(array $payload, string $key): array
    {
        $result = data_get($payload, "data.{$key}");
        $parseErrors = is_array($result['parseErrors'] ?? null) ? $result['parseErrors'] : [];
        $rows = data_get($result, 'tableData.rows');

        if ($parseErrors !== []) {
            return [
                'available' => false,
                'rows' => [],
                'error' => 'ShopifyQL 查询不支持当前指标。',
            ];
        }

        return [
            'available' => is_array($rows),
            'rows' => is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [],
            'error' => is_array($rows) ? null : 'ShopifyQL 未返回统计数据。',
        ];
    }

    /** @return array<string, mixed> */
    private function unavailable(string $message, bool $scopeGranted): array
    {
        $reports = collect(self::REPORT_KEYS)->mapWithKeys(fn (string $key): array => [$key => [
            'available' => false,
            'rows' => [],
            'error' => $message,
        ]])->all();

        return [
            'scope_granted' => $scopeGranted,
            'available' => false,
            'complete' => false,
            'source' => 'shopifyql',
            'reports' => $reports,
            'errors' => array_fill_keys(self::REPORT_KEYS, $message),
            'date' => null,
            'fetched_at' => null,
        ];
    }
}
