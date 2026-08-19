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
        $orders = $this->orders($store, $period);
        $events = StorefrontEvent::query()
            ->forOrganization($store->organization_id)
            ->forStore($store)
            ->whereBetween('occurred_at', [$period['start'], $period['end']]);
        $native = $this->reports->overview($store, $period);

        return [
            'schema' => 'business-insights-v1',
            'period' => [
                'days' => $period['days'],
                'from' => $period['local_start']->toDateString(),
                'to' => $period['local_end']->toDateString(),
                'timezone' => $period['timezone'],
            ],
            'channels' => $native['channels'] ?? $this->channels(clone $orders),
            'pos_locations' => $native['pos_locations'] ?? $this->posLocations(clone $orders),
            'pos_staff' => $native['pos_staff'] ?? $this->posStaff($store, $period),
            'traffic' => $native['traffic'] ?? $this->traffic(clone $events),
            'search' => $native['search'] ?? $this->search(clone $events),
            'funnel' => $native['funnel'] ?? $this->funnel(clone $events),
            'coverage' => $this->coverage(clone $orders, $store, $period),
            'integration' => $this->integration($store, $native),
            'data_source' => [
                'primary' => $native['available'] ? 'shopifyql' : 'local',
                'reports_available' => $native['available'],
                'report_errors' => $native['errors'],
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
    private function orders(Store $store, array $period): Builder
    {
        return Order::query()
            ->forOrganization($store->organization_id)
            ->forStore($store)
            ->whereBetween('created_at_shopify', [$period['start'], $period['end']])
            ->where('is_test', false)
            ->whereNull('cancelled_at');
    }

    /** @param array<string, mixed> $filters */
    private function period(Store $store, array $filters): array
    {
        $timezone = $store->timezone ?: 'UTC';
        $days = min(max((int) ($filters['days'] ?? 30), 1), 366);
        $localEnd = CarbonImmutable::now($timezone)->endOfDay();
        $localStart = $localEnd->subDays($days - 1)->startOfDay();

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
            'timezone' => $timezone,
            'local_start' => $localStart,
            'local_end' => $localEnd,
            'start' => $localStart->utc(),
            'end' => $localEnd->utc(),
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
