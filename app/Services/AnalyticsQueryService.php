<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use App\Services\Shopify\Analytics\ShopifyAnalyticsReportService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class AnalyticsQueryService
{
    public function __construct(
        private AnalyticsCacheVersionService $cacheVersion,
        private ShopifyAnalyticsReportService $shopifyReports,
    ) {}

    /** @param int|array<string, mixed> $filters */
    public function sales(Store $store, int|array $filters = 30): array
    {
        $values = is_int($filters) ? ['days' => $filters] : $filters;
        $period = $this->period($store, $values);
        $comparisonPeriod = $this->selectedComparisonPeriod($store, $period, $values);
        $comparisonSignature = [
            'mode' => (string) ($values['comparison'] ?? 'previous'),
            'from' => (string) ($values['comparison_date_from'] ?? ''),
            'to' => (string) ($values['comparison_date_to'] ?? ''),
        ];
        $version = $this->cacheVersion->current((int) $store->getKey());
        // Keep the response schema version in the cache key so older dashboard
        // payloads cannot be reused after new metrics are introduced.
        $key = implode(':', [
            'analytics',
            'sales',
            'schema-v4',
            'organization',
            $store->organization_id,
            'store',
            $store->getKey(),
            "v{$version}",
            sha1(json_encode([$period, $comparisonSignature])),
        ]);

        return Cache::remember(
            $key,
            now()->addMinutes(5),
            fn (): array => $this->buildSales($store, $period, $values, $comparisonPeriod),
        );
    }

    /**
     * Shopify-style operating overview backed only by data that DecoAdmin has
     * actually synchronized. Traffic metrics stay explicitly unavailable until
     * Web Pixel/customer-event collection is introduced.
     *
     * @param  int|array<string, mixed>  $filters
     */
    public function operationsOverview(Store $store, int|array $filters = 30): array
    {
        $sales = $this->sales($store, $filters);
        $period = $this->period($store, $filters);
        $orders = $this->orders($store, $period);

        $statusGroups = function (string $column) use ($orders): array {
            return (clone $orders)
                ->selectRaw("COALESCE(NULLIF({$column}, ''), 'unknown') as status, COUNT(*) as total")
                ->groupBy($column)
                ->orderByDesc('total')
                ->get()
                ->map(fn (object $row): array => [
                    'status' => (string) $row->status,
                    'total' => (int) $row->total,
                ])->all();
        };

        return [
            'schema' => 'operations-overview-v1',
            'period' => $sales['period'],
            'comparison' => $sales['comparison'],
            'summary' => $sales['summary'],
            'comparisons' => $sales['comparisons'],
            'trend' => $sales['trend'],
            'comparison_trend' => $sales['comparison_trend'],
            'sales_breakdown' => [
                ['key' => 'gross_sales', 'label' => '毛销售额', 'value' => $sales['summary']['gross_sales']],
                ['key' => 'discounts', 'label' => '折扣', 'value' => -abs($sales['summary']['discounts'])],
                ['key' => 'refunds', 'label' => '退款', 'value' => -abs($sales['summary']['refunds'])],
                ['key' => 'net_sales', 'label' => '净销售额', 'value' => $sales['summary']['net_sales']],
                ['key' => 'shipping', 'label' => '运费', 'value' => $sales['summary']['shipping']],
                ['key' => 'taxes', 'label' => '税费', 'value' => $sales['summary']['taxes']],
                ['key' => 'total_sales', 'label' => '总销售额', 'value' => $sales['summary']['total_sales']],
            ],
            'customers' => $sales['customers'],
            'rankings' => $sales['rankings'],
            'inventory' => $sales['inventory'],
            'order_statuses' => [
                'financial' => $statusGroups('financial_status'),
                'fulfillment' => $statusGroups('fulfillment_status'),
            ],
            'traffic' => [
                'available' => false,
                'source' => null,
                'reason_code' => 'web_pixel_not_connected',
                'message' => '接入 Shopify Web Pixel 后可展示访问、设备、地点、推荐来源和转化率。',
            ],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /** @param int|array<string, mixed> $filters */
    public function storeComparison(Organization $organization, User $user, int|array $filters = 30): array
    {
        $stores = $this->authorizedStores($organization, $user);

        return [
            'period' => $stores->isEmpty() ? [] : $this->sales($stores->first(), $filters)['period'],
            'stores' => $stores->map(function (Store $store) use ($filters): array {
                $analytics = $this->sales($store, $filters);

                return [
                    'id' => $store->id,
                    'name' => $store->name,
                    'currency' => $store->currency ?: 'USD',
                    'orders' => $analytics['summary']['orders'],
                    'sales' => $analytics['summary']['net_sales'],
                    'net_sales' => $analytics['summary']['net_sales'],
                    'refunds' => $analytics['summary']['refunds'],
                    'discounts' => $analytics['summary']['discounts'],
                    'average_order_value' => $analytics['summary']['average_order_value'],
                ];
            })->sort(function (array $left, array $right): int {
                return [$left['currency'], -$left['net_sales']] <=> [$right['currency'], -$right['net_sales']];
            })->values()->all(),
        ];
    }

    /** @return Collection<int, Store> */
    public function authorizedStores(Organization $organization, User $user): Collection
    {
        return $user->isSuperAdmin()
            ? $organization->stores()->where('status', 'active')->orderBy('name')->get()
            : $user->stores()->where('stores.organization_id', $organization->id)->where('stores.status', 'active')->orderBy('stores.name')->get();
    }

    /**
     * @param  array<string, mixed>  $period
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>|null  $comparisonPeriod
     */
    private function buildSales(Store $store, array $period, array $filters, ?array $comparisonPeriod): array
    {
        $orders = $this->orders($store, $period);
        $summary = $this->summary(clone $orders);
        $comparisonOrders = $comparisonPeriod === null ? null : $this->orders($store, $comparisonPeriod);
        $comparison = $comparisonOrders === null ? null : $this->summary(clone $comparisonOrders);
        $year = $this->comparisonSummary($store, $period, 'year');
        $rankings = $this->productInsights($store, $period);
        $inventory = $this->inventoryInsights($store, $period);

        $result = [
            'period' => [
                'days' => $period['days'],
                'from' => $period['local_start']->toDateString(),
                'to' => $period['local_end']->toDateString(),
                'timezone' => $period['timezone'],
                'include_test' => $period['include_test'],
                'include_cancelled' => $period['include_cancelled'],
            ],
            'comparison' => $this->comparisonMeta($filters, $period, $comparisonPeriod),
            'summary' => [...$summary, 'sales' => (string) $summary['net_sales']],
            'comparisons' => [
                'previous' => $comparison === null
                    ? $this->unavailableComparison($summary)
                    : $this->compare($summary, $comparison),
                'year_over_year' => $this->compare($summary, $year),
            ],
            'trend' => $this->trend($orders, $period),
            'comparison_trend' => [
                'previous' => $comparisonOrders === null || $comparisonPeriod === null
                    ? []
                    : $this->trend($comparisonOrders, $comparisonPeriod),
            ],
            'customers' => $this->customerInsights($store, $period),
            'rankings' => $rankings,
            'inventory' => $inventory,
            'top_products' => collect($rankings['products'])->take(8)->map(fn (array $item): array => [
                'product_id' => $item['product_id'],
                'title' => $item['name'],
                'units' => $item['units'],
                'revenue' => $item['net_sales'],
            ])->all(),
            'low_stock' => collect($inventory['items'])->filter(fn (array $item): bool => in_array($item['risk'], ['out_of_stock', 'low_stock'], true))->take(8)->map(fn (array $item): array => [
                'id' => $item['id'], 'sku' => $item['sku'], 'available' => $item['available'],
            ])->all(),
            'definitions' => [
                'gross_sales' => '商品原始小计加折扣，不含税费和运费。',
                'net_sales' => '商品原始小计减退款后的当前净商品销售额，不含税费和运费。',
                'inventory_turnover' => '当前周期售出数量 ÷ 平均可用库存的估算值。',
            ],
            'data_source' => [
                'primary' => 'local_sync',
                'fallback' => null,
                'timezone' => $period['timezone'],
                'storage' => null,
            ],
        ];

        return $this->applyShopifySales($store, $period, $comparisonPeriod, $result);
    }

    /**
     * Shopify Analytics uses the store's reporting timezone and its own sales
     * accounting semantics. Prefer that canonical source when the requested
     * filters match Shopify's native report; keep synchronized data as a safe
     * fallback and for app-only test/cancelled-order filtering.
     *
     * @param  array<string, mixed>  $period
     * @param  array<string, mixed>|null  $comparisonPeriod
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function applyShopifySales(Store $store, array $period, ?array $comparisonPeriod, array $result): array
    {
        if ($period['include_test'] || ! $period['include_cancelled']) {
            $result['data_source']['semantic_mode'] = 'decoadmin_custom';
            $result['data_source']['notice'] = '启用测试订单或排除取消订单时使用 DecoAdmin 自定义筛选口径。';

            return $result;
        }

        $from = $period['local_start']->toDateString();
        $to = $period['local_end']->toDateString();
        $current = $this->shopifyReports->report($store, 'core-sales-timeseries', $from, $to);
        if (! is_array($current) || ! ($current['available'] ?? false) || ($current['rows'] ?? []) === []) {
            $currentPending = (bool) data_get($current, 'storage.pending', false)
                || (bool) data_get($current, 'storage.refreshing', false);
            $result['data_source']['semantic_mode'] = 'decoadmin_custom';
            $result['data_source']['storage'] = data_get($current, 'storage');
            $result['data_source']['pending'] = $currentPending;
            $result['data_source']['comparison_pending'] = false;
            $result['data_source']['notice'] = $currentPending
                ? 'Shopify 统计报表正在准备，页面会自动刷新。'
                : 'Shopify 原生报表当前不可用，暂时显示本地同步数据。';

            return $result;
        }

        $currentSummary = $this->shopifySummary($current['rows']);
        if ($currentSummary === null) {
            return $result;
        }

        $year = $this->shopifyReports->report($store, 'core-sales-year-comparison', $from, $to);
        $yearSummary = ($year['available'] ?? false)
            ? $this->shopifyComparisonSummary($year['rows'] ?? [], 'previous_year')
            : null;

        $localComparison = $result['comparisons']['previous'] ?? [];
        $localComparisonTrend = $result['comparison_trend']['previous'] ?? [];
        $result['summary'] = [...$currentSummary, 'sales' => (string) $currentSummary['net_sales']];
        $result['trend'] = $this->shopifyTrend($current['rows'], $period);
        $result['comparisons']['previous'] = $this->unavailableComparison($currentSummary);
        $result['comparison_trend']['previous'] = [];

        $comparisonMode = (string) ($result['comparison']['mode'] ?? 'previous');
        $comparisonSource = $comparisonMode === 'none' ? null : 'shopifyql';
        $comparisonResolved = false;
        $comparisonPending = $comparisonMode === 'year'
            && ((bool) data_get($year, 'storage.pending', false) || (bool) data_get($year, 'storage.refreshing', false));
        if ($comparisonPeriod !== null && $comparisonMode === 'previous') {
            $previousSummary = $this->shopifyComparisonSummary($current['rows'], 'previous_period');
            if ($previousSummary !== null) {
                $result['comparisons']['previous'] = $this->shopifyComparison(
                    $currentSummary,
                    $previousSummary,
                    $current['rows'],
                    'previous_period',
                );
                $result['comparison_trend']['previous'] = $this->shopifyComparisonTrend(
                    $current['rows'],
                    $comparisonPeriod,
                    'previous_period',
                );
                $comparisonResolved = true;
            }
        } elseif ($comparisonPeriod !== null && $comparisonMode === 'year' && $yearSummary !== null) {
            $result['comparisons']['previous'] = $this->shopifyComparison(
                $currentSummary,
                $yearSummary,
                $year['rows'] ?? [],
                'previous_year',
            );
            $result['comparison_trend']['previous'] = $this->shopifyComparisonTrend(
                $year['rows'] ?? [],
                $comparisonPeriod,
                'previous_year',
            );
            $comparisonResolved = true;
        } elseif ($comparisonPeriod !== null && in_array($comparisonMode, ['custom', 'year_weekday'], true)) {
            $baseline = $this->shopifyReports->report(
                $store,
                'core-sales-timeseries',
                $comparisonPeriod['local_start']->toDateString(),
                $comparisonPeriod['local_end']->toDateString(),
            );
            $comparisonPending = (bool) data_get($baseline, 'storage.pending', false)
                || (bool) data_get($baseline, 'storage.refreshing', false);
            $baselineSummary = ($baseline['available'] ?? false)
                ? $this->shopifySummary($baseline['rows'] ?? [])
                : null;
            if ($baselineSummary !== null) {
                $result['comparisons']['previous'] = $this->compare($currentSummary, $baselineSummary);
                $result['comparison_trend']['previous'] = $this->shopifyTrend(
                    $baseline['rows'] ?? [],
                    $comparisonPeriod,
                );
                $comparisonResolved = true;
            }
        }

        if ($comparisonPeriod !== null && ! $comparisonResolved) {
            $metrics = [
                'net_sales', 'gross_sales', 'total_sales', 'orders', 'average_order_value',
                'refunds', 'discounts', 'taxes', 'shipping',
            ];
            $localBaseline = collect($metrics)->mapWithKeys(function (string $metric) use ($localComparison): array {
                $value = data_get($localComparison, "{$metric}.baseline");

                return is_numeric($value) ? [$metric => (float) $value] : [];
            })->all();
            if (count($localBaseline) === count($metrics)) {
                $result['comparisons']['previous'] = $this->compare($currentSummary, $localBaseline);
                $result['comparison_trend']['previous'] = $localComparisonTrend;
                $comparisonSource = 'local_sync';
            }
        }

        if ($yearSummary !== null) {
            $result['comparisons']['year_over_year'] = $this->shopifyComparison(
                $currentSummary,
                $yearSummary,
                $year['rows'] ?? [],
                'previous_year',
            );
        } else {
            $result['comparisons']['year_over_year'] = $this->unavailableComparison($currentSummary);
        }

        $result = $this->applyShopifyCatalogInsights($store, $period, $result);
        $result['data_source'] = [
            'primary' => 'shopifyql',
            'fallback' => 'local_sync',
            'timezone' => $period['timezone'],
            'storage' => $current['storage'] ?? null,
            'semantic_mode' => 'shopify_native',
            'comparison' => $comparisonMode,
            'comparison_source' => $comparisonSource,
            'pending' => false,
            'comparison_pending' => $comparisonPending,
            'traffic_filter' => ['human', 'bot'],
            'snapshot_delay_minutes' => 15,
        ];

        return $result;
    }

    /** @param list<array<string, mixed>> $rows */
    private function shopifyComparisonSummary(array $rows, string $comparison): ?array
    {
        $totals = collect($rows)->first(fn (array $row): bool => collect(array_keys($row))->contains(
            fn (string $key): bool => str_contains($key, "comparison_total_sales__{$comparison}")
                && str_ends_with($key, '__totals'),
        ));

        if (! is_array($totals)) {
            return null;
        }

        return [
            'gross_sales' => $this->comparisonValue($totals, 'gross_sales', $comparison, true),
            'net_sales' => $this->comparisonValue($totals, 'net_sales', $comparison, true),
            'discounts' => abs($this->comparisonValue($totals, 'discounts', $comparison, true)),
            'refunds' => abs($this->comparisonValue($totals, 'returns', $comparison, true)),
            'taxes' => $this->comparisonValue($totals, 'taxes', $comparison, true),
            'shipping' => $this->comparisonValue($totals, 'shipping_charges', $comparison, true),
            'total_sales' => $this->comparisonValue($totals, 'total_sales', $comparison, true),
            'orders' => (int) round($this->comparisonValue($totals, 'orders', $comparison, true)),
            'average_order_value' => $this->comparisonValue($totals, 'average_order_value', $comparison, true),
        ];
    }

    /** @param array<string, mixed> $row */
    private function comparisonValue(array $row, string $metric, string $comparison, bool $totals = false): float
    {
        $prefix = "comparison_{$metric}__{$comparison}";
        $expected = $prefix.($totals ? '__totals' : '');
        $key = array_key_exists($expected, $row) ? $expected : null;

        return $this->decimal($key === null ? 0 : ($row[$key] ?? 0));
    }

    /** @param array<string, mixed> $row */
    private function percentChangeValue(array $row, string $metric, string $comparison, bool $totals = false): ?float
    {
        $prefix = "percent_change_{$metric}__{$comparison}";
        $expected = $prefix.($totals ? '__totals' : '');
        $key = array_key_exists($expected, $row) ? $expected : null;
        $value = $key === null ? null : ($row[$key] ?? null);

        return is_numeric($value) ? round((float) $value, 2) : null;
    }

    /**
     * Shopify's percent-change column is the canonical comparison shown in
     * Admin. It can differ from a naïve current/baseline calculation because
     * Shopify applies native reporting rules to partial days and reversals.
     *
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $baseline
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function shopifyComparison(array $current, array $baseline, array $rows, string $comparison): array
    {
        $metrics = $this->compare($current, $baseline);
        $totals = collect($rows)->first(fn (array $row): bool => collect(array_keys($row))->contains(
            fn (string $key): bool => str_starts_with($key, "percent_change_total_sales__{$comparison}")
                && str_ends_with($key, '__totals'),
        ));

        if (! is_array($totals)) {
            return $metrics;
        }

        foreach (array_keys($metrics) as $metric) {
            $shopifyMetric = match ($metric) {
                'refunds' => 'returns',
                'shipping' => 'shipping_charges',
                default => $metric,
            };
            $metrics[$metric]['change_percent'] = $this->percentChangeValue(
                $totals,
                $shopifyMetric,
                $comparison,
                true,
            );
        }

        return $metrics;
    }

    /** @param array<string, mixed> $current @return array<string, mixed> */
    private function unavailableComparison(array $current): array
    {
        return collect([
            'net_sales', 'gross_sales', 'total_sales', 'orders', 'average_order_value',
            'refunds', 'discounts', 'taxes', 'shipping',
        ])->mapWithKeys(fn (string $metric): array => [$metric => [
            'current' => (float) ($current[$metric] ?? 0),
            'baseline' => 0.0,
            'change' => 0.0,
            'change_percent' => null,
        ]])->all();
    }

    /**
     * @param  list<array<string, mixed>>  $reportRows
     * @param  array<string, mixed>  $period
     * @return list<array<string, int|float|string>>
     */
    private function shopifyComparisonTrend(array $reportRows, array $period, string $comparison): array
    {
        $rows = collect($reportRows)->filter(fn (array $row): bool => filled($row['day'] ?? null))->values();

        return collect(range(0, $period['calendar_days'] - 1))->map(function (int $offset) use ($period, $rows, $comparison): array {
            $date = $period['local_start']->addDays($offset);
            $row = $rows->get($offset, []);

            return [
                'date' => $date->toDateString(),
                'label' => $date->format('m/d'),
                'sales' => $this->comparisonValue($row, 'net_sales', $comparison),
                'net_sales' => $this->comparisonValue($row, 'net_sales', $comparison),
                'gross_sales' => $this->comparisonValue($row, 'gross_sales', $comparison),
                'total_sales' => $this->comparisonValue($row, 'total_sales', $comparison),
                'refunds' => abs($this->comparisonValue($row, 'returns', $comparison)),
                'discounts' => abs($this->comparisonValue($row, 'discounts', $comparison)),
                'taxes' => $this->comparisonValue($row, 'taxes', $comparison),
                'shipping' => $this->comparisonValue($row, 'shipping_charges', $comparison),
                'orders' => (int) round($this->comparisonValue($row, 'orders', $comparison)),
                'average_order_value' => $this->comparisonValue($row, 'average_order_value', $comparison),
            ];
        })->all();
    }

    /** @param array<string, mixed> $period @param array<string, mixed> $result */
    private function applyShopifyCatalogInsights(Store $store, array $period, array $result): array
    {
        $from = $period['local_start']->toDateString();
        $to = $period['local_end']->toDateString();
        $reports = collect([
            'products' => 'product-sales',
            'vendors' => 'vendor-sales',
            'product_types' => 'product-type-sales',
            'customer_overview' => 'customer-overview',
            'customer_returning_rate' => 'customer-returning-rate',
        ])->map(fn (string $report): array => $this->shopifyReports->report($store, $report, $from, $to));

        foreach (['products', 'vendors', 'product_types'] as $key) {
            $report = $reports->get($key);
            if (($report['available'] ?? false) && ($report['rows'] ?? []) !== []) {
                $result['rankings'][$key] = $report['rows'];
            }
        }

        $customerOverview = $reports->get('customer_overview');
        $customerRate = $reports->get('customer_returning_rate');
        $customerRows = collect($customerOverview['rows'] ?? []);
        $totals = $customerRows->first(fn (array $row): bool => array_key_exists('customers__totals', $row));
        $rateTotals = collect($customerRate['rows'] ?? [])->first(
            fn (array $row): bool => array_key_exists('returning_customer_rate__totals', $row),
        );
        if (($customerOverview['available'] ?? false) && is_array($totals)) {
            $active = (int) round($this->decimal($totals['customers__totals'] ?? 0));
            $new = $this->customerSegmentCount($customerRows, 'New');
            $returning = $this->customerSegmentCount($customerRows, 'Returning');
            $repeatRate = is_array($rateTotals)
                ? round((float) ($rateTotals['returning_customer_rate__totals'] ?? 0) * 100, 2)
                : ($active > 0 ? round($returning / $active * 100, 2) : 0.0);
            $result['customers'] = [
                'active' => $active,
                'new' => $new,
                'returning' => $returning,
                'repeat_customers' => $returning,
                'repeat_rate' => $repeatRate,
                // Lifetime value is intentionally not mixed into period
                // customer metrics. It remains a separate native report.
                'average_lifetime_value' => null,
                'high_value' => [],
                'source' => 'shopifyql',
                'semantic_mode' => 'shopify_native',
                'period_semantics' => 'shopify_new_or_returning_customer',
            ];
        }

        $result['top_products'] = collect($result['rankings']['products'] ?? [])->take(8)->map(fn (array $item): array => [
            'product_id' => $item['product_id'] ?? null,
            'title' => $item['name'] ?? '未命名商品',
            'units' => $item['units'] ?? 0,
            'revenue' => $item['net_sales'] ?? 0,
        ])->all();

        return $result;
    }

    /** @param Collection<int, array<string, mixed>> $rows */
    private function customerSegmentCount(Collection $rows, string $segment): int
    {
        $row = $rows->first(fn (array $row): bool => strcasecmp(
            (string) ($row['new_or_returning_customer'] ?? ''),
            $segment,
        ) === 0);

        return is_array($row) ? (int) round($this->decimal($row['customers'] ?? 0)) : 0;
    }

    /** @param list<array<string, mixed>> $rows */
    private function shopifySummary(array $rows): ?array
    {
        $totals = collect($rows)->first(fn (array $row): bool => array_key_exists('total_sales__totals', $row));
        if (! is_array($totals)) {
            return null;
        }

        return [
            'gross_sales' => $this->decimal($totals['gross_sales__totals'] ?? 0),
            'net_sales' => $this->decimal($totals['net_sales__totals'] ?? 0),
            'discounts' => abs($this->decimal($totals['discounts__totals'] ?? 0)),
            'refunds' => abs($this->decimal($totals['returns__totals'] ?? 0)),
            'taxes' => $this->decimal($totals['taxes__totals'] ?? 0),
            'shipping' => $this->decimal($totals['shipping_charges__totals'] ?? 0),
            'total_sales' => $this->decimal($totals['total_sales__totals'] ?? 0),
            'orders' => (int) round($this->decimal($totals['orders__totals'] ?? 0)),
            'average_order_value' => $this->decimal($totals['average_order_value__totals'] ?? 0),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $reportRows
     * @param  array<string, mixed>  $period
     * @return list<array<string, int|float|string>>
     */
    private function shopifyTrend(array $reportRows, array $period): array
    {
        $rows = collect($reportRows)->mapWithKeys(function (array $row): array {
            $date = substr((string) ($row['day'] ?? ''), 0, 10);

            return $date === '' ? [] : [$date => $row];
        });

        return collect(range(0, $period['calendar_days'] - 1))->map(function (int $offset) use ($period, $rows): array {
            $date = $period['local_start']->addDays($offset);
            $key = $date->toDateString();
            $row = $rows->get($key, []);

            return [
                'date' => $key,
                'label' => $date->format('m/d'),
                'sales' => $this->decimal($row['net_sales'] ?? 0),
                'net_sales' => $this->decimal($row['net_sales'] ?? 0),
                'gross_sales' => $this->decimal($row['gross_sales'] ?? 0),
                'total_sales' => $this->decimal($row['total_sales'] ?? 0),
                'refunds' => abs($this->decimal($row['returns'] ?? 0)),
                'discounts' => abs($this->decimal($row['discounts'] ?? 0)),
                'taxes' => $this->decimal($row['taxes'] ?? 0),
                'shipping' => $this->decimal($row['shipping_charges'] ?? 0),
                'orders' => (int) round($this->decimal($row['orders'] ?? 0)),
                'average_order_value' => $this->decimal($row['average_order_value'] ?? 0),
            ];
        })->all();
    }

    private function decimal(mixed $value): float
    {
        return round((float) $value, 2);
    }

    /** @param array<string, mixed> $period */
    private function orders(Store $store, array $period): Builder
    {
        return Order::query()
            ->forOrganization($store->organization_id)
            ->forStore($store)
            ->whereBetween('created_at_shopify', [$period['start'], $period['end']])
            ->when(! $period['include_test'], fn (Builder $query) => $query->where('is_test', false))
            ->when(! $period['include_cancelled'], fn (Builder $query) => $query->whereNull('cancelled_at'));
    }

    private function summary(Builder $orders): array
    {
        $row = $orders->selectRaw('COUNT(*) as orders_count')
            ->selectRaw('COALESCE(SUM(subtotal_price + discount_total), 0) as gross_sales')
            ->selectRaw('COALESCE(SUM(net_sales), 0) as net_sales')
            ->selectRaw('COALESCE(SUM(discount_total), 0) as discounts')
            ->selectRaw('COALESCE(SUM(refund_total), 0) as refunds')
            ->selectRaw('COALESCE(SUM(total_tax), 0) as taxes')
            ->selectRaw('COALESCE(SUM(shipping_total), 0) as shipping')
            ->selectRaw('COALESCE(SUM(total_price), 0) as total_sales')
            ->first();
        $count = (int) ($row?->orders_count ?? 0);
        $net = (float) ($row?->net_sales ?? 0);

        return [
            'gross_sales' => round((float) ($row?->gross_sales ?? 0), 2),
            'net_sales' => round($net, 2),
            'discounts' => round((float) ($row?->discounts ?? 0), 2),
            'refunds' => round((float) ($row?->refunds ?? 0), 2),
            'taxes' => round((float) ($row?->taxes ?? 0), 2),
            'shipping' => round((float) ($row?->shipping ?? 0), 2),
            'total_sales' => round((float) ($row?->total_sales ?? 0), 2),
            'orders' => $count,
            'average_order_value' => $count > 0 ? round($net / $count, 2) : 0,
        ];
    }

    /** @param array<string, mixed> $period */
    private function trend(Builder $orders, array $period): array
    {
        $rows = [];
        foreach (range(0, $period['calendar_days'] - 1) as $offset) {
            $date = $period['local_start']->addDays($offset);
            $rows[$date->toDateString()] = [
                'date' => $date->toDateString(), 'label' => $date->format('m/d'),
                'sales' => 0.0, 'net_sales' => 0.0, 'gross_sales' => 0.0,
                'total_sales' => 0.0, 'refunds' => 0.0, 'discounts' => 0.0,
                'taxes' => 0.0, 'shipping' => 0.0, 'orders' => 0,
                'average_order_value' => 0.0,
            ];
        }

        (clone $orders)->select([
            'id', 'created_at_shopify', 'subtotal_price', 'discount_total', 'net_sales',
            'refund_total', 'total_tax', 'shipping_total', 'total_price',
        ])->orderBy('id')->lazyById(1000)
            ->each(function (Order $order) use (&$rows, $period): void {
                $key = $order->created_at_shopify?->timezone($period['timezone'])->toDateString();
                if ($key && isset($rows[$key])) {
                    $rows[$key]['orders']++;
                    $rows[$key]['net_sales'] += (float) $order->net_sales;
                    $rows[$key]['sales'] += (float) $order->net_sales;
                    $rows[$key]['gross_sales'] += (float) $order->subtotal_price + (float) $order->discount_total;
                    $rows[$key]['total_sales'] += (float) $order->total_price;
                    $rows[$key]['refunds'] += (float) $order->refund_total;
                    $rows[$key]['discounts'] += (float) $order->discount_total;
                    $rows[$key]['taxes'] += (float) $order->total_tax;
                    $rows[$key]['shipping'] += (float) $order->shipping_total;
                }
            });

        foreach ($rows as &$row) {
            $row['average_order_value'] = $row['orders'] > 0
                ? round($row['net_sales'] / $row['orders'], 2)
                : 0.0;
            foreach (['sales', 'net_sales', 'gross_sales', 'total_sales', 'refunds', 'discounts', 'taxes', 'shipping'] as $metric) {
                $row[$metric] = round($row[$metric], 2);
            }
        }
        unset($row);

        return array_values($rows);
    }

    /** @param array<string, mixed> $period */
    private function customerInsights(Store $store, array $period): array
    {
        $orders = $this->orders($store, $period)->whereNotNull('shopify_customer_id');
        $active = (clone $orders)->distinct('shopify_customer_id')->count('shopify_customer_id');
        $repeat = (clone $orders)->select('shopify_customer_id')->groupBy('shopify_customer_id')->havingRaw('COUNT(*) >= 2')->get()->count();
        $new = Customer::query()->forOrganization($store->organization_id)->forStore($store)
            ->whereBetween('created_at_shopify', [$period['start'], $period['end']])->count();
        $lifetime = Customer::query()->forOrganization($store->organization_id)->forStore($store);

        return [
            'active' => $active,
            'new' => $new,
            'returning' => max(0, $active - $new),
            'repeat_customers' => $repeat,
            'repeat_rate' => $active > 0 ? round($repeat / $active * 100, 2) : 0,
            'average_lifetime_value' => round((float) (clone $lifetime)->avg('total_spent'), 2),
            'high_value' => (clone $lifetime)->orderByDesc('total_spent')->limit(200)->get(['id', 'first_name', 'last_name', 'orders_count', 'total_spent'])
                ->map(fn (Customer $customer): array => [
                    'id' => $customer->id,
                    'name' => trim($customer->first_name.' '.$customer->last_name) ?: '未命名客户',
                    'orders' => $customer->orders_count,
                    'lifetime_value' => (float) $customer->total_spent,
                ])->all(),
        ];
    }

    /** @param array<string, mixed> $period */
    private function productInsights(Store $store, array $period): array
    {
        $base = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->leftJoin('products', 'products.id', '=', 'order_items.product_id')
            ->where('orders.organization_id', $store->organization_id)
            ->where('orders.store_id', $store->id)
            ->whereBetween('orders.created_at_shopify', [$period['start'], $period['end']])
            ->when(! $period['include_test'], fn ($query) => $query->where('orders.is_test', false))
            ->when(! $period['include_cancelled'], fn ($query) => $query->whereNull('orders.cancelled_at'));

        $products = (clone $base)->selectRaw('order_items.product_id, order_items.title as name, COALESCE(products.vendor, "未分类供应商") as vendor, COALESCE(products.product_type, "未分类") as product_type')
            ->selectRaw('SUM(order_items.quantity) as units, SUM(order_items.quantity * order_items.price) as net_sales')
            ->groupBy('order_items.product_id', 'order_items.title', 'products.vendor', 'products.product_type')
            ->orderByDesc('net_sales')->limit(200)->get()->map(fn (object $row): array => [
                'product_id' => $row->product_id ? (int) $row->product_id : null,
                'name' => $row->name,
                'vendor' => $row->vendor,
                'product_type' => $row->product_type,
                'units' => (int) $row->units,
                'net_sales' => round((float) $row->net_sales, 2),
            ])->all();

        $aggregate = function (string $column, string $alias) use ($base): array {
            return (clone $base)->selectRaw("COALESCE(products.{$column}, '未分类') as name")
                ->selectRaw('SUM(order_items.quantity) as units, SUM(order_items.quantity * order_items.price) as net_sales')
                ->groupBy("products.{$column}")->orderByDesc('net_sales')->limit(100)->get()
                ->map(fn (object $row): array => [$alias => $row->name, 'units' => (int) $row->units, 'net_sales' => round((float) $row->net_sales, 2)])->all();
        };

        return ['products' => $products, 'vendors' => $aggregate('vendor', 'vendor'), 'product_types' => $aggregate('product_type', 'product_type')];
    }

    /** @param array<string, mixed> $period */
    private function inventoryInsights(Store $store, array $period): array
    {
        $sales = DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.store_id', $store->id)->whereBetween('orders.created_at_shopify', [$period['start'], $period['end']])
            ->when(! $period['include_test'], fn ($query) => $query->where('orders.is_test', false))
            ->when(! $period['include_cancelled'], fn ($query) => $query->whereNull('orders.cancelled_at'))
            ->selectRaw('order_items.variant_id, SUM(order_items.quantity) as units_sold')->groupBy('order_items.variant_id');

        $items = InventoryItem::query()->where('inventory_items.organization_id', $store->organization_id)->where('inventory_items.store_id', $store->id)
            ->leftJoin('inventory_levels', 'inventory_levels.inventory_item_id', '=', 'inventory_items.id')
            ->leftJoinSub($sales, 'period_sales', fn ($join) => $join->on('period_sales.variant_id', '=', 'inventory_items.variant_id'))
            ->selectRaw('inventory_items.id, inventory_items.sku, COALESCE(SUM(inventory_levels.available), 0) as available, COALESCE(MAX(period_sales.units_sold), 0) as units_sold')
            ->groupBy('inventory_items.id', 'inventory_items.sku')->get()->map(function (object $row) use ($period): array {
                $available = (int) $row->available;
                $sold = (int) $row->units_sold;
                $daily = $period['calendar_days'] > 0 ? $sold / $period['calendar_days'] : 0;
                $daysCover = $daily > 0 ? round($available / $daily, 1) : null;
                $risk = $available <= 0 ? 'out_of_stock' : ($available <= 10 || ($daysCover !== null && $daysCover <= 14) ? 'low_stock' : ($sold === 0 ? 'slow_moving' : 'healthy'));

                return [
                    'id' => (int) $row->id, 'sku' => $row->sku ?: '未设置 SKU', 'available' => $available,
                    'units_sold' => $sold, 'estimated_days_cover' => $daysCover,
                    'turnover' => $available > 0 ? round($sold / $available, 2) : null, 'risk' => $risk,
                ];
            })->sortBy(fn (array $item): int => match ($item['risk']) {
                'out_of_stock' => 0, 'low_stock' => 1, 'slow_moving' => 2, default => 3,
            })->values();

        return [
            'summary' => [
                'out_of_stock' => $items->where('risk', 'out_of_stock')->count(),
                'low_stock' => $items->where('risk', 'low_stock')->count(),
                'slow_moving' => $items->where('risk', 'slow_moving')->count(),
            ],
            'items' => $items->take(200)->all(),
        ];
    }

    /** @param array<string, mixed> $summary @param array<string, mixed> $baseline */
    private function compare(array $summary, array $baseline): array
    {
        $metrics = [];
        foreach (['net_sales', 'gross_sales', 'total_sales', 'orders', 'average_order_value', 'refunds', 'discounts', 'taxes', 'shipping'] as $metric) {
            $current = (float) $summary[$metric];
            $before = (float) $baseline[$metric];
            $change = $current - $before;
            $metrics[$metric] = [
                'current' => $current, 'baseline' => $before, 'change' => round($change, 2),
                'change_percent' => $before != 0.0 ? round($change / abs($before) * 100, 2) : null,
            ];
        }

        return $metrics;
    }

    /** @param array<string, mixed> $period */
    private function comparisonSummary(Store $store, array $period, string $type): array
    {
        return $this->summary($this->orders($store, $this->relativeComparisonPeriod($period, $type)));
    }

    /** @param array<string, mixed> $period */
    private function relativeComparisonPeriod(array $period, string $type): array
    {
        $localEnd = $type === 'year' ? $period['local_end']->subYear() : $period['local_start']->subDay()->endOfDay();
        $localStart = $type === 'year' ? $period['local_start']->subYear() : $localEnd->subDays($period['calendar_days'] - 1)->startOfDay();

        return [...$period, 'local_start' => $localStart, 'local_end' => $localEnd, 'start' => $localStart->utc(), 'end' => $localEnd->utc()];
    }

    /**
     * @param  array<string, mixed>  $period
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>|null
     */
    private function selectedComparisonPeriod(Store $store, array $period, array $filters): ?array
    {
        $mode = (string) ($filters['comparison'] ?? 'previous');
        if ($mode === 'none') {
            return null;
        }

        $timezone = $store->timezone ?: 'UTC';
        if ($mode === 'custom'
            && filled($filters['comparison_date_from'] ?? null)
            && filled($filters['comparison_date_to'] ?? null)) {
            try {
                $localStart = CarbonImmutable::createFromFormat(
                    '!Y-m-d',
                    (string) $filters['comparison_date_from'],
                    $timezone,
                )->startOfDay();
                $localEnd = CarbonImmutable::createFromFormat(
                    '!Y-m-d',
                    (string) $filters['comparison_date_to'],
                    $timezone,
                )->endOfDay();

                return $this->makePeriod($period, $localStart, $localEnd);
            } catch (Throwable) {
                return null;
            }
        }

        if ($mode === 'year') {
            return $this->makePeriod(
                $period,
                $period['local_start']->subYear()->startOfDay(),
                $period['local_end']->subYear()->endOfDay(),
            );
        }

        if ($mode === 'year_weekday') {
            return $this->makePeriod(
                $period,
                $period['local_start']->subDays(364)->startOfDay(),
                $period['local_end']->subDays(364)->endOfDay(),
            );
        }

        return $this->relativeComparisonPeriod($period, 'previous');
    }

    /** @param array<string, mixed> $period */
    private function makePeriod(array $period, CarbonImmutable $localStart, CarbonImmutable $localEnd): array
    {
        return [
            ...$period,
            'days' => min(366, (int) $localStart->diffInDays($localEnd) + 1),
            'calendar_days' => min(366, (int) $localStart->diffInDays($localEnd) + 1),
            'local_start' => $localStart,
            'local_end' => $localEnd,
            'start' => $localStart->utc(),
            'end' => $localEnd->utc(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $period
     * @param  array<string, mixed>|null  $comparisonPeriod
     */
    private function comparisonMeta(array $filters, array $period, ?array $comparisonPeriod): array
    {
        $mode = (string) ($filters['comparison'] ?? 'previous');

        return [
            'mode' => $mode,
            'label' => match ($mode) {
                'none' => '无对比',
                'year' => '去年同期',
                'year_weekday' => '去年同期（匹配星期）',
                'custom' => '自定义对比',
                default => $period['days'] === 1 ? '昨天' : '上一周期',
            },
            'period' => $comparisonPeriod === null ? null : [
                'days' => $comparisonPeriod['days'],
                'from' => $comparisonPeriod['local_start']->toDateString(),
                'to' => $comparisonPeriod['local_end']->toDateString(),
                'timezone' => $comparisonPeriod['timezone'],
            ],
        ];
    }

    /** @param int|array<string, mixed> $filters */
    private function period(Store $store, int|array $filters): array
    {
        $values = is_int($filters) ? ['days' => $filters] : $filters;
        $timezone = $store->timezone ?: 'UTC';
        $days = min(max((int) ($values['days'] ?? 30), 1), 366);
        $now = CarbonImmutable::now($timezone);
        $localEnd = $now->endOfDay();
        $lookbackDays = $days === 1 ? 0 : min($days, 365);
        $localStart = $localEnd->subDays($lookbackDays)->startOfDay();

        if (filled($values['date_from'] ?? null) && filled($values['date_to'] ?? null)) {
            try {
                $localStart = CarbonImmutable::createFromFormat('!Y-m-d', (string) $values['date_from'], $timezone)->startOfDay();
                $localEnd = CarbonImmutable::createFromFormat('!Y-m-d', (string) $values['date_to'], $timezone)->endOfDay();
                $days = min(366, (int) $localStart->diffInDays($localEnd) + 1);
            } catch (Throwable) {
                // The request validator normally prevents invalid dates; presets remain a safe fallback.
            }
        }

        return [
            'days' => $days,
            'calendar_days' => (int) $localStart->diffInDays($localEnd) + 1,
            'timezone' => $timezone, 'local_start' => $localStart, 'local_end' => $localEnd,
            'start' => $localStart->utc(), 'end' => $localEnd->utc(),
            'include_test' => filter_var($values['include_test'] ?? false, FILTER_VALIDATE_BOOL),
            'include_cancelled' => filter_var($values['include_cancelled'] ?? true, FILTER_VALIDATE_BOOL),
        ];
    }
}
