<?php

namespace App\Services;

use App\Models\AdvertisingChannelDailyMetric;
use App\Models\Customer;
use App\Models\MetaAdInsight;
use App\Models\ProductVariant;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class AnalyticsOperatingMetricsService
{
    public function __construct(private WholeBikeOrderMetricsService $wholeBikeOrders) {}

    /** @var array<string, string> */
    private const CHANNELS = [
        'facebook' => 'Facebook',
        'google' => 'Google',
        'tiktok' => 'TikTok',
        'bing' => 'Bing',
        'criteo' => 'Criteo',
    ];

    /**
     * @param  array<string, mixed>  $overview
     * @param  array<string, mixed>  $behavior
     * @return array<string, mixed>
     */
    public function forStore(
        Store $store,
        array $overview,
        array $behavior,
        string $comparisonMode = 'previous',
    ): array {
        $period = $overview['period'];
        $timezone = $store->timezone ?: 'UTC';
        $from = CarbonImmutable::createFromFormat('!Y-m-d', (string) $period['from'], $timezone)->startOfDay();
        $to = CarbonImmutable::createFromFormat('!Y-m-d', (string) $period['to'], $timezone)->startOfDay();
        $days = (int) $from->diffInDays($to) + 1;
        $previousTo = $from->subDay();
        $previousFrom = $previousTo->subDays($days - 1);

        $advertising = $this->advertisingSummary($store, $from, $to);
        $previousAdvertising = $comparisonMode === 'previous'
            ? $this->advertisingSummary($store, $previousFrom, $previousTo, false)
            : $this->emptyAdvertising();
        $behaviorAvailable = (bool) ($behavior['available'] ?? false);
        $behaviorMetrics = is_array($behavior['metrics'] ?? null) ? $behavior['metrics'] : [];
        $behaviorTrend = collect($behavior['trend'] ?? [])->keyBy('date');
        $salesTrend = collect($overview['trend'] ?? [])->keyBy('date');
        $adTrend = collect($advertising['trend'])->keyBy('date');
        $dates = $this->trendDates($salesTrend, $behaviorTrend, $adTrend);

        $spend = (float) $advertising['spend'];
        $previousSpend = (float) $previousAdvertising['spend'];
        $netSales = (float) data_get($overview, 'summary.net_sales', 0);
        $previousNetSales = (float) data_get($overview, 'comparisons.previous.net_sales.baseline', 0);
        $sessions = (float) data_get($behaviorMetrics, 'sessions.value', 0);
        $conversionRate = (float) data_get($behaviorMetrics, 'conversion_rate.value', 0);
        $addToCart = (float) data_get($behaviorMetrics, 'add_to_cart.value', 0);
        $checkout = (float) data_get($behaviorMetrics, 'checkout.value', 0);
        $previousSessions = $this->baseline($behaviorMetrics, 'sessions');
        $previousConversionRate = $this->baseline($behaviorMetrics, 'conversion_rate');
        $previousAddToCart = $this->baseline($behaviorMetrics, 'add_to_cart');
        $previousCheckout = $this->baseline($behaviorMetrics, 'checkout');
        $wholeBike = $this->wholeBikeOrders->forStore(
            $store,
            $period,
            is_array(data_get($overview, 'comparison.period')) ? data_get($overview, 'comparison.period') : null,
        );
        $wholeBikeAvailable = (bool) ($wholeBike['available'] ?? false);
        $wholeBikeCurrentOrders = $wholeBikeAvailable ? (float) data_get($wholeBike, 'current.orders', 0) : null;
        $wholeBikeBaselineOrders = $wholeBikeAvailable && is_array($wholeBike['comparison'] ?? null)
            ? (float) data_get($wholeBike, 'comparison.orders', 0)
            : null;
        $wholeBikeCurrentAverage = $wholeBikeAvailable ? (float) data_get($wholeBike, 'current.average_order_value', 0) : null;
        $wholeBikeBaselineAverage = $wholeBikeAvailable && is_array($wholeBike['comparison'] ?? null)
            ? (float) data_get($wholeBike, 'comparison.average_order_value', 0)
            : null;
        $wholeBikeTrend = collect(data_get($wholeBike, 'current.trend', []))->keyBy('date');
        $wholeBikeConversion = $wholeBikeAvailable && $behaviorAvailable && $sessions > 0
            ? round((float) $wholeBikeCurrentOrders / $sessions * 100, 2)
            : null;
        $wholeBikeBaselineConversion = $wholeBikeBaselineOrders !== null && $previousSessions !== null && $previousSessions > 0
            ? round($wholeBikeBaselineOrders / $previousSessions * 100, 2)
            : null;
        $coverageNote = $advertising['available']
            ? sprintf('已同步 %d/%d 个广告渠道', $advertising['available_channels'], count(self::CHANNELS))
            : '未找到该周期的广告平台日级同步记录';
        $behaviorError = trim((string) ($behavior['error'] ?? ''));
        $behaviorNote = $behaviorAvailable
            ? 'ShopifyQL 整站会话口径'
            : ($behaviorError !== '' ? $behaviorError : 'ShopifyQL 报表暂不可用。');
        $addToCartCostNote = ! $behaviorAvailable
            ? $behaviorNote
            : (! $advertising['available']
                ? $coverageNote
                : ($addToCart > 0 ? '广告花费 ÷ 加购会话数' : '当前周期没有可计算的加购会话'));
        $checkoutCostNote = ! $behaviorAvailable
            ? $behaviorNote
            : (! $advertising['available']
                ? $coverageNote
                : ($checkout > 0 ? '广告花费 ÷ 到达结账会话数' : '当前周期没有可计算的结账会话'));

        return [
            'schema' => 'analytics-operating-metrics-v1',
            'comparison' => [
                'mode' => $comparisonMode,
                'label' => (string) data_get($overview, 'comparison.label', $comparisonMode === 'none' ? '无对比' : '上一等长周期'),
                'period' => data_get($overview, 'comparison.period'),
            ],
            'whole_bike' => [
                'available' => $wholeBikeAvailable,
                'product_count' => (int) ($wholeBike['product_count'] ?? 0),
                'product_handles' => $wholeBike['product_handles'] ?? [],
            ],
            'advertising' => [
                'available' => $advertising['available'],
                'complete' => $advertising['complete'],
                'available_channels' => $advertising['available_channels'],
                'expected_channels' => count(self::CHANNELS),
                'channels' => $advertising['channels'],
                'message' => $coverageNote,
            ],
            'behavior' => [
                'available' => $behaviorAvailable,
                'source' => 'shopifyql',
                'message' => $behaviorAvailable ? 'ShopifyQL 整站会话漏斗' : $behaviorNote,
            ],
            'metrics' => [
                'whole_bike_orders' => $this->metric(
                    $wholeBikeAvailable,
                    $wholeBikeCurrentOrders,
                    $wholeBikeBaselineOrders,
                    '仅统计 5 款整车商品的订单',
                    $this->trend($dates, fn (string $date): float => (float) data_get($wholeBikeTrend, "{$date}.orders", 0)),
                    $comparisonMode,
                ),
                'whole_bike_average_order_value' => $this->metric(
                    $wholeBikeAvailable,
                    $wholeBikeCurrentAverage,
                    $wholeBikeBaselineAverage,
                    '整车订单净销售额 ÷ 整车订单数',
                    $this->trend($dates, fn (string $date): float => (float) data_get($wholeBikeTrend, "{$date}.average_order_value", 0)),
                    $comparisonMode,
                ),
                'whole_bike_conversion_rate' => $this->metric(
                    $wholeBikeConversion !== null,
                    $wholeBikeConversion,
                    $wholeBikeBaselineConversion,
                    $behaviorAvailable ? '整车订单数 ÷ ShopifyQL 整站会话数' : $behaviorNote,
                    $this->trend($dates, function (string $date) use ($wholeBikeTrend, $behaviorTrend): float {
                        $dailySessions = (float) data_get($behaviorTrend, "{$date}.sessions", 0);

                        return $dailySessions > 0
                            ? (float) data_get($wholeBikeTrend, "{$date}.orders", 0) / $dailySessions * 100
                            : 0.0;
                    }),
                    $comparisonMode,
                ),
                'ad_spend' => $this->metric(
                    $advertising['available'],
                    $spend,
                    $previousAdvertising['available'] ? $previousSpend : null,
                    $coverageNote,
                    $this->trend($dates, fn (string $date): float => (float) data_get($adTrend, "{$date}.spend", 0)),
                    $comparisonMode,
                ),
                'roi' => $this->metric(
                    $advertising['available'] && $spend > 0,
                    $spend > 0 ? round($netSales / $spend, 2) : null,
                    $previousAdvertising['available'] && $previousSpend > 0
                        ? round($previousNetSales / $previousSpend, 2)
                        : null,
                    $advertising['available'] ? '净销售额 ÷ 广告花费' : $coverageNote,
                    $this->trend($dates, function (string $date) use ($adTrend, $salesTrend): float {
                        $dailySpend = (float) data_get($adTrend, "{$date}.spend", 0);

                        return $dailySpend > 0
                            ? round((float) data_get($salesTrend, "{$date}.net_sales", 0) / $dailySpend, 2)
                            : 0.0;
                    }),
                    $comparisonMode,
                ),
                'sessions' => $this->metric(
                    $behaviorAvailable,
                    $sessions,
                    $previousSessions,
                    $behaviorNote,
                    $this->trend($dates, fn (string $date): float => (float) data_get($behaviorTrend, "{$date}.sessions", 0)),
                    $comparisonMode,
                    data_get($behaviorMetrics, 'sessions.comparison'),
                ),
                'conversion_rate' => $this->metric(
                    $behaviorAvailable,
                    $conversionRate,
                    $previousConversionRate,
                    $behaviorNote,
                    $this->trend($dates, fn (string $date): float => (float) data_get($behaviorTrend, "{$date}.conversion_rate", 0)),
                    $comparisonMode,
                    data_get($behaviorMetrics, 'conversion_rate.comparison'),
                ),
                'add_to_cart' => $this->metric(
                    $behaviorAvailable,
                    $addToCart,
                    $previousAddToCart,
                    $behaviorAvailable ? '发生加购的 Shopify Session 数' : $behaviorNote,
                    $this->trend($dates, fn (string $date): float => (float) data_get($behaviorTrend, "{$date}.add_to_cart", 0)),
                    $comparisonMode,
                    data_get($behaviorMetrics, 'add_to_cart.comparison'),
                ),
                'checkout' => $this->metric(
                    $behaviorAvailable,
                    $checkout,
                    $previousCheckout,
                    $behaviorAvailable ? '到达结账的 Shopify Session 数' : $behaviorNote,
                    $this->trend($dates, fn (string $date): float => (float) data_get($behaviorTrend, "{$date}.checkout", 0)),
                    $comparisonMode,
                    data_get($behaviorMetrics, 'checkout.comparison'),
                ),
                'add_to_cart_cost' => $this->metric(
                    $advertising['available'] && $behaviorAvailable && $addToCart > 0,
                    $addToCart > 0 ? round($spend / $addToCart, 2) : null,
                    $previousAdvertising['available'] && $previousAddToCart !== null && $previousAddToCart > 0
                        ? round($previousSpend / $previousAddToCart, 2)
                        : null,
                    $addToCartCostNote,
                    $this->trend($dates, function (string $date) use ($adTrend, $behaviorTrend): float {
                        $count = (float) data_get($behaviorTrend, "{$date}.add_to_cart", 0);

                        return $count > 0 ? round((float) data_get($adTrend, "{$date}.spend", 0) / $count, 2) : 0.0;
                    }),
                    $comparisonMode,
                ),
                'checkout_cost' => $this->metric(
                    $advertising['available'] && $behaviorAvailable && $checkout > 0,
                    $checkout > 0 ? round($spend / $checkout, 2) : null,
                    $previousAdvertising['available'] && $previousCheckout !== null && $previousCheckout > 0
                        ? round($previousSpend / $previousCheckout, 2)
                        : null,
                    $checkoutCostNote,
                    $this->trend($dates, function (string $date) use ($adTrend, $behaviorTrend): float {
                        $count = (float) data_get($behaviorTrend, "{$date}.checkout", 0);

                        return $count > 0 ? round((float) data_get($adTrend, "{$date}.spend", 0) / $count, 2) : 0.0;
                    }),
                    $comparisonMode,
                ),
            ],
            'catalog' => [
                'sku_count' => ProductVariant::query()
                    ->whereHas('product', fn ($query) => $query
                        ->where('organization_id', $store->organization_id)
                        ->where('store_id', $store->getKey()))
                    ->whereNotNull('sku')->where('sku', '!=', '')->count(),
                'customer_count' => Customer::query()
                    ->forOrganization($store->organization_id)
                    ->forStore($store)
                    ->count(),
            ],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function advertisingSummary(Store $store, CarbonImmutable $from, CarbonImmutable $to, bool $withTrend = true): array
    {
        $channels = collect(self::CHANNELS)->map(fn (string $name, string $key): array => [
            'key' => $key,
            'name' => $name,
            'available' => false,
            'spend' => 0.0,
            'attributed_sales' => 0.0,
        ]);

        AdvertisingChannelDailyMetric::query()
            ->forOrganization($store->organization_id)
            ->forStore($store)
            ->whereIn('provider', ['google', 'tiktok', 'bing', 'criteo'])
            ->whereBetween('metric_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('provider, COUNT(*) AS row_count, COALESCE(SUM(spend), 0) AS spend, COALESCE(SUM(attributed_sales), 0) AS attributed_sales')
            ->groupBy('provider')
            ->get()
            ->each(function (object $row) use ($channels): void {
                $provider = (string) $row->provider;
                if (! $channels->has($provider)) {
                    return;
                }
                $channels->put($provider, [
                    ...$channels->get($provider),
                    'available' => (int) $row->row_count > 0,
                    'spend' => round(max(0, (float) $row->spend), 2),
                    'attributed_sales' => round(max(0, (float) $row->attributed_sales), 2),
                ]);
            });

        $meta = MetaAdInsight::query()
            ->forOrganization($store->organization_id)
            ->forStore($store)
            ->where('level', 'account')
            ->where('granularity', 'day')
            ->whereBetween('date_start', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('COUNT(*) AS row_count, COALESCE(SUM(spend), 0) AS spend, COALESCE(SUM(purchase_value), 0) AS attributed_sales')
            ->first();
        if ((int) ($meta?->row_count ?? 0) > 0) {
            $channels->put('facebook', [
                ...$channels->get('facebook'),
                'available' => true,
                'spend' => round(max(0, (float) $meta->spend), 2),
                'attributed_sales' => round(max(0, (float) $meta->attributed_sales), 2),
            ]);
        }

        $available = $channels->where('available', true);

        return [
            'available' => $available->isNotEmpty(),
            'complete' => $available->count() === count(self::CHANNELS),
            'available_channels' => $available->count(),
            'spend' => round((float) $available->sum('spend'), 2),
            'attributed_sales' => round((float) $available->sum('attributed_sales'), 2),
            'channels' => $channels->values()->all(),
            'trend' => $withTrend ? $this->advertisingTrend($store, $from, $to) : [],
        ];
    }

    /** @return list<array{date: string, spend: float}> */
    private function advertisingTrend(Store $store, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $daily = AdvertisingChannelDailyMetric::query()
            ->forOrganization($store->organization_id)
            ->forStore($store)
            ->whereIn('provider', ['google', 'tiktok', 'bing', 'criteo'])
            ->whereBetween('metric_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('metric_date AS date, COALESCE(SUM(spend), 0) AS spend')
            ->groupBy('metric_date')->get()
            ->mapWithKeys(fn (object $row): array => [CarbonImmutable::parse((string) $row->date)->toDateString() => (float) $row->spend]);
        MetaAdInsight::query()
            ->forOrganization($store->organization_id)
            ->forStore($store)
            ->where('level', 'account')->where('granularity', 'day')
            ->whereBetween('date_start', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('date_start AS date, COALESCE(SUM(spend), 0) AS spend')
            ->groupBy('date_start')->get()
            ->each(function (object $row) use ($daily): void {
                $date = CarbonImmutable::parse((string) $row->date)->toDateString();
                $daily->put($date, (float) $daily->get($date, 0) + (float) $row->spend);
            });

        $trend = [];
        for ($date = $from; $date->lte($to); $date = $date->addDay()) {
            $trend[] = ['date' => $date->toDateString(), 'spend' => round((float) $daily->get($date->toDateString(), 0), 2)];
        }

        return $trend;
    }

    /** @return array<string, mixed> */
    private function emptyAdvertising(): array
    {
        return ['available' => false, 'complete' => false, 'available_channels' => 0, 'spend' => 0.0, 'attributed_sales' => 0.0, 'channels' => [], 'trend' => []];
    }

    /** @return list<string> */
    private function trendDates(Collection $sales, Collection $behavior, Collection $advertising): array
    {
        return $sales->keys()->merge($behavior->keys())->merge($advertising->keys())->filter()->unique()->sort()->values()->all();
    }

    /** @return list<float> */
    private function trend(array $dates, callable $value): array
    {
        return collect($dates)->map(fn (string $date): float => round((float) $value($date), 2))->all();
    }

    private function baseline(array $metrics, string $key): ?float
    {
        $value = data_get($metrics, "{$key}.comparison.baseline");

        return is_numeric($value) ? (float) $value : null;
    }

    /** @param array<string, mixed>|null $nativeComparison @return array<string, mixed> */
    private function metric(
        bool $available,
        ?float $current,
        ?float $baseline,
        string $note,
        array $trend,
        string $comparisonMode,
        ?array $nativeComparison = null,
    ): array {
        $comparison = null;
        if ($comparisonMode !== 'none' && $available && $current !== null && $baseline !== null) {
            $comparison = is_array($nativeComparison)
                ? $nativeComparison
                : $this->compare($current, $baseline);
        }

        return [
            'available' => $available,
            'value' => $available ? $current : null,
            'comparison' => $comparison,
            'trend' => $trend,
            'note' => $note,
        ];
    }

    /** @return array{current: float, baseline: float, change: float, change_percent: float|null} */
    private function compare(float $current, float $baseline): array
    {
        $change = round($current - $baseline, 2);

        return [
            'current' => $current,
            'baseline' => $baseline,
            'change' => $change,
            'change_percent' => abs($baseline) > 0.000001
                ? round($change / abs($baseline) * 100, 2)
                : (abs($current) < 0.000001 ? 0.0 : null),
        ];
    }
}
