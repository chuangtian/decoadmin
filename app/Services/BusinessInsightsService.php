<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Store;
use App\Models\StorefrontEvent;
use App\Services\Shopify\Analytics\ShopifyAnalyticsReportService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

class BusinessInsightsService
{
    public function __construct(
        private StorefrontPixelSnippetService $pixel,
        private ShopifyAnalyticsReportService $reports,
    ) {}

    /** @param array<string, mixed> $filters */
    public function overview(Store $store, array $filters = []): array
    {
        $period = $this->period($store, $filters);
        $current = $this->snapshot($store, $period);
        $comparisonMode = (string) ($filters['comparison'] ?? 'previous');
        $comparisonPeriod = $this->comparisonPeriod($store, $period, $filters);
        $baseline = $comparisonPeriod === null ? null : $this->snapshot($store, $comparisonPeriod);

        return [
            'schema' => 'business-insights-v1',
            'period' => $this->publicPeriod($period),
            'comparison' => $this->comparisonMeta($filters, $period, $comparisonPeriod),
            'channels' => $this->withChannelComparisons($current['channels'], $baseline['channels'] ?? []),
            'pos_locations' => $current['pos_locations'],
            'pos_staff' => $current['pos_staff'],
            'traffic' => $this->withMetricComparisons($current['traffic'], $baseline['traffic'] ?? null, $comparisonMode),
            'search' => $this->withMetricComparisons($current['search'], $baseline['search'] ?? null),
            'funnel' => $this->withFunnelComparisons($current['funnel'], $baseline['funnel'] ?? [], $comparisonMode),
            'coverage' => $current['coverage'],
            'integration' => $this->integration($store, $current['native']),
            'data_source' => [
                'primary' => $current['native']['available'] ? 'shopifyql' : 'local',
                'reports_available' => $current['native']['available'],
                'report_errors' => $current['native']['errors'],
                'comparison_primary' => $baseline === null
                    ? null
                    : ($baseline['native']['available'] ? 'shopifyql' : 'local'),
                'storage' => $current['native']['storage'] ?? [
                    'persisted' => false,
                    'source' => null,
                    'stale' => false,
                    'fetched_at' => null,
                    'expires_at' => null,
                ],
                'comparison_storage' => $baseline['native']['storage'] ?? null,
            ],
            'privacy' => [
                'raw_ip_collected' => false,
                'raw_payload_stored' => false,
                'identifiers' => 'hmac_sha256',
                'search_query' => 'email_phone_redacted',
            ],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /** @param array<string, mixed> $period */
    private function snapshot(Store $store, array $period): array
    {
        $orders = $this->orders($store, $period);
        $events = StorefrontEvent::query()
            ->forOrganization($store->organization_id)
            ->forStore($store)
            ->whereBetween('occurred_at', [$period['start'], $period['end']]);
        $native = $this->reports->overview($store, $period);

        return [
            'native' => $native,
            'channels' => $native['channels'] ?? $this->channels(clone $orders),
            'pos_locations' => $native['pos_locations'] ?? $this->posLocations(clone $orders),
            'pos_staff' => $native['pos_staff'] ?? $this->posStaff($store, $period),
            'traffic' => $native['traffic'] ?? $this->traffic(clone $events),
            'search' => $native['search'] ?? $this->search(clone $events),
            'funnel' => $native['funnel'] ?? $this->funnel(clone $events),
            'coverage' => $this->coverage(clone $orders, $store, $period),
        ];
    }

    /** @param array<string, mixed> $filters */
    public function localPosOverview(Store $store, array $filters = []): array
    {
        $period = $this->period($store, $filters);
        $orders = $this->orders($store, $period);

        return [
            'locations' => $this->posLocations(clone $orders),
            'staff' => $this->posStaff($store, $period),
        ];
    }

    private function channels(Builder $orders): array
    {
        return $orders
            ->select(['sales_channel', 'sales_channel_name'])
            ->selectRaw('COUNT(*) as orders_count')
            ->selectRaw('COALESCE(SUM(net_sales), 0) as net_sales')
            ->selectRaw('COALESCE(SUM(total_price), 0) as total_sales')
            ->groupBy('sales_channel', 'sales_channel_name')
            ->orderByDesc('total_sales')
            ->limit(100)
            ->get()
            ->map(fn (Order $order): array => [
                'key' => $order->sales_channel ?: 'unknown',
                'name' => $order->sales_channel_name ?: $this->channelLabel($order->sales_channel),
                'orders' => (int) $order->getAttribute('orders_count'),
                'net_sales' => round((float) $order->getAttribute('net_sales'), 2),
                'total_sales' => round((float) $order->getAttribute('total_sales'), 2),
            ])->all();
    }

    private function posLocations(Builder $orders): array
    {
        return $orders->whereNotNull('pos_location_id')
            ->select(['pos_location_id', 'pos_location_name'])
            ->selectRaw('COUNT(*) as orders_count')
            ->selectRaw('COALESCE(SUM(net_sales), 0) as net_sales')
            ->selectRaw('COALESCE(SUM(total_price), 0) as total_sales')
            ->groupBy('pos_location_id', 'pos_location_name')
            ->orderByDesc('total_sales')
            ->limit(100)
            ->get()
            ->map(fn (Order $order): array => [
                'id' => (string) $order->pos_location_id,
                'name' => $order->pos_location_name ?: '未命名 POS 地点',
                'orders' => (int) $order->getAttribute('orders_count'),
                'net_sales' => round((float) $order->getAttribute('net_sales'), 2),
                'total_sales' => round((float) $order->getAttribute('total_sales'), 2),
            ])->all();
    }

    /** @param array<string, mixed> $period */
    private function posStaff(Store $store, array $period): array
    {
        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.organization_id', $store->organization_id)
            ->where('orders.store_id', $store->getKey())
            ->whereBetween('orders.created_at_shopify', [$period['start'], $period['end']])
            ->where('orders.is_test', false)
            ->whereNull('orders.cancelled_at')
            ->whereNotNull('order_items.shopify_staff_id')
            ->select(['order_items.shopify_staff_id', 'order_items.staff_name'])
            ->selectRaw('COUNT(DISTINCT orders.id) as orders_count')
            ->selectRaw('COALESCE(SUM(order_items.current_quantity), 0) as units')
            ->selectRaw('COALESCE(SUM(order_items.attributed_sales), 0) as attributed_sales')
            ->groupBy('order_items.shopify_staff_id', 'order_items.staff_name')
            ->orderByDesc('attributed_sales')
            ->limit(100)
            ->get()
            ->map(fn (object $row): array => [
                'id' => (string) $row->shopify_staff_id,
                'name' => $row->staff_name ?: '未命名 POS 员工',
                'orders' => (int) $row->orders_count,
                'units' => (int) $row->units,
                'attributed_sales' => round((float) $row->attributed_sales, 2),
            ])->all();
    }

    private function traffic(Builder $events): array
    {
        $row = $events->selectRaw('COUNT(DISTINCT session_id_hash) as sessions')
            ->selectRaw("SUM(CASE WHEN event_name = 'page_viewed' THEN 1 ELSE 0 END) as page_views")
            ->selectRaw("COUNT(DISTINCT CASE WHEN event_name = 'product_viewed' THEN session_id_hash END) as product_view_sessions")
            ->selectRaw("COUNT(DISTINCT CASE WHEN event_name = 'checkout_completed' THEN session_id_hash END) as converted_sessions")
            ->first();
        $sessions = (int) ($row?->sessions ?? 0);
        $converted = (int) ($row?->converted_sessions ?? 0);

        return [
            'sessions' => $sessions,
            'page_views' => (int) ($row?->page_views ?? 0),
            'product_view_sessions' => (int) ($row?->product_view_sessions ?? 0),
            'converted_sessions' => $converted,
            'conversion_rate' => $sessions > 0 ? round($converted / $sessions * 100, 2) : 0.0,
        ];
    }

    private function search(Builder $events): array
    {
        $searches = (clone $events)->where('event_name', 'search_submitted');
        $searchCount = (clone $searches)->count();
        $searchSessions = (clone $searches)->select('session_id_hash')->distinct();
        $searchSessionCount = (clone $searchSessions)->count('session_id_hash');
        $converted = $searchSessionCount === 0 ? 0 : (clone $events)
            ->where('event_name', 'checkout_completed')
            ->whereIn('session_id_hash', $searchSessions)
            ->distinct('session_id_hash')
            ->count('session_id_hash');

        return [
            'searches' => $searchCount,
            'sessions' => $searchSessionCount,
            'converted_sessions' => $converted,
            'conversion_rate' => $searchSessionCount > 0
                ? round($converted / $searchSessionCount * 100, 2)
                : 0.0,
            'top_queries' => (clone $searches)
                ->whereNotNull('search_query')
                ->select('search_query')
                ->selectRaw('COUNT(*) as searches_count')
                ->selectRaw('COUNT(DISTINCT session_id_hash) as sessions_count')
                ->groupBy('search_query')
                ->orderByDesc('searches_count')
                ->limit(50)
                ->get()
                ->map(fn (StorefrontEvent $event): array => [
                    'query' => $event->search_query,
                    'searches' => (int) $event->getAttribute('searches_count'),
                    'sessions' => (int) $event->getAttribute('sessions_count'),
                ])->all(),
        ];
    }

    private function funnel(Builder $events): array
    {
        $stages = [
            ['key' => 'sessions', 'label' => '访问 Session', 'event' => null],
            ['key' => 'product_viewed', 'label' => '查看商品', 'event' => 'product_viewed'],
            ['key' => 'product_added_to_cart', 'label' => '加入购物车', 'event' => 'product_added_to_cart'],
            ['key' => 'checkout_started', 'label' => '发起结账', 'event' => 'checkout_started'],
            ['key' => 'checkout_completed', 'label' => '完成购买', 'event' => 'checkout_completed'],
        ];
        $total = (clone $events)->distinct('session_id_hash')->count('session_id_hash');

        return collect($stages)->map(function (array $stage) use ($events, $total): array {
            $sessions = $stage['event'] === null
                ? $total
                : (clone $events)->where('event_name', $stage['event'])->distinct('session_id_hash')->count('session_id_hash');

            return [
                'key' => $stage['key'],
                'label' => $stage['label'],
                'sessions' => $sessions,
                'rate' => $total > 0 ? round($sessions / $total * 100, 2) : 0.0,
            ];
        })->all();
    }

    /** @param array<string, mixed> $period */
    private function coverage(Builder $orders, Store $store, array $period): array
    {
        $total = (clone $orders)->count();

        return [
            'orders' => $total,
            'channel_orders' => (clone $orders)->whereNotNull('sales_channel')->count(),
            'pos_orders' => (clone $orders)->where('sales_channel', 'pos')->count(),
            'pos_location_orders' => (clone $orders)->whereNotNull('pos_location_id')->count(),
            'pos_staff_line_items' => DB::table('order_items')
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->where('orders.organization_id', $store->organization_id)
                ->where('orders.store_id', $store->getKey())
                ->whereBetween('orders.created_at_shopify', [$period['start'], $period['end']])
                ->whereNotNull('order_items.shopify_staff_id')
                ->count(),
        ];
    }

    /** @param array<string, mixed> $native */
    private function integration(Store $store, array $native): array
    {
        $connection = $store->shopifyConnection;
        $scopes = $connection?->scopes ?? [];
        $requiredOrderScopes = ['read_orders', 'read_locations'];
        $reportScopes = ['read_reports'];
        $appPixelScopes = ['write_pixels', 'read_customer_events'];
        $endpoint = $this->pixel->endpoint($store);
        $parts = parse_url($endpoint);
        $publicEndpoint = ($parts['scheme'] ?? null) === 'https'
            && ! in_array($parts['host'] ?? null, ['localhost', '127.0.0.1'], true);
        $latestEvent = $store->storefrontEvents()->latest('received_at')->first(['received_at']);
        $missingOrderScopes = array_values(array_diff($requiredOrderScopes, $scopes));
        $missingReportScopes = array_values(array_diff($reportScopes, $scopes));
        $missingPixelScopes = array_values(array_diff($appPixelScopes, $scopes));
        $steps = [];

        if ($missingOrderScopes !== []) {
            $steps[] = '重新授权 Shopify 店铺，补充权限：'.implode(', ', $missingOrderScopes);
        }
        if ($missingReportScopes !== []) {
            $steps[] = '重新授权 Shopify 店铺，补充报表权限：'.implode(', ', $missingReportScopes);
        }
        if ($missingReportScopes === [] && ! $native['complete']) {
            $steps[] = 'read_reports 已授权，但部分 ShopifyQL 报表暂未返回；页面已对失败项使用本地数据兜底。';
        }
        if (! $native['available'] && ! $publicEndpoint) {
            $steps[] = '将 SHOPIFY_APP_URL 配置为公网 HTTPS 地址，当前 localhost 无法接收店面事件。';
        }
        if (! $native['available'] && ! $latestEvent) {
            $steps[] = '在 Shopify 后台「设置 → 客户事件」新增自定义 Pixel，并粘贴本页代码后连接。';
        }
        if (! $native['available'] && $missingPixelScopes !== []) {
            $steps[] = '若改为自动部署 App Pixel，还需权限：'.implode(', ', $missingPixelScopes).'，以及 Shopify CLI Web Pixel 扩展。';
        }

        return [
            'order_sync_ready' => $connection !== null && $missingOrderScopes === [],
            'reports_ready' => $native['complete'],
            'report_scope_granted' => $missingReportScopes === [],
            'report_errors' => $native['errors'],
            'pixel_receiving' => $latestEvent !== null,
            'last_event_at' => $latestEvent?->received_at?->toIso8601String(),
            'collector_endpoint' => $endpoint,
            'collector_endpoint_public' => $publicEndpoint,
            'pixel_snippet' => $this->pixel->snippet($store),
            'missing_order_scopes' => $missingOrderScopes,
            'missing_report_scopes' => $missingReportScopes,
            'missing_app_pixel_scopes' => $missingPixelScopes,
            'steps' => $steps,
        ];
    }

    /** @param array<string, mixed> $period */
    private function publicPeriod(array $period): array
    {
        return [
            'days' => $period['days'],
            'from' => $period['local_start']->toDateString(),
            'to' => $period['local_end']->toDateString(),
            'timezone' => $period['timezone'],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $currentPeriod
     * @param  array<string, mixed>|null  $comparisonPeriod
     */
    private function comparisonMeta(array $filters, array $currentPeriod, ?array $comparisonPeriod): array
    {
        $mode = (string) ($filters['comparison'] ?? 'previous');

        return [
            'mode' => $mode,
            'label' => match ($mode) {
                'none' => '无对比',
                'year' => '前一年',
                'year_weekday' => '前一年（匹配星期几）',
                'custom' => '自定义对比',
                default => $currentPeriod['days'] === 1 ? '昨天' : '上一周期',
            },
            'period' => $comparisonPeriod === null ? null : $this->publicPeriod($comparisonPeriod),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $current
     * @param  array<int, array<string, mixed>>  $baseline
     * @return array<int, array<string, mixed>>
     */
    private function withChannelComparisons(array $current, array $baseline): array
    {
        $byKey = collect($baseline)->keyBy(fn (array $row): string => (string) ($row['key'] ?? $row['name'] ?? ''));

        return collect($current)->map(function (array $row) use ($byKey): array {
            $previous = $byKey->get((string) ($row['key'] ?? $row['name'] ?? ''));
            $row['comparison'] = $previous === null ? null : [
                'orders' => $this->compareMetric($row['orders'] ?? 0, $previous['orders'] ?? 0),
                'net_sales' => $this->compareMetric($row['net_sales'] ?? 0, $previous['net_sales'] ?? 0),
                'total_sales' => $this->compareMetric($row['total_sales'] ?? 0, $previous['total_sales'] ?? 0),
            ];

            return $row;
        })->all();
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>|null  $baseline
     * @return array<string, mixed>
     */
    private function withMetricComparisons(array $current, ?array $baseline, string $comparisonMode = 'manual'): array
    {
        $native = is_array($current['native_comparison'] ?? null) ? $current['native_comparison'] : [];
        unset($current['native_comparison']);
        $metrics = [];
        if ($baseline !== null) {
            foreach ($current as $key => $value) {
                if (is_int($value) || is_float($value)) {
                    $metrics[$key] = $comparisonMode === 'previous' && is_array($native[$key] ?? null)
                        ? $native[$key]
                        : $this->compareMetric($value, $baseline[$key] ?? 0);
                }
            }
        }
        $current['comparison'] = $baseline === null ? null : $metrics;

        return $current;
    }

    /**
     * @param  array<int, array<string, mixed>>  $current
     * @param  array<int, array<string, mixed>>  $baseline
     * @return array<int, array<string, mixed>>
     */
    private function withFunnelComparisons(array $current, array $baseline, string $comparisonMode): array
    {
        $byKey = collect($baseline)->keyBy('key');

        return collect($current)->map(function (array $row) use ($byKey, $comparisonMode): array {
            $previous = $byKey->get($row['key']);
            $native = is_array($row['native_comparison'] ?? null) ? $row['native_comparison'] : null;
            unset($row['native_comparison']);
            $row['comparison'] = $previous === null ? null : [
                'sessions' => $comparisonMode === 'previous' && $native !== null
                    ? $native
                    : $this->compareMetric($row['sessions'] ?? 0, $previous['sessions'] ?? 0),
                'rate' => $this->compareMetric($row['rate'] ?? 0, $previous['rate'] ?? 0),
            ];

            return $row;
        })->all();
    }

    private function compareMetric(mixed $current, mixed $baseline): array
    {
        $currentValue = (float) $current;
        $baselineValue = (float) $baseline;
        $change = $currentValue - $baselineValue;
        $changePercent = abs($baselineValue) > 0.000001
            ? round($change / abs($baselineValue) * 100, 2)
            : (abs($currentValue) < 0.000001 ? 0.0 : null);

        return [
            'current' => $currentValue,
            'baseline' => $baselineValue,
            'change' => round($change, 2),
            'change_percent' => $changePercent,
        ];
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

    /**
     * @param  array<string, mixed>  $period
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>|null
     */
    private function comparisonPeriod(Store $store, array $period, array $filters): ?array
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

                return $this->makePeriod(
                    $localStart,
                    $localEnd,
                    $timezone,
                    $period['include_test'],
                    $period['include_cancelled'],
                );
            } catch (Throwable) {
                return null;
            }
        }

        if ($mode === 'year') {
            return $this->makePeriod(
                $period['local_start']->subYear()->startOfDay(),
                $period['local_end']->subYear()->endOfDay(),
                $timezone,
                $period['include_test'],
                $period['include_cancelled'],
            );
        }

        if ($mode === 'year_weekday') {
            return $this->makePeriod(
                $period['local_start']->subDays(364)->startOfDay(),
                $period['local_end']->subDays(364)->endOfDay(),
                $timezone,
                $period['include_test'],
                $period['include_cancelled'],
            );
        }

        $localEnd = $period['local_start']->subDay()->endOfDay();

        return $this->makePeriod(
            $localEnd->subDays($period['calendar_days'] - 1)->startOfDay(),
            $localEnd,
            $timezone,
            $period['include_test'],
            $period['include_cancelled'],
        );
    }

    private function makePeriod(
        CarbonImmutable $localStart,
        CarbonImmutable $localEnd,
        string $timezone,
        bool $includeTest = false,
        bool $includeCancelled = true,
    ): array {
        return [
            'days' => min(366, (int) $localStart->diffInDays($localEnd) + 1),
            'calendar_days' => min(366, (int) $localStart->diffInDays($localEnd) + 1),
            'timezone' => $timezone,
            'local_start' => $localStart,
            'local_end' => $localEnd,
            'start' => $localStart->utc(),
            'end' => $localEnd->utc(),
            'include_test' => $includeTest,
            'include_cancelled' => $includeCancelled,
        ];
    }

    /** @param array<string, mixed> $filters */
    private function period(Store $store, array $filters): array
    {
        $timezone = $store->timezone ?: 'UTC';
        $days = min(max((int) ($filters['days'] ?? 30), 1), 366);
        $localEnd = CarbonImmutable::now($timezone)->endOfDay();
        $lookbackDays = $days === 1 ? 0 : min($days, 365);
        $localStart = $localEnd->subDays($lookbackDays)->startOfDay();

        if (filled($filters['date_from'] ?? null) && filled($filters['date_to'] ?? null)) {
            try {
                $localStart = CarbonImmutable::createFromFormat('!Y-m-d', (string) $filters['date_from'], $timezone)->startOfDay();
                $localEnd = CarbonImmutable::createFromFormat('!Y-m-d', (string) $filters['date_to'], $timezone)->endOfDay();
                $days = min(366, (int) $localStart->diffInDays($localEnd) + 1);
            } catch (Throwable) {
                // Request validation normally prevents invalid custom dates.
            }
        }

        return [
            'days' => $days,
            'calendar_days' => (int) $localStart->diffInDays($localEnd) + 1,
            'timezone' => $timezone,
            'local_start' => $localStart,
            'local_end' => $localEnd,
            'start' => $localStart->utc(),
            'end' => $localEnd->utc(),
            'include_test' => filter_var($filters['include_test'] ?? false, FILTER_VALIDATE_BOOL),
            'include_cancelled' => filter_var($filters['include_cancelled'] ?? true, FILTER_VALIDATE_BOOL),
        ];
    }

    private function channelLabel(?string $channel): string
    {
        return match ($channel) {
            'web', 'online_store' => 'Online Store',
            'pos' => 'Point of Sale',
            'shopify_draft_order' => 'Draft Orders',
            'mobile_app' => 'Shopify Mobile',
            null, '' => '未知渠道',
            default => $channel,
        };
    }
}
