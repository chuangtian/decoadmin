<?php

namespace App\Services\Shopify\Analytics;

use App\Exceptions\ShopifyApiException;
use App\Models\ShopifyConnection;
use App\Models\Store;
use App\Services\Shopify\ShopifyGraphQLClient;
use Illuminate\Support\Facades\Cache;
use Throwable;

class ShopifyAnalyticsReportService
{
    /** @var array<string, string> */
    private const REPORT_QUERIES = [
        'acquisition-by-source' => "FROM sessions SHOW sessions, online_store_visitors, sessions_that_completed_checkout, conversion_rate WHERE human_or_bot_session = 'human' GROUP BY referrer_source, referrer_name {range} ORDER BY sessions DESC LIMIT 100",
        'acquisition-by-location' => "FROM sessions SHOW sessions, online_store_visitors, sessions_that_completed_checkout, conversion_rate WHERE human_or_bot_session = 'human' GROUP BY session_country, session_region {range} ORDER BY sessions DESC LIMIT 100",
        'behavior-by-device' => "FROM sessions SHOW sessions, pageviews, pageviews_per_session, average_session_duration, bounce_rate, conversion_rate WHERE human_or_bot_session = 'human' GROUP BY session_device_type {range} ORDER BY sessions DESC LIMIT 50",
        'pos-sales-by-location' => "FROM sales SHOW net_sales, total_sales, orders WHERE sales_channel = 'Point of Sale' GROUP BY pos_location_id, pos_location_name {range} ORDER BY total_sales DESC LIMIT 100",
        'pos-sales-by-staff' => "FROM sales SHOW net_sales, total_sales, orders, net_items_sold WHERE sales_channel = 'Point of Sale' GROUP BY staff_id, staff_member_name {range} ORDER BY total_sales DESC LIMIT 100",
        'behavior-by-landing-page' => "FROM sessions SHOW sessions, pageviews, sessions_with_cart_additions, sessions_that_completed_checkout, conversion_rate WHERE human_or_bot_session = 'human' GROUP BY landing_page_type, landing_page_path {range} ORDER BY sessions DESC LIMIT 100",
        'performance-by-page' => 'FROM web_performance SHOW page_loads, lcp_p75_ms, inp_p75_ms, p75_cls, cls_poor_view_count GROUP BY page_type, page_path {range} ORDER BY page_loads DESC LIMIT 100',
        'performance-by-device' => 'FROM web_performance SHOW page_loads, lcp_p75_ms, inp_p75_ms, p75_cls GROUP BY device_type, browser_family {range} ORDER BY page_loads DESC LIMIT 50',
        'campaign-attributed-sales' => 'FROM campaign_sales SHOW campaign_last_click_total_sales, campaign_last_click_order_count, campaign_last_click_total_average_order_value GROUP BY utm_campaign, utm_source {range} ORDER BY campaign_last_click_total_sales DESC LIMIT 100',
        'campaign-attributed-sessions' => 'FROM campaign_sessions SHOW campaign_sessions, campaign_online_store_visitors, campaign_pageviews, campaign_sessions_that_completed_checkout, campaign_conversion_rate GROUP BY utm_campaign, utm_source {range} ORDER BY campaign_sessions DESC LIMIT 100',
        'marketing-engagement-performance' => 'FROM marketing_engagements SHOW engagements_total_sales, engagements_orders, engagements_ad_spend, engagements_clicks, engagements_impressions GROUP BY marketing_platform, marketing_activity_title {range} ORDER BY engagements_total_sales DESC LIMIT 100',
    ];

    private const SHOPIFYQL_QUERY = <<<'GRAPHQL'
        query BusinessAnalyticsReport($query: String!) {
          shopifyqlQuery(query: $query) {
            tableData {
              columns { name dataType displayName }
              rows
            }
            parseErrors
          }
        }
        GRAPHQL;

    private const ANALYTICS_OVERVIEW_QUERY = <<<'GRAPHQL'
        query AnalyticsOverviewReports(
          $acquisition: String!
          $devices: String!
          $locations: String!
          $posLocations: String!
          $posStaff: String!
        ) {
          acquisition: shopifyqlQuery(query: $acquisition) { tableData { rows } parseErrors }
          devices: shopifyqlQuery(query: $devices) { tableData { rows } parseErrors }
          locations: shopifyqlQuery(query: $locations) { tableData { rows } parseErrors }
          posLocations: shopifyqlQuery(query: $posLocations) { tableData { rows } parseErrors }
          posStaff: shopifyqlQuery(query: $posStaff) { tableData { rows } parseErrors }
        }
        GRAPHQL;

    public function __construct(private ShopifyGraphQLClient $client) {}

    /**
     * @param  array{local_start: mixed, local_end: mixed}  $period
     * @return array<string, mixed>
     */
    public function overview(Store $store, array $period): array
    {
        $connection = $store->shopifyConnection;
        $scopes = $connection?->scopes ?? [];

        if (! $connection || ! in_array($connection->status, ['connected', 'warning'], true)) {
            return $this->unavailable(false, 'Shopify 连接不可用。');
        }

        if (! in_array('read_reports', $scopes, true)) {
            return $this->unavailable(false, '缺少 read_reports。');
        }

        $from = $period['local_start']->toDateString();
        $to = $period['local_end']->toDateString();
        $cacheKey = "shopify-analytics-report:{$store->getKey()}:{$from}:{$to}:v1";

        return Cache::remember($cacheKey, now()->addMinutes(5), function () use ($connection, $from, $to): array {
            $range = "SINCE {$from} UNTIL {$to}";
            $reports = [
                'channels' => $this->run($connection, "FROM sales SHOW net_sales, total_sales, orders GROUP BY sales_channel {$range} ORDER BY total_sales DESC LIMIT 100"),
                'pos_locations' => $this->run($connection, "FROM sales SHOW net_sales, total_sales, orders WHERE sales_channel = 'Point of Sale' GROUP BY pos_location_id, pos_location_name {$range} ORDER BY total_sales DESC LIMIT 100"),
                'pos_staff' => $this->run($connection, "FROM sales SHOW net_sales, total_sales, orders, net_items_sold WHERE sales_channel = 'Point of Sale' GROUP BY staff_id, staff_member_name {$range} ORDER BY total_sales DESC LIMIT 100"),
                'traffic' => $this->run($connection, "FROM sessions SHOW sessions, pageviews, sessions_with_cart_additions, sessions_that_reached_checkout, sessions_that_completed_checkout, conversion_rate WHERE human_or_bot_session = 'human' {$range}"),
                'search_queries' => $this->run($connection, "FROM searches SHOW searches GROUP BY search_query WITH TOTALS {$range} ORDER BY searches DESC LIMIT 50"),
                'search_funnel' => $this->run($connection, "FROM search_conversions SHOW sessions_with_searches, search_sessions_with_clicks, search_sessions_with_cart_additions, search_sessions_that_completed_checkout, search_conversion_rate {$range}"),
            ];
            $errors = collect($reports)
                ->filter(fn (array $report): bool => ! $report['ok'])
                ->mapWithKeys(fn (array $report, string $key): array => [$key => $report['error']])
                ->all();

            return [
                'scope_granted' => true,
                'available' => count($errors) < count($reports),
                'complete' => $errors === [],
                'source' => 'shopifyql',
                'channels' => $reports['channels']['ok'] ? $this->channels($reports['channels']['rows']) : null,
                'pos_locations' => $reports['pos_locations']['ok'] ? $this->posLocations($reports['pos_locations']['rows']) : null,
                'pos_staff' => $reports['pos_staff']['ok'] ? $this->posStaff($reports['pos_staff']['rows']) : null,
                'traffic' => $reports['traffic']['ok'] ? $this->traffic($reports['traffic']['rows']) : null,
                'search' => $reports['search_queries']['ok'] && $reports['search_funnel']['ok']
                    ? $this->search($reports['search_queries']['rows'], $reports['search_funnel']['rows'])
                    : null,
                'funnel' => $reports['traffic']['ok'] ? $this->funnel($reports['traffic']['rows']) : null,
                'errors' => $errors,
            ];
        });
    }

    /**
     * Run one bounded, catalog-backed ShopifyQL report. The report key is
     * whitelisted above; callers cannot supply arbitrary ShopifyQL.
     *
     * @return array{scope_granted: bool, available: bool, source: string, rows: list<array<string, mixed>>, error: string|null}
     */
    public function report(Store $store, string $report, string $from, string $to): array
    {
        $query = self::REPORT_QUERIES[$report] ?? null;
        abort_unless($query, 404);

        $connection = $store->shopifyConnection;
        $scopes = $connection?->scopes ?? [];

        if (! $connection || ! in_array($connection->status, ['connected', 'warning'], true)) {
            return $this->unavailableReport(false, 'Shopify 连接不可用。');
        }

        if (! in_array('read_reports', $scopes, true)) {
            return $this->unavailableReport(false, '缺少 read_reports，请重新授权店铺。');
        }

        $range = "SINCE {$from} UNTIL {$to}";
        $cacheKey = "shopify-report:{$store->getKey()}:{$report}:{$from}:{$to}:v1";

        return Cache::remember($cacheKey, now()->addMinutes(5), function () use ($connection, $query, $range): array {
            $result = $this->run($connection, str_replace('{range}', $range, $query));

            return [
                'scope_granted' => true,
                'available' => $result['ok'],
                'source' => 'shopifyql',
                'rows' => $result['rows'],
                'error' => $result['error'],
            ];
        });
    }

    /** @return array<string, array{scope_granted: bool, available: bool, source: string, rows: list<array<string, mixed>>, error: string|null}> */
    public function analyticsOverview(Store $store, string $from, string $to): array
    {
        $connection = $store->shopifyConnection;
        $scopes = $connection?->scopes ?? [];
        $keys = ['acquisition', 'devices', 'locations', 'pos_locations', 'pos_staff'];

        if (! $connection || ! in_array($connection->status, ['connected', 'warning'], true)) {
            return $this->unavailableReports($keys, false, 'Shopify 连接不可用。');
        }

        if (! in_array('read_reports', $scopes, true)) {
            return $this->unavailableReports($keys, false, '缺少 read_reports，请重新授权店铺。');
        }

        $cacheKey = "shopify-analytics-overview:{$store->getKey()}:{$from}:{$to}:v1";

        return Cache::remember($cacheKey, now()->addMinutes(5), function () use ($connection, $from, $to, $keys): array {
            $range = "SINCE {$from} UNTIL {$to}";
            $variables = [
                'acquisition' => str_replace('{range}', $range, self::REPORT_QUERIES['acquisition-by-source']),
                'devices' => str_replace('{range}', $range, self::REPORT_QUERIES['behavior-by-device']),
                'locations' => str_replace('{range}', $range, self::REPORT_QUERIES['acquisition-by-location']),
                'posLocations' => str_replace('{range}', $range, self::REPORT_QUERIES['pos-sales-by-location']),
                'posStaff' => str_replace('{range}', $range, self::REPORT_QUERIES['pos-sales-by-staff']),
            ];

            try {
                $payload = $this->client->query($connection, self::ANALYTICS_OVERVIEW_QUERY, $variables, 30);

                return [
                    'acquisition' => $this->tableReport($payload, 'acquisition'),
                    'devices' => $this->tableReport($payload, 'devices'),
                    'locations' => $this->tableReport($payload, 'locations'),
                    'pos_locations' => $this->tableReport($payload, 'posLocations'),
                    'pos_staff' => $this->tableReport($payload, 'posStaff'),
                ];
            } catch (ShopifyApiException $exception) {
                return $this->unavailableReports($keys, true, $exception->getMessage());
            } catch (Throwable) {
                return $this->unavailableReports($keys, true, 'Shopify 报表暂时不可用。');
            }
        });
    }

    /** @return array{scope_granted: bool, available: bool, source: string, rows: array<never, never>, error: string} */
    private function unavailableReport(bool $scopeGranted, string $message): array
    {
        return [
            'scope_granted' => $scopeGranted,
            'available' => false,
            'source' => 'shopifyql',
            'rows' => [],
            'error' => $message,
        ];
    }

    /** @param list<string> $keys */
    private function unavailableReports(array $keys, bool $scopeGranted, string $message): array
    {
        return collect($keys)->mapWithKeys(
            fn (string $key): array => [$key => $this->unavailableReport($scopeGranted, $message)],
        )->all();
    }

    /** @return array{scope_granted: bool, available: bool, source: string, rows: list<array<string, mixed>>, error: string|null} */
    private function tableReport(array $payload, string $alias): array
    {
        $result = data_get($payload, "data.{$alias}");
        $parseErrors = is_array($result['parseErrors'] ?? null) ? $result['parseErrors'] : [];

        if ($parseErrors !== []) {
            return $this->unavailableReport(true, implode('; ', array_map('strval', $parseErrors)));
        }

        $rows = data_get($result, 'tableData.rows', []);

        return [
            'scope_granted' => true,
            'available' => is_array($rows),
            'source' => 'shopifyql',
            'rows' => is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [],
            'error' => is_array($rows) ? null : 'ShopifyQL 未返回表格数据。',
        ];
    }

    /** @return array<string, mixed> */
    private function unavailable(bool $scopeGranted, string $message): array
    {
        return [
            'scope_granted' => $scopeGranted,
            'available' => false,
            'complete' => false,
            'source' => 'local',
            'channels' => null,
            'pos_locations' => null,
            'pos_staff' => null,
            'traffic' => null,
            'search' => null,
            'funnel' => null,
            'errors' => ['connection' => $message],
        ];
    }

    /** @return array{ok: bool, rows: list<array<string, mixed>>, error: string|null} */
    private function run(ShopifyConnection $connection, string $shopifyql): array
    {
        try {
            $payload = $this->client->query($connection, self::SHOPIFYQL_QUERY, ['query' => $shopifyql], 30);
            $result = data_get($payload, 'data.shopifyqlQuery');
            $parseErrors = is_array($result['parseErrors'] ?? null) ? $result['parseErrors'] : [];

            if ($parseErrors !== []) {
                return ['ok' => false, 'rows' => [], 'error' => implode('; ', array_map('strval', $parseErrors))];
            }

            $rows = data_get($result, 'tableData.rows', []);

            return [
                'ok' => is_array($rows),
                'rows' => is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [],
                'error' => is_array($rows) ? null : 'ShopifyQL 未返回表格数据。',
            ];
        } catch (ShopifyApiException $exception) {
            return ['ok' => false, 'rows' => [], 'error' => $exception->getMessage()];
        } catch (Throwable) {
            return ['ok' => false, 'rows' => [], 'error' => 'Shopify 报表暂时不可用。'];
        }
    }

    /** @param list<array<string, mixed>> $rows */
    private function channels(array $rows): array
    {
        return collect($rows)->map(fn (array $row): array => [
            'key' => (string) ($row['sales_channel'] ?? 'unknown'),
            'name' => (string) ($row['sales_channel'] ?? '未知渠道'),
            'orders' => $this->integer($row['orders'] ?? 0),
            'net_sales' => $this->decimal($row['net_sales'] ?? 0),
            'total_sales' => $this->decimal($row['total_sales'] ?? 0),
        ])->values()->all();
    }

    /** @param list<array<string, mixed>> $rows */
    private function posLocations(array $rows): array
    {
        return collect($rows)->filter(fn (array $row): bool => filled($row['pos_location_id'] ?? null))
            ->map(fn (array $row): array => [
                'id' => (string) $row['pos_location_id'],
                'name' => (string) ($row['pos_location_name'] ?? '未命名 POS 地点'),
                'orders' => $this->integer($row['orders'] ?? 0),
                'net_sales' => $this->decimal($row['net_sales'] ?? 0),
                'total_sales' => $this->decimal($row['total_sales'] ?? 0),
            ])->values()->all();
    }

    /** @param list<array<string, mixed>> $rows */
    private function posStaff(array $rows): array
    {
        return collect($rows)->filter(fn (array $row): bool => filled($row['staff_id'] ?? null))
            ->map(fn (array $row): array => [
                'id' => (string) $row['staff_id'],
                'name' => (string) ($row['staff_member_name'] ?? '未命名 POS 员工'),
                'orders' => $this->integer($row['orders'] ?? 0),
                'units' => $this->integer($row['net_items_sold'] ?? 0),
                'attributed_sales' => $this->decimal($row['net_sales'] ?? 0),
            ])->values()->all();
    }

    /** @param list<array<string, mixed>> $rows */
    private function traffic(array $rows): array
    {
        $row = $rows[0] ?? [];

        return [
            'sessions' => $this->integer($row['sessions'] ?? 0),
            'page_views' => $this->integer($row['pageviews'] ?? 0),
            'product_view_sessions' => 0,
            'converted_sessions' => $this->integer($row['sessions_that_completed_checkout'] ?? 0),
            'conversion_rate' => $this->percent($row['conversion_rate'] ?? 0),
        ];
    }

    /** @param list<array<string, mixed>> $queryRows @param list<array<string, mixed>> $funnelRows */
    private function search(array $queryRows, array $funnelRows): array
    {
        $funnel = $funnelRows[0] ?? [];

        return [
            'searches' => $this->integer($queryRows[0]['searches__totals'] ?? collect($queryRows)->sum(
                fn (array $row): int => $this->integer($row['searches'] ?? 0),
            )),
            'sessions' => $this->integer($funnel['sessions_with_searches'] ?? 0),
            'converted_sessions' => $this->integer($funnel['search_sessions_that_completed_checkout'] ?? 0),
            'conversion_rate' => $this->percent($funnel['search_conversion_rate'] ?? 0),
            'top_queries' => collect($queryRows)->map(fn (array $row): array => [
                'query' => $this->redact((string) ($row['search_query'] ?? '')),
                'searches' => $this->integer($row['searches'] ?? 0),
                'sessions' => null,
            ])->filter(fn (array $row): bool => $row['query'] !== '')->values()->all(),
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    private function funnel(array $rows): array
    {
        $row = $rows[0] ?? [];
        $total = $this->integer($row['sessions'] ?? 0);
        $stages = [
            ['key' => 'sessions', 'label' => '访问 Session', 'value' => $total],
            ['key' => 'product_added_to_cart', 'label' => '加入购物车', 'value' => $this->integer($row['sessions_with_cart_additions'] ?? 0)],
            ['key' => 'checkout_started', 'label' => '到达结账', 'value' => $this->integer($row['sessions_that_reached_checkout'] ?? 0)],
            ['key' => 'checkout_completed', 'label' => '完成购买', 'value' => $this->integer($row['sessions_that_completed_checkout'] ?? 0)],
        ];

        return collect($stages)->map(fn (array $stage): array => [
            'key' => $stage['key'],
            'label' => $stage['label'],
            'sessions' => $stage['value'],
            'rate' => $total > 0 ? round($stage['value'] / $total * 100, 2) : 0.0,
        ])->all();
    }

    private function integer(mixed $value): int
    {
        return is_numeric($value) ? (int) round((float) $value) : 0;
    }

    private function decimal(mixed $value): float
    {
        return is_numeric($value) ? round((float) $value, 2) : 0.0;
    }

    private function percent(mixed $value): float
    {
        $number = is_numeric($value) ? (float) $value : 0.0;

        return round(abs($number) <= 1 ? $number * 100 : $number, 2);
    }

    private function redact(string $query): string
    {
        $query = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[email]', $query) ?? '';

        return trim(preg_replace('/(?<!\d)(?:\+?\d[\d\s().-]{7,}\d)(?!\d)/', '[phone]', $query) ?? '');
    }
}
