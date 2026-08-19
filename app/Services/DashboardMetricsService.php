<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreAlert;
use App\Models\SyncJob;
use App\Models\User;
use App\Models\WebhookEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class DashboardMetricsService
{
    public function __construct(private AnalyticsQueryService $analytics) {}

    /** @return array<string, mixed> */
    public function empty(): array
    {
        return [
            'store' => null,
            'summary' => [
                'orders' => 0, 'sales' => '0', 'orders_today' => 0, 'sales_today' => '0',
                'products' => 0, 'customers' => 0, 'inventory_available' => 0,
            ],
            'operations' => [
                'open_alerts' => 0, 'failed_sync_jobs_24h' => 0, 'failed_webhooks_24h' => 0,
                'connection_status' => 'disconnected', 'last_sync_at' => null,
            ],
            'sales_trend' => [],
            'recent_orders' => [],
            'analytics_30d' => ['summary' => ['sales' => '0', 'orders' => 0, 'average_order_value' => '0.00'], 'trend' => [], 'top_products' => [], 'low_stock' => []],
            'analytics' => [
                'period' => ['days' => 30, 'from' => now()->subDays(29)->toDateString(), 'to' => now()->toDateString(), 'timezone' => 'UTC', 'include_test' => false, 'include_cancelled' => false],
                'summary' => [], 'comparisons' => ['previous' => []], 'trend' => [], 'comparison_trend' => ['previous' => []],
            ],
            'metric_definitions' => $this->metricDefinitions(),
            'store_comparison' => ['stores' => []],
        ];
    }

    /** @return array<string, mixed> */
    public function forStore(Store $store, ?Organization $organization = null, ?User $user = null, array $filters = []): array
    {
        $today = CarbonImmutable::now($store->timezone ?: 'UTC')->startOfDay()->utc();
        $weekStart = $today->subDays(6);
        $orders = Order::query()->where('organization_id', $store->organization_id)->where('store_id', $store->id)
            ->where('is_test', false)->whereNull('cancelled_at');

        $trendRows = (clone $orders)
            ->where('created_at_shopify', '>=', $weekStart)
            ->selectRaw('DATE(created_at_shopify) as day, COUNT(*) as orders_count, COALESCE(SUM(net_sales), 0) as sales_total')
            ->groupByRaw('DATE(created_at_shopify)')
            ->orderBy('day')
            ->get()
            ->keyBy('day');

        $analytics = $this->analytics->sales($store, $filters ?: 30);
        $analytics30d = $filters ? $this->analytics->sales($store, 30) : $analytics;

        return [
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
                'shopify_domain' => $store->shopify_domain,
                'currency' => $store->currency,
                'timezone' => $store->timezone,
            ],
            'summary' => [
                'orders' => (clone $orders)->count(),
                'sales' => (string) (clone $orders)->sum('net_sales'),
                'orders_today' => (clone $orders)->where('created_at_shopify', '>=', $today)->count(),
                'sales_today' => (string) (clone $orders)->where('created_at_shopify', '>=', $today)->sum('net_sales'),
                'products' => Product::query()->where('organization_id', $store->organization_id)->where('store_id', $store->id)->count(),
                'customers' => Customer::query()->where('organization_id', $store->organization_id)->where('store_id', $store->id)->count(),
                'inventory_available' => (int) InventoryItem::query()
                    ->where('inventory_items.organization_id', $store->organization_id)
                    ->where('inventory_items.store_id', $store->id)
                    ->join('inventory_levels', 'inventory_levels.inventory_item_id', '=', 'inventory_items.id')
                    ->sum('inventory_levels.available'),
            ],
            'operations' => [
                'open_alerts' => StoreAlert::query()->where('store_id', $store->id)->whereIn('status', ['open', 'acknowledged'])->count(),
                'failed_sync_jobs_24h' => SyncJob::query()->where('store_id', $store->id)->where('status', 'failed')->where('created_at', '>=', now()->subDay())->count(),
                'failed_webhooks_24h' => WebhookEvent::query()->where('store_id', $store->id)->where('status', 'failed')->where('received_at', '>=', now()->subDay())->count(),
                'connection_status' => $store->shopifyConnection?->status ?? 'disconnected',
                'last_sync_at' => $store->latestSyncJob?->finished_at?->toIso8601String()
                    ?? $store->latestSyncJob?->completed_at?->toIso8601String(),
            ],
            'sales_trend' => $this->trend($weekStart, $trendRows),
            'recent_orders' => (clone $orders)
                ->latest('created_at_shopify')
                ->limit(6)
                ->get(['id', 'order_number', 'email', 'financial_status', 'fulfillment_status', 'currency', 'total_price', 'created_at_shopify'])
                ->map(fn (Order $order): array => [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'email' => $order->email,
                    'financial_status' => $order->financial_status,
                    'fulfillment_status' => $order->fulfillment_status,
                    'currency' => $order->currency,
                    'total_price' => (string) $order->total_price,
                    'processed_at' => $order->processed_at?->toIso8601String() ?? $order->created_at_shopify?->toIso8601String(),
                ])->values(),
            'analytics_30d' => $analytics30d,
            'analytics' => $analytics,
            'metric_definitions' => $this->metricDefinitions(),
            'store_comparison' => $organization && $user
                ? $this->analytics->storeComparison($organization, $user, 30)
                : ['stores' => []],
        ];
    }

    /** @return list<array{key: string, label: string, format: string, description: string}> */
    private function metricDefinitions(): array
    {
        return [
            ['key' => 'net_sales', 'label' => '净销售额', 'format' => 'currency', 'description' => '扣除退款后的商品销售额'],
            ['key' => 'gross_sales', 'label' => '商品销售额', 'format' => 'currency', 'description' => '折扣前商品销售额'],
            ['key' => 'total_sales', 'label' => '总销售额', 'format' => 'currency', 'description' => '包含税费与运费的订单总额'],
            ['key' => 'orders', 'label' => '订单数', 'format' => 'number', 'description' => '统计周期内的有效订单'],
            ['key' => 'average_order_value', 'label' => '平均订单金额', 'format' => 'currency', 'description' => '净销售额除以订单数'],
            ['key' => 'refunds', 'label' => '退款金额', 'format' => 'currency', 'description' => '统计周期内记录的退款'],
            ['key' => 'discounts', 'label' => '折扣金额', 'format' => 'currency', 'description' => '订单优惠与折扣合计'],
            ['key' => 'taxes', 'label' => '税费', 'format' => 'currency', 'description' => '订单税费合计'],
            ['key' => 'shipping', 'label' => '运费', 'format' => 'currency', 'description' => '订单运费合计'],
        ];
    }

    /**
     * @param  Collection<string, object>  $rows
     * @return list<array{date: string, label: string, orders: int, amount: float}>
     */
    private function trend(CarbonImmutable $start, Collection $rows): array
    {
        return collect(range(0, 6))->map(function (int $offset) use ($start, $rows): array {
            $date = $start->addDays($offset);
            $key = $date->format('Y-m-d');
            $row = $rows->get($key);

            return [
                'date' => $key,
                'label' => $date->isoFormat('MM/DD'),
                'orders' => (int) ($row->orders_count ?? 0),
                'amount' => (float) ($row->sales_total ?? 0),
            ];
        })->all();
    }
}
