<?php

namespace App\Services\Shopify\Analytics;

use App\Exceptions\ShopifyApiException;
use App\Jobs\RefreshShopifyAnalyticsSnapshot;
use App\Models\AnalyticsSnapshot;
use App\Models\ShopifyConnection;
use App\Models\Store;
use App\Services\AnalyticsCacheVersionService;
use App\Services\Shopify\ShopifyGraphQLClient;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Throwable;

class ShopifyAnalyticsReportService
{
    private const BUSINESS_OVERVIEW_REPORT = 'business-overview';

    private const BUSINESS_OVERVIEW_SCHEMA_VERSION = 3;

    private const ANALYTICS_OVERVIEW_REPORT = 'analytics-overview';

    private const ANALYTICS_OVERVIEW_SCHEMA_VERSION = 3;

    private const CATALOG_REPORT_SCHEMA_VERSION = 2;

    /** @var list<string> */
    private const ANALYTICS_CACHE_REPORTS = [
        'catalog:core-sales-timeseries',
        'catalog:core-sales-year-comparison',
        'catalog:product-sales',
        'catalog:vendor-sales',
        'catalog:product-type-sales',
        'catalog:customer-overview',
        'catalog:customer-returning-rate',
    ];

    /** @var array<string, string> */
    private const REPORT_QUERIES = [
        'core-sales-timeseries' => 'FROM sales SHOW total_sales, orders, average_order_value, gross_sales, discounts, returns, net_sales, taxes, shipping_charges TIMESERIES day WITH TOTALS, PERCENT_CHANGE {range} COMPARE TO previous_period ORDER BY day',
        'core-sales-year-comparison' => 'FROM sales SHOW total_sales, orders, average_order_value, gross_sales, discounts, returns, net_sales, taxes, shipping_charges TIMESERIES day WITH TOTALS, PERCENT_CHANGE {range} COMPARE TO previous_year ORDER BY day',
        'product-sales' => 'FROM sales SHOW net_items_sold, gross_sales, discounts, sales_reversals, net_sales, taxes, total_sales WHERE product_title IS NOT NULL GROUP BY product_title, product_vendor, product_type WITH TOTALS, PERCENT_CHANGE {range} COMPARE TO previous_period ORDER BY net_sales DESC LIMIT 200',
        'product-variant-sales' => 'FROM sales SHOW net_items_sold, gross_sales, discounts, sales_reversals, net_sales, taxes, total_sales WHERE product_title IS NOT NULL GROUP BY product_title, product_variant_title, product_variant_sku, product_vendor, product_type WITH TOTALS, PERCENT_CHANGE {range} COMPARE TO previous_period ORDER BY net_sales DESC LIMIT 200',
        'model-sales-summary' => "FROM sales SHOW net_items_sold, total_sales, gross_sales WHERE product_status = 'Active' AND product_title IS NOT NULL GROUP BY product_id, product_title, product_variant_id, product_variant_title, product_variant_sku WITH TOTALS, PERCENT_CHANGE {range} COMPARE TO previous_period ORDER BY total_sales DESC LIMIT 1000",
        'vendor-sales' => 'FROM sales SHOW net_items_sold, gross_sales, discounts, sales_reversals, net_sales, taxes, total_sales GROUP BY product_vendor WITH TOTALS, PERCENT_CHANGE {range} COMPARE TO previous_period ORDER BY total_sales DESC LIMIT 100',
        'product-type-sales' => 'FROM sales SHOW net_items_sold, gross_sales, discounts, sales_reversals, net_sales, taxes, total_sales GROUP BY product_type WITH TOTALS, PERCENT_CHANGE {range} COMPARE TO previous_period ORDER BY total_sales DESC LIMIT 100',
        'customer-overview' => 'FROM sales SHOW customers WHERE new_or_returning_customer IS NOT NULL GROUP BY new_or_returning_customer WITH TOTALS {range} ORDER BY new_or_returning_customer ASC LIMIT 2',
        'customer-returning-rate' => 'FROM sales SHOW returning_customers, customers, returning_customer_rate TIMESERIES day WITH TOTALS, PERCENT_CHANGE {range} COMPARE TO previous_period ORDER BY day',
        'customer-value' => 'FROM customers SHOW total_number_of_orders, total_amount_spent GROUP BY customer_id, customer_name WITH TOTALS {range} ORDER BY total_amount_spent DESC LIMIT 200',
        'order-status' => 'FROM sales SHOW orders GROUP BY order_payment_status, order_fulfillment_status WITH TOTALS {range} ORDER BY orders DESC LIMIT 100',
        'abc-product-analysis' => 'FROM sales SHOW net_sales, net_items_sold GROUP BY product_variant_abc_grade, product_title, product_variant_title, product_variant_sku WITH TOTALS {range} ORDER BY net_sales DESC LIMIT 200',
        'acquisition-by-source' => "FROM sessions SHOW sessions, online_store_visitors, sessions_that_completed_checkout, conversion_rate WHERE human_or_bot_session IN ('human', 'bot') GROUP BY referrer_source, referrer_name {range} ORDER BY sessions DESC LIMIT 100",
        'acquisition-by-location' => "FROM sessions SHOW sessions, online_store_visitors, sessions_that_completed_checkout, conversion_rate WHERE human_or_bot_session IN ('human', 'bot') GROUP BY session_country, session_region {range} ORDER BY sessions DESC LIMIT 100",
        'behavior-by-device' => "FROM sessions SHOW sessions, pageviews, pageviews_per_session, average_session_duration, bounce_rate, conversion_rate WHERE human_or_bot_session IN ('human', 'bot') GROUP BY session_device_type {range} ORDER BY sessions DESC LIMIT 50",
        'pos-sales-by-location' => "FROM sales SHOW net_sales, total_sales, orders WHERE sales_channel = 'Point of Sale' GROUP BY pos_location_id, pos_location_name {range} ORDER BY total_sales DESC LIMIT 100",
        'pos-sales-by-staff' => "FROM sales SHOW net_sales, total_sales, orders, net_items_sold WHERE sales_channel = 'Point of Sale' GROUP BY staff_id, staff_member_name {range} ORDER BY total_sales DESC LIMIT 100",
        'behavior-by-landing-page' => "FROM sessions SHOW sessions, pageviews, sessions_with_cart_additions, sessions_that_completed_checkout, conversion_rate WHERE human_or_bot_session IN ('human', 'bot') GROUP BY landing_page_type, landing_page_path {range} ORDER BY sessions DESC LIMIT 100",
        'performance-by-page' => 'FROM web_performance SHOW page_loads, lcp_p75_ms, inp_p75_ms, p75_cls, cls_poor_view_count GROUP BY page_type, page_path {range} ORDER BY page_loads DESC LIMIT 100',
        'performance-by-device' => 'FROM web_performance SHOW page_loads, lcp_p75_ms, inp_p75_ms, p75_cls GROUP BY device_type, browser_family {range} ORDER BY page_loads DESC LIMIT 50',
        'campaign-attributed-sales' => 'FROM campaign_sales SHOW campaign_last_click_total_sales, campaign_last_click_order_count, campaign_last_click_total_average_order_value GROUP BY utm_campaign, utm_source {range} ORDER BY campaign_last_click_total_sales DESC LIMIT 100',
        'campaign-attributed-sessions' => 'FROM campaign_sessions SHOW campaign_sessions, campaign_online_store_visitors, campaign_pageviews, campaign_sessions_that_completed_checkout, campaign_conversion_rate GROUP BY utm_campaign, utm_source {range} ORDER BY campaign_sessions DESC LIMIT 100',
        'marketing-engagement-performance' => 'FROM marketing_engagements SHOW engagements_total_sales, engagements_orders, engagements_ad_spend, engagements_clicks, engagements_impressions GROUP BY marketing_platform, marketing_activity_title {range} ORDER BY engagements_total_sales DESC LIMIT 100',
        'marketing-engagement-spend-timeseries' => 'FROM marketing_engagements SHOW engagements_ad_spend TIMESERIES day WITH TOTALS {range} ORDER BY day',
        'sessions-timeseries' => "FROM sessions SHOW sessions, online_store_visitors WHERE human_or_bot_session IN ('human', 'bot') TIMESERIES day WITH TOTALS, PERCENT_CHANGE {range} COMPARE TO previous_period ORDER BY day",
        'conversion-funnel-timeseries' => "FROM sessions SHOW sessions, sessions_with_cart_additions, sessions_that_reached_checkout, sessions_that_completed_checkout, conversion_rate WHERE human_or_bot_session IN ('human', 'bot') TIMESERIES day WITH TOTALS, PERCENT_CHANGE {range} COMPARE TO previous_period ORDER BY day",
        'conversion-funnel-breakdown' => "FROM sessions SHOW sessions, sessions_with_cart_additions, sessions_that_reached_checkout, sessions_that_completed_checkout, conversion_rate WHERE human_or_bot_session IN ('human', 'bot') WITH TOTALS, PERCENT_CHANGE {range} COMPARE TO previous_period",
        'sales-by-channel' => 'FROM sales SHOW orders, gross_sales, discounts, sales_reversals, net_sales, taxes, total_sales GROUP BY sales_channel WITH TOTALS, PERCENT_CHANGE {range} COMPARE TO previous_period ORDER BY total_sales DESC LIMIT 100',
        'orders-fulfilled-timeseries' => "FROM sales SHOW orders WHERE order_fulfillment_status = 'fulfilled' TIMESERIES day WITH TOTALS, PERCENT_CHANGE {range} COMPARE TO previous_period ORDER BY day",
    ];

    private const SHOPIFYQL_QUERY = <<<'GRAPHQL'
        query BusinessAnalyticsReport($query: String!) {
          shopifyqlQuery(query: $query) {
            tableData {
              columns {
                name
                dataType
                displayName
                columnOrigin
                dynamicColumnMetadata {
                  type
                  originalColumnName
                  comparisonReference
                  aggregatedBy
                }
              }
              rows
            }
            parseErrors
          }
        }
        GRAPHQL;

    private const BUSINESS_OVERVIEW_QUERY = <<<'GRAPHQL'
        query BusinessAnalyticsOverview(
          $channels: String!
          $posLocations: String!
          $posStaff: String!
          $traffic: String!
          $searchQueries: String!
          $searchFunnel: String!
        ) {
          channels: shopifyqlQuery(query: $channels) { tableData { columns { name dynamicColumnMetadata { type originalColumnName comparisonReference } } rows } parseErrors }
          posLocations: shopifyqlQuery(query: $posLocations) { tableData { columns { name dynamicColumnMetadata { type originalColumnName comparisonReference } } rows } parseErrors }
          posStaff: shopifyqlQuery(query: $posStaff) { tableData { columns { name dynamicColumnMetadata { type originalColumnName comparisonReference } } rows } parseErrors }
          traffic: shopifyqlQuery(query: $traffic) { tableData { columns { name dynamicColumnMetadata { type originalColumnName comparisonReference } } rows } parseErrors }
          searchQueries: shopifyqlQuery(query: $searchQueries) { tableData { columns { name dynamicColumnMetadata { type originalColumnName comparisonReference } } rows } parseErrors }
          searchFunnel: shopifyqlQuery(query: $searchFunnel) { tableData { columns { name dynamicColumnMetadata { type originalColumnName comparisonReference } } rows } parseErrors }
        }
        GRAPHQL;

    private const ANALYTICS_OVERVIEW_QUERY = <<<'GRAPHQL'
        query AnalyticsOverviewReports(
          $acquisition: String!
          $devices: String!
          $locations: String!
          $posLocations: String!
          $posStaff: String!
          $behavior: String!
        ) {
          acquisition: shopifyqlQuery(query: $acquisition) { tableData { rows } parseErrors }
          devices: shopifyqlQuery(query: $devices) { tableData { rows } parseErrors }
          locations: shopifyqlQuery(query: $locations) { tableData { rows } parseErrors }
          posLocations: shopifyqlQuery(query: $posLocations) { tableData { rows } parseErrors }
          posStaff: shopifyqlQuery(query: $posStaff) { tableData { rows } parseErrors }
          behavior: shopifyqlQuery(query: $behavior) { tableData { columns { name dynamicColumnMetadata { type originalColumnName comparisonReference } } rows } parseErrors }
        }
        GRAPHQL;

    public function __construct(
        private ShopifyGraphQLClient $client,
        private AnalyticsCacheVersionService $analyticsCache,
    ) {}

    /**
     * @param  array{local_start: mixed, local_end: mixed, timezone: string}  $period
     * @return array<string, mixed>
     */
    public function overview(Store $store, array $period, bool $insideRefreshLock = false): array
    {
        $from = $period['local_start']->toDateString();
        $to = $period['local_end']->toDateString();
        $snapshot = $this->businessSnapshot($store, $from, $to);

        if ($snapshot?->expires_at?->isFuture()) {
            return $this->storedOverview($snapshot, false);
        }

        if ($snapshot && ! $insideRefreshLock) {
            $this->queueRefresh($snapshot);

            return $this->storedOverview($snapshot, true);
        }

        $connection = $store->shopifyConnection;
        $scopes = $connection?->scopes ?? [];

        if (! $connection || ! in_array($connection->status, ['connected', 'warning'], true)) {
            return $snapshot
                ? $this->storedOverview($snapshot, true)
                : $this->unavailable(false, 'Shopify 连接不可用。');
        }

        if (! in_array('read_reports', $scopes, true)) {
            return $snapshot
                ? $this->storedOverview($snapshot, true)
                : $this->unavailable(false, '缺少 read_reports。');
        }

        if (! $snapshot && ! $insideRefreshLock) {
            $snapshot = $this->pendingSnapshot(
                $store,
                self::BUSINESS_OVERVIEW_REPORT,
                $from,
                $to,
                self::BUSINESS_OVERVIEW_SCHEMA_VERSION,
                $this->unavailable(true, 'Shopify 报表正在刷新，请稍后重试。'),
                (string) $period['timezone'],
            );
            $this->queueRefresh($snapshot);
            $snapshot = $snapshot->fresh() ?? $snapshot;

            return $this->storedOverview($snapshot, ! $snapshot->expires_at->isFuture());
        }

        if (! $insideRefreshLock) {
            return $this->withRefreshLock(
                $store,
                self::BUSINESS_OVERVIEW_REPORT,
                $from,
                $to,
                self::BUSINESS_OVERVIEW_SCHEMA_VERSION,
                fn (): array => $this->overview($store, $period, true),
                fn (): array => $this->unavailable(true, 'Shopify 报表正在刷新，请稍后重试。'),
            );
        }

        $range = "SINCE {$from} UNTIL {$to}";
        try {
            $payload = $this->client->query($connection, self::BUSINESS_OVERVIEW_QUERY, [
                'channels' => "FROM sales SHOW net_sales, total_sales, orders GROUP BY sales_channel {$range} COMPARE TO previous_period ORDER BY total_sales DESC LIMIT 100",
                'posLocations' => "FROM sales SHOW net_sales, total_sales, orders WHERE sales_channel = 'Point of Sale' GROUP BY pos_location_id, pos_location_name {$range} ORDER BY total_sales DESC LIMIT 100",
                'posStaff' => "FROM sales SHOW net_sales, total_sales, orders, net_items_sold WHERE sales_channel = 'Point of Sale' GROUP BY staff_id, staff_member_name {$range} ORDER BY total_sales DESC LIMIT 100",
                'traffic' => "FROM sessions SHOW sessions, pageviews, sessions_with_cart_additions, sessions_that_reached_checkout, sessions_that_completed_checkout, conversion_rate WHERE human_or_bot_session IN ('human', 'bot') WITH TOTALS, PERCENT_CHANGE {$range} COMPARE TO previous_period",
                'searchQueries' => "FROM searches SHOW searches GROUP BY search_query WITH TOTALS {$range} COMPARE TO previous_period ORDER BY searches DESC LIMIT 50",
                'searchFunnel' => "FROM search_conversions SHOW sessions_with_searches, search_sessions_with_clicks, search_sessions_with_cart_additions, search_sessions_that_completed_checkout, search_conversion_rate {$range} COMPARE TO previous_period",
            ], 30);
        } catch (ShopifyApiException $exception) {
            return $snapshot
                ? $this->storedOverview($snapshot, true)
                : $this->unavailable(true, $exception->getMessage());
        } catch (Throwable) {
            return $snapshot
                ? $this->storedOverview($snapshot, true)
                : $this->unavailable(true, 'Shopify 报表暂时不可用。');
        }

        $reports = [
            'channels' => $this->tableReport($payload, 'channels'),
            'pos_locations' => $this->tableReport($payload, 'posLocations'),
            'pos_staff' => $this->tableReport($payload, 'posStaff'),
            'traffic' => $this->tableReport($payload, 'traffic'),
            'search_queries' => $this->tableReport($payload, 'searchQueries'),
            'search_funnel' => $this->tableReport($payload, 'searchFunnel'),
        ];
        $errors = collect($reports)
            ->filter(fn (array $report): bool => ! $report['available'])
            ->mapWithKeys(fn (array $report, string $key): array => [$key => $report['error']])
            ->all();

        $result = [
            'scope_granted' => true,
            'available' => count($errors) < count($reports),
            'complete' => $errors === [],
            'source' => 'shopifyql',
            'channels' => $reports['channels']['available'] ? $this->channels($reports['channels']['rows']) : null,
            'pos_locations' => $reports['pos_locations']['available'] ? $this->posLocations($reports['pos_locations']['rows']) : null,
            'pos_staff' => $reports['pos_staff']['available'] ? $this->posStaff($reports['pos_staff']['rows']) : null,
            'traffic' => $reports['traffic']['available'] ? $this->traffic($reports['traffic']['rows']) : null,
            'search' => $reports['search_queries']['available'] && $reports['search_funnel']['available']
                ? $this->search($reports['search_queries']['rows'], $reports['search_funnel']['rows'])
                : null,
            'funnel' => $reports['traffic']['available'] ? $this->funnel($reports['traffic']['rows']) : null,
            'errors' => $errors,
        ];

        if (! $result['available']) {
            return $snapshot ? $this->storedOverview($snapshot, true) : $result;
        }

        return $this->storedOverview(
            $this->persistBusinessSnapshot($store, $from, $to, (string) $period['timezone'], $result),
            false,
        );
    }

    /**
     * Run one bounded, catalog-backed ShopifyQL report. The report key is
     * whitelisted above; callers cannot supply arbitrary ShopifyQL.
     *
     * @return array{scope_granted: bool, available: bool, source: string, rows: list<array<string, mixed>>, error: string|null}
     */
    public function report(
        Store $store,
        string $report,
        string $from,
        string $to,
        bool $insideRefreshLock = false,
    ): array {
        $query = self::REPORT_QUERIES[$report] ?? null;
        abort_unless($query, 404);

        $snapshotKey = "catalog:{$report}";
        $schemaVersion = $this->catalogSchemaVersion($report);
        $snapshot = $this->snapshot($store, $snapshotKey, $from, $to, $schemaVersion);

        if ($snapshot?->expires_at?->isFuture()) {
            return $this->storedReport($snapshot, false);
        }

        if ($snapshot && ! $insideRefreshLock) {
            $this->queueRefresh($snapshot);

            return $this->storedReport($snapshot, true);
        }

        $connection = $store->shopifyConnection;
        $scopes = $connection?->scopes ?? [];

        if (! $connection || ! in_array($connection->status, ['connected', 'warning'], true)) {
            return $snapshot
                ? $this->storedReport($snapshot, true)
                : $this->unavailableReport(false, 'Shopify 连接不可用。');
        }

        if (! in_array('read_reports', $scopes, true)) {
            return $snapshot
                ? $this->storedReport($snapshot, true)
                : $this->unavailableReport(false, '缺少 read_reports，请重新授权店铺。');
        }

        if (! $snapshot && ! $insideRefreshLock) {
            $snapshot = $this->pendingSnapshot(
                $store,
                $snapshotKey,
                $from,
                $to,
                $schemaVersion,
                $this->unavailableReport(true, 'Shopify 报表正在刷新，请稍后重试。'),
            );
            $this->queueRefresh($snapshot);
            $snapshot = $snapshot->fresh() ?? $snapshot;

            return $this->storedReport($snapshot, ! $snapshot->expires_at->isFuture());
        }

        $range = "SINCE {$from} UNTIL {$to}";
        if (! $insideRefreshLock) {
            return $this->withRefreshLock(
                $store,
                $snapshotKey,
                $from,
                $to,
                $schemaVersion,
                fn (): array => $this->report($store, $report, $from, $to, true),
                fn (): array => $this->unavailableReport(true, 'Shopify 报表正在刷新，请稍后重试。'),
            );
        }

        $result = $this->run($connection, str_replace('{range}', $range, $query));
        $rows = $this->normalizeDynamicColumns($result['rows'], $result['columns']);
        $payload = [
            'scope_granted' => true,
            'available' => $result['ok'],
            'source' => 'shopifyql',
            'columns' => $result['columns'],
            'rows' => $this->normalizeReportRows($report, $rows),
            'error' => $result['error'],
        ];

        if (! $payload['available']) {
            return $snapshot ? $this->storedReport($snapshot, true) : $payload;
        }

        return $this->storedReport(
            $this->persistSnapshot(
                $store,
                $snapshotKey,
                $from,
                $to,
                $schemaVersion,
                $payload,
            ),
            false,
        );
    }

    /** @return array<string, array{scope_granted: bool, available: bool, source: string, rows: list<array<string, mixed>>, error: string|null}> */
    public function analyticsOverview(
        Store $store,
        string $from,
        string $to,
        bool $insideRefreshLock = false,
    ): array {
        $snapshot = $this->snapshot(
            $store,
            self::ANALYTICS_OVERVIEW_REPORT,
            $from,
            $to,
            self::ANALYTICS_OVERVIEW_SCHEMA_VERSION,
        );

        if ($snapshot?->expires_at?->isFuture()) {
            return $this->storedAnalyticsOverview($snapshot, false);
        }

        if ($snapshot && ! $insideRefreshLock) {
            $this->queueRefresh($snapshot);

            return $this->storedAnalyticsOverview($snapshot, true);
        }

        $connection = $store->shopifyConnection;
        $scopes = $connection?->scopes ?? [];
        $keys = ['acquisition', 'devices', 'locations', 'pos_locations', 'pos_staff', 'behavior'];

        if (! $connection || ! in_array($connection->status, ['connected', 'warning'], true)) {
            return $this->failedAnalyticsOverview(
                $store,
                $snapshot,
                $from,
                $to,
                $this->unavailableReports($keys, false, 'Shopify 连接不可用。'),
            );
        }

        if (! in_array('read_reports', $scopes, true)) {
            return $this->failedAnalyticsOverview(
                $store,
                $snapshot,
                $from,
                $to,
                $this->unavailableReports($keys, false, '缺少 read_reports，请重新授权店铺。'),
            );
        }

        if (! $snapshot && ! $insideRefreshLock) {
            $snapshot = $this->pendingSnapshot(
                $store,
                self::ANALYTICS_OVERVIEW_REPORT,
                $from,
                $to,
                self::ANALYTICS_OVERVIEW_SCHEMA_VERSION,
                $this->unavailableReports($keys, true, 'Shopify 报表正在刷新，请稍后重试。'),
            );
            $this->queueRefresh($snapshot);
            $snapshot = $snapshot->fresh() ?? $snapshot;

            return $this->storedAnalyticsOverview($snapshot, ! $snapshot->expires_at->isFuture());
        }

        if (! $insideRefreshLock) {
            return $this->withRefreshLock(
                $store,
                self::ANALYTICS_OVERVIEW_REPORT,
                $from,
                $to,
                self::ANALYTICS_OVERVIEW_SCHEMA_VERSION,
                fn (): array => $this->analyticsOverview($store, $from, $to, true),
                fn (): array => $this->unavailableReports($keys, true, 'Shopify 报表正在刷新，请稍后重试。'),
            );
        }

        $range = "SINCE {$from} UNTIL {$to}";
        $variables = [
            'acquisition' => str_replace('{range}', $range, self::REPORT_QUERIES['acquisition-by-source']),
            'devices' => str_replace('{range}', $range, self::REPORT_QUERIES['behavior-by-device']),
            'locations' => str_replace('{range}', $range, self::REPORT_QUERIES['acquisition-by-location']),
            'posLocations' => str_replace('{range}', $range, self::REPORT_QUERIES['pos-sales-by-location']),
            'posStaff' => str_replace('{range}', $range, self::REPORT_QUERIES['pos-sales-by-staff']),
            'behavior' => str_replace('{range}', $range, self::REPORT_QUERIES['conversion-funnel-timeseries']),
        ];

        try {
            $payload = $this->client->query($connection, self::ANALYTICS_OVERVIEW_QUERY, $variables, 30);

            $reports = [
                'acquisition' => $this->tableReport($payload, 'acquisition'),
                'devices' => $this->tableReport($payload, 'devices'),
                'locations' => $this->tableReport($payload, 'locations'),
                'pos_locations' => $this->tableReport($payload, 'posLocations'),
                'pos_staff' => $this->tableReport($payload, 'posStaff'),
                'behavior' => $this->tableReport($payload, 'behavior'),
            ];
        } catch (ShopifyApiException $exception) {
            return $this->failedAnalyticsOverview(
                $store,
                $snapshot,
                $from,
                $to,
                $this->unavailableReports($keys, true, $exception->getMessage()),
            );
        } catch (Throwable) {
            return $this->failedAnalyticsOverview(
                $store,
                $snapshot,
                $from,
                $to,
                $this->unavailableReports($keys, true, 'Shopify 报表暂时不可用。'),
            );
        }

        if (! $this->reportsAvailable($reports, $keys)) {
            return $this->failedAnalyticsOverview($store, $snapshot, $from, $to, $reports);
        }

        return $this->storedAnalyticsOverview(
            $this->persistSnapshot(
                $store,
                self::ANALYTICS_OVERVIEW_REPORT,
                $from,
                $to,
                self::ANALYTICS_OVERVIEW_SCHEMA_VERSION,
                $reports,
            ),
            false,
        );
    }

    public function refreshSnapshot(int $snapshotId): void
    {
        $snapshot = AnalyticsSnapshot::query()->with('store.shopifyConnection')->find($snapshotId);
        if (! $snapshot || $snapshot->expires_at?->isFuture()) {
            return;
        }

        $store = $snapshot->store;
        if (! $store || (int) $store->organization_id !== (int) $snapshot->organization_id) {
            return;
        }

        $from = $snapshot->period_from->toDateString();
        $to = $snapshot->period_to->toDateString();

        $this->withRefreshLock(
            $store,
            $snapshot->report_key,
            $from,
            $to,
            $snapshot->schema_version,
            function () use ($snapshot, $store, $from, $to): void {
                $current = $snapshot->fresh();
                if (! $current || $current->expires_at?->isFuture()) {
                    return;
                }

                if ($snapshot->report_key === self::BUSINESS_OVERVIEW_REPORT) {
                    $timezone = $snapshot->timezone ?: $store->timezone ?: 'UTC';
                    $this->overview($store, [
                        'local_start' => CarbonImmutable::parse($from, $timezone)->startOfDay(),
                        'local_end' => CarbonImmutable::parse($to, $timezone)->endOfDay(),
                        'timezone' => $timezone,
                    ], true);

                    return;
                }

                if ($snapshot->report_key === self::ANALYTICS_OVERVIEW_REPORT) {
                    $this->analyticsOverview($store, $from, $to, true);

                    return;
                }

                if (str_starts_with($snapshot->report_key, 'catalog:')) {
                    $report = substr($snapshot->report_key, strlen('catalog:'));
                    if (isset(self::REPORT_QUERIES[$report])) {
                        $this->report($store, $report, $from, $to, true);
                    }
                }
            },
            static fn (): null => null,
        );

        // A dashboard request may have cached its local fallback while this
        // background refresh was pending. The completed snapshot must become
        // visible on the next request instead of waiting for that cache TTL.
        if (in_array($snapshot->report_key, self::ANALYTICS_CACHE_REPORTS, true)) {
            $this->analyticsCache->bump((int) $store->getKey());
        }
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
        $columns = data_get($result, 'tableData.columns', []);
        if (is_array($rows) && is_array($columns)) {
            $rows = $this->normalizeDynamicColumns(
                array_values(array_filter($rows, 'is_array')),
                array_values(array_filter($columns, 'is_array')),
            );
        }

        return [
            'scope_granted' => true,
            'available' => is_array($rows),
            'source' => 'shopifyql',
            'rows' => is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [],
            'error' => is_array($rows) ? null : 'ShopifyQL 未返回表格数据。',
        ];
    }

    private function businessSnapshot(Store $store, string $from, string $to): ?AnalyticsSnapshot
    {
        return $this->snapshot(
            $store,
            self::BUSINESS_OVERVIEW_REPORT,
            $from,
            $to,
            self::BUSINESS_OVERVIEW_SCHEMA_VERSION,
        );
    }

    private function snapshot(
        Store $store,
        string $reportKey,
        string $from,
        string $to,
        int $schemaVersion,
    ): ?AnalyticsSnapshot {
        return AnalyticsSnapshot::query()
            ->forOrganization($store->organization_id)
            ->forStore($store)
            ->where('report_key', $reportKey)
            ->whereDate('period_from', $from)
            ->whereDate('period_to', $to)
            ->where('schema_version', $schemaVersion)
            ->first();
    }

    /** @param array<string, mixed> $payload */
    private function persistBusinessSnapshot(
        Store $store,
        string $from,
        string $to,
        string $timezone,
        array $payload,
    ): AnalyticsSnapshot {
        return $this->persistSnapshot(
            $store,
            self::BUSINESS_OVERVIEW_REPORT,
            $from,
            $to,
            self::BUSINESS_OVERVIEW_SCHEMA_VERSION,
            $payload,
            $timezone,
        );
    }

    /** @param array<string, mixed> $payload */
    private function persistSnapshot(
        Store $store,
        string $reportKey,
        string $from,
        string $to,
        int $schemaVersion,
        array $payload,
        ?string $timezone = null,
    ): AnalyticsSnapshot {
        $fetchedAt = now();
        $ttl = max(1, (int) config('shopify.analytics_snapshot_ttl_minutes', 15));

        $snapshot = $this->snapshot($store, $reportKey, $from, $to, $schemaVersion)
            ?? new AnalyticsSnapshot([
                'organization_id' => $store->organization_id,
                'store_id' => $store->getKey(),
                'report_key' => $reportKey,
                'period_from' => $from,
                'period_to' => $to,
                'schema_version' => $schemaVersion,
            ]);

        $snapshot->fill([
            'timezone' => $timezone ?: $store->timezone ?: 'UTC',
            'source' => (string) ($payload['source'] ?? 'shopifyql'),
            'payload' => $payload,
            'fetched_at' => $fetchedAt,
            'expires_at' => $fetchedAt->copy()->addMinutes($ttl),
        ])->save();

        return $snapshot;
    }

    /** @param array<string, mixed> $payload */
    private function pendingSnapshot(
        Store $store,
        string $reportKey,
        string $from,
        string $to,
        int $schemaVersion,
        array $payload,
        ?string $timezone = null,
    ): AnalyticsSnapshot {
        $now = now();

        return AnalyticsSnapshot::query()->firstOrCreate([
            'organization_id' => $store->organization_id,
            'store_id' => $store->getKey(),
            'report_key' => $reportKey,
            'period_from' => $from,
            'period_to' => $to,
            'schema_version' => $schemaVersion,
        ], [
            'timezone' => $timezone ?: $store->timezone ?: 'UTC',
            'source' => 'pending',
            'payload' => $payload,
            'fetched_at' => $now,
            'expires_at' => $now->copy()->subSecond(),
        ]);
    }

    /** @return array<string, mixed> */
    private function storedOverview(AnalyticsSnapshot $snapshot, bool $stale): array
    {
        $payload = $snapshot->payload;
        if (! is_array($payload)) {
            return $this->unavailable(false, '本地分析快照不可用。');
        }

        $payload['storage'] = $this->storageMetadata($snapshot, $stale);

        return $payload;
    }

    /** @return array<string, mixed> */
    private function storedReport(AnalyticsSnapshot $snapshot, bool $stale): array
    {
        $payload = $snapshot->payload;
        if (! is_array($payload)) {
            return $this->unavailableReport(false, '本地分析快照不可用。');
        }

        $payload['storage'] = $this->storageMetadata($snapshot, $stale);

        return $payload;
    }

    /** @return array<string, mixed> */
    private function storedAnalyticsOverview(AnalyticsSnapshot $snapshot, bool $stale): array
    {
        $payload = $snapshot->payload;
        if (! is_array($payload)) {
            return $this->unavailableReports(
                ['acquisition', 'devices', 'locations', 'pos_locations', 'pos_staff', 'behavior'],
                false,
                '本地分析快照不可用。',
            );
        }

        $payload['storage'] = $this->storageMetadata($snapshot, $stale);

        return $payload;
    }

    /** @param array<string, mixed> $reports */
    private function failedAnalyticsOverview(
        Store $store,
        ?AnalyticsSnapshot $snapshot,
        string $from,
        string $to,
        array $reports,
    ): array {
        if (! $snapshot) {
            return $reports;
        }

        if ($snapshot->source !== 'pending' && (bool) data_get($snapshot->payload, 'behavior.available', false)) {
            return $this->storedAnalyticsOverview($snapshot, true);
        }

        return $this->storedAnalyticsOverview(
            $this->persistSnapshot(
                $store,
                self::ANALYTICS_OVERVIEW_REPORT,
                $from,
                $to,
                self::ANALYTICS_OVERVIEW_SCHEMA_VERSION,
                $reports,
            ),
            false,
        );
    }

    /** @param array<string, mixed> $reports @param list<string> $keys */
    private function reportsAvailable(array $reports, array $keys): bool
    {
        return collect($keys)->contains(
            fn (string $key): bool => (bool) data_get($reports, "{$key}.available", false),
        );
    }

    /** @return array{persisted: true, source: string, stale: bool, pending: bool, refreshing: bool, fetched_at: string|null, expires_at: string|null} */
    private function storageMetadata(AnalyticsSnapshot $snapshot, bool $stale): array
    {
        return [
            'persisted' => true,
            'source' => 'database',
            'stale' => $stale,
            'pending' => $snapshot->source === 'pending',
            'refreshing' => $stale,
            'fetched_at' => $snapshot->fetched_at?->toIso8601String(),
            'expires_at' => $snapshot->expires_at?->toIso8601String(),
        ];
    }

    private function queueRefresh(AnalyticsSnapshot $snapshot): void
    {
        RefreshShopifyAnalyticsSnapshot::dispatch($snapshot->getKey());
    }

    private function refreshLockKey(
        Store $store,
        string $reportKey,
        string $from,
        string $to,
        int $schemaVersion,
    ): string {
        return implode(':', [
            'shopify-analytics-refresh',
            $store->organization_id,
            $store->getKey(),
            sha1($reportKey),
            $from,
            $to,
            "v{$schemaVersion}",
        ]);
    }

    private function withRefreshLock(
        Store $store,
        string $reportKey,
        string $from,
        string $to,
        int $schemaVersion,
        callable $refresh,
        callable $timeoutFallback,
    ): mixed {
        $lock = Cache::lock(
            $this->refreshLockKey($store, $reportKey, $from, $to, $schemaVersion),
            max(30, (int) config('shopify.analytics_snapshot_refresh_lock_seconds', 90)),
        );

        try {
            return $lock->block(
                max(1, (int) config('shopify.analytics_snapshot_refresh_wait_seconds', 35)),
                $refresh,
            );
        } catch (LockTimeoutException) {
            return $timeoutFallback();
        }
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
            'storage' => [
                'persisted' => false,
                'source' => null,
                'stale' => false,
                'fetched_at' => null,
                'expires_at' => null,
            ],
        ];
    }

    /** @return array{ok: bool, columns: list<array<string, mixed>>, rows: list<array<string, mixed>>, error: string|null} */
    private function run(ShopifyConnection $connection, string $shopifyql): array
    {
        try {
            $payload = $this->client->query($connection, self::SHOPIFYQL_QUERY, ['query' => $shopifyql], 30);
            $result = data_get($payload, 'data.shopifyqlQuery');
            $parseErrors = is_array($result['parseErrors'] ?? null) ? $result['parseErrors'] : [];

            if ($parseErrors !== []) {
                return ['ok' => false, 'columns' => [], 'rows' => [], 'error' => implode('; ', array_map('strval', $parseErrors))];
            }

            $columns = data_get($result, 'tableData.columns', []);
            $rows = data_get($result, 'tableData.rows', []);

            return [
                'ok' => is_array($rows),
                'columns' => is_array($columns) ? array_values(array_filter($columns, 'is_array')) : [],
                'rows' => is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [],
                'error' => is_array($rows) ? null : 'ShopifyQL 未返回表格数据。',
            ];
        } catch (ShopifyApiException $exception) {
            return ['ok' => false, 'columns' => [], 'rows' => [], 'error' => $exception->getMessage()];
        } catch (Throwable) {
            return ['ok' => false, 'columns' => [], 'rows' => [], 'error' => 'Shopify 报表暂时不可用。'];
        }
    }

    /**
     * Normalize Shopify-generated comparison columns from their metadata. The
     * aliases keep snapshots stable if Shopify changes the opaque generated
     * column name while retaining the same original metric and comparison.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $columns
     * @return list<array<string, mixed>>
     */
    private function normalizeDynamicColumns(array $rows, array $columns): array
    {
        $aliases = collect($columns)->mapWithKeys(function (array $column): array {
            $metadata = $column['dynamicColumnMetadata'] ?? null;
            if (! is_array($metadata)) {
                return [];
            }

            $name = (string) ($column['name'] ?? '');
            $metric = (string) ($metadata['originalColumnName'] ?? '');
            $reference = (string) ($metadata['comparisonReference'] ?? '');
            $type = (string) ($metadata['type'] ?? '');
            if ($name === '' || $metric === '' || $reference === '') {
                return [];
            }

            $prefix = str_contains($type, 'PERCENT_CHANGE') ? 'percent_change' :
                (str_contains($type, 'COMPARISON') ? 'comparison' : null);
            if ($prefix === null) {
                return [];
            }

            $totals = str_ends_with($type, '_TOTALS') || str_ends_with($name, '__totals');
            $alias = "{$prefix}_{$metric}__{$reference}".($totals ? '__totals' : '');

            return [$name => $alias];
        });

        if ($aliases->isEmpty()) {
            return $rows;
        }

        return collect($rows)->map(function (array $row) use ($aliases): array {
            foreach ($aliases as $source => $alias) {
                if (array_key_exists($source, $row) && ! array_key_exists($alias, $row)) {
                    $row[$alias] = $row[$source];
                }
            }

            return $row;
        })->all();
    }

    /**
     * Keep the public report schema stable while using Shopify's native field
     * names and accounting semantics underneath.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function normalizeReportRows(string $report, array $rows): array
    {
        return collect($rows)
            ->map(function (array $row) use ($report): array {
                return match ($report) {
                    'product-sales' => [...$row,
                        // Shopify's native product report groups by title,
                        // vendor and type. Do not attach a local product id:
                        // doing so can merge renamed products or variants.
                        'product_id' => null,
                        'name' => (string) ($row['product_title'] ?? '未命名商品'),
                        'vendor' => (string) ($row['product_vendor'] ?? '未分类供应商'),
                        'product_type' => (string) ($row['product_type'] ?? '未分类'),
                        'units' => $this->integer($row['net_items_sold'] ?? 0),
                        'gross_sales' => $this->decimal($row['gross_sales'] ?? 0),
                        'net_sales' => $this->decimal($row['net_sales'] ?? 0),
                        'total_sales' => $this->decimal($row['total_sales'] ?? 0),
                        'ordered_quantity' => null,
                        'quantity_semantics' => 'net_items_sold',
                        'sales_semantics' => 'net_sales',
                    ],
                    'product-variant-sales' => [...$row,
                        'product_id' => null,
                        'name' => trim(implode(' · ', array_filter([
                            $row['product_title'] ?? null,
                            $row['product_variant_title'] ?? null,
                        ]))) ?: '未命名商品多属性',
                        'vendor' => (string) ($row['product_vendor'] ?? '未分类供应商'),
                        'product_type' => (string) ($row['product_type'] ?? '未分类'),
                        'sku' => (string) ($row['product_variant_sku'] ?? ''),
                        'units' => $this->integer($row['net_items_sold'] ?? 0),
                        'gross_sales' => $this->decimal($row['gross_sales'] ?? 0),
                        'net_sales' => $this->decimal($row['net_sales'] ?? 0),
                        'total_sales' => $this->decimal($row['total_sales'] ?? 0),
                        'quantity_semantics' => 'net_items_sold',
                        'sales_semantics' => 'net_sales',
                    ],
                    'vendor-sales' => [...$row,
                        'vendor' => (string) ($row['product_vendor'] ?? '未分类供应商'),
                        'units' => $this->integer($row['net_items_sold'] ?? 0),
                        'gross_sales' => $this->decimal($row['gross_sales'] ?? 0),
                        'net_sales' => $this->decimal($row['net_sales'] ?? 0),
                        'total_sales' => $this->decimal($row['total_sales'] ?? 0),
                        'quantity_semantics' => 'net_items_sold',
                    ],
                    'product-type-sales' => [...$row,
                        'product_type' => (string) ($row['product_type'] ?? '未分类'),
                        'units' => $this->integer($row['net_items_sold'] ?? 0),
                        'gross_sales' => $this->decimal($row['gross_sales'] ?? 0),
                        'net_sales' => $this->decimal($row['net_sales'] ?? 0),
                        'total_sales' => $this->decimal($row['total_sales'] ?? 0),
                        'quantity_semantics' => 'net_items_sold',
                    ],
                    'customer-value' => [...$row,
                        'name' => (string) ($row['customer_name'] ?? '未命名客户'),
                        'orders' => $this->integer($row['total_number_of_orders'] ?? 0),
                        'lifetime_value' => $this->decimal($row['total_amount_spent'] ?? 0),
                    ],
                    'order-status' => [...$row,
                        'status' => trim(implode(' / ', array_filter([
                            $row['order_payment_status'] ?? null,
                            $row['order_fulfillment_status'] ?? null,
                        ]))) ?: '未知',
                        'type' => 'Shopify 订单状态',
                    ],
                    'abc-product-analysis' => [...$row,
                        'name' => trim(implode(' · ', array_filter([
                            $row['product_title'] ?? null,
                            $row['product_variant_title'] ?? null,
                        ]))) ?: '未命名商品',
                        'grade' => (string) ($row['product_variant_abc_grade'] ?? '未评级'),
                        'units' => $this->integer($row['net_items_sold'] ?? 0),
                    ],
                    default => $row,
                };
            })->values()->all();
    }

    private function catalogSchemaVersion(string $report): int
    {
        return in_array($report, [
            'core-sales-timeseries', 'core-sales-year-comparison', 'product-sales', 'product-variant-sales',
            'vendor-sales', 'product-type-sales', 'customer-overview', 'customer-returning-rate',
            'customer-value', 'order-status',
            'abc-product-analysis', 'model-sales-summary',
        ], true) ? 3 : self::CATALOG_REPORT_SCHEMA_VERSION;
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
            'native_comparison' => array_filter([
                'sessions' => $this->nativeComparison($row, 'sessions'),
                'page_views' => $this->nativeComparison($row, 'pageviews'),
                'converted_sessions' => $this->nativeComparison($row, 'sessions_that_completed_checkout'),
                'conversion_rate' => $this->nativeComparison($row, 'conversion_rate', true),
            ]),
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

        $nativeMetrics = [
            'sessions' => 'sessions',
            'product_added_to_cart' => 'sessions_with_cart_additions',
            'checkout_started' => 'sessions_that_reached_checkout',
            'checkout_completed' => 'sessions_that_completed_checkout',
        ];

        return collect($stages)->map(fn (array $stage): array => [
            'key' => $stage['key'],
            'label' => $stage['label'],
            'sessions' => $stage['value'],
            'rate' => $total > 0 ? round($stage['value'] / $total * 100, 2) : 0.0,
            'native_comparison' => $this->nativeComparison($row, $nativeMetrics[$stage['key']]),
        ])->all();
    }

    /** @return array{current: float, baseline: float, change: float, change_percent: float}|null */
    private function nativeComparison(array $row, string $metric, bool $isRate = false): ?array
    {
        $baseline = $this->dynamicComparisonValue($row, 'comparison', $metric);
        $changePercent = $this->dynamicComparisonValue($row, 'percent_change', $metric);
        if (! is_numeric($baseline) || ! is_numeric($changePercent)) {
            return null;
        }

        $current = $row[$metric] ?? null;
        if (! is_numeric($current)) {
            return null;
        }

        $currentValue = $isRate ? $this->percent($current) : (float) $current;
        $baselineValue = $isRate ? $this->percent($baseline) : (float) $baseline;

        return [
            'current' => $currentValue,
            'baseline' => $baselineValue,
            'change' => round($currentValue - $baselineValue, 2),
            'change_percent' => round((float) $changePercent, 2),
        ];
    }

    private function dynamicComparisonValue(array $row, string $prefix, string $metric): mixed
    {
        foreach ([
            "{$prefix}_{$metric}__previous_period",
            "{$prefix}_{$metric}__previous_period__totals",
        ] as $key) {
            if (array_key_exists($key, $row)) {
                return $row[$key];
            }
        }

        return null;
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
