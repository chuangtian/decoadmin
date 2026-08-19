<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class LiveViewService
{
    /** @return array<string, mixed> */
    public function snapshot(Store $store): array
    {
        $timezone = $store->timezone ?: 'UTC';
        $now = CarbonImmutable::now($timezone);
        $from = $now->subMinutes(30);
        $orders = Order::query()
            ->forOrganization($store->organization_id)
            ->forStore($store)
            ->where('is_test', false)
            ->whereNull('cancelled_at')
            ->whereBetween('processed_at', [$from->utc(), $now->utc()])
            ->get(['id', 'currency', 'net_sales', 'processed_at']);

        return [
            'schema' => 'live-view-v1',
            'store' => [
                'id' => $store->getKey(),
                'name' => $store->name,
                'currency' => $store->currency ?: 'USD',
                'timezone' => $timezone,
            ],
            'period' => [
                'minutes' => 30,
                'from' => $from->toIso8601String(),
                'to' => $now->toIso8601String(),
            ],
            'metrics' => [
                'current_visitors' => $this->unavailableMetric('Web Pixel 尚未接入，暂时无法统计当前访客。'),
                'visits' => $this->unavailableMetric('Web Pixel 尚未接入，暂时无法统计访问量。'),
                'orders' => $this->availableMetric($orders->count(), $this->sparkline($orders, $from, 'count')),
                'net_sales' => $this->availableMetric(round((float) $orders->sum('net_sales'), 2), $this->sparkline($orders, $from, 'sales')),
            ],
            'customer_behavior' => [
                'active_carts' => $this->unavailableMetric('需接入 Web Pixel 购物车事件。'),
                'checking_out' => $this->unavailableMetric('需接入 Web Pixel 结账事件。'),
                'purchased' => $this->availableMetric($orders->count()),
            ],
            'insights' => [
                'visits_by_location' => $this->unavailableInsight(),
                'new_vs_returning' => $this->unavailableInsight(),
                'sales_by_product' => $this->unavailableInsight(),
            ],
            'traffic' => [
                'available' => false,
                'reason_code' => 'web_pixel_not_connected',
                'message' => '接入 Shopify Web Pixel 后，将展示当前访客、访问来源和粗粒度地点。',
            ],
            'locations' => [],
            'privacy' => [
                'location_precision' => 'coarse',
                'raw_ip_collected' => false,
            ],
            'generated_at' => $now->toIso8601String(),
        ];
    }

    /** @return array{value: float|int, available: true, message: null, trend: list<float>} */
    private function availableMetric(float|int $value, array $trend = []): array
    {
        return ['value' => $value, 'available' => true, 'message' => null, 'trend' => $trend];
    }

    /** @return array{value: int, available: false, message: string, trend: list<float>} */
    private function unavailableMetric(string $message): array
    {
        return ['value' => 0, 'available' => false, 'message' => $message, 'trend' => []];
    }

    /** @return array{available: false, message: string, items: list<array{label: string, value: float|int}>} */
    private function unavailableInsight(): array
    {
        return ['available' => false, 'message' => '此日期范围内无数据', 'items' => []];
    }

    /**
     * @param  Collection<int, Order>  $orders
     * @return list<float>
     */
    private function sparkline(Collection $orders, CarbonImmutable $from, string $mode): array
    {
        return collect(range(0, 5))->map(function (int $index) use ($orders, $from, $mode): float {
            $start = $from->addMinutes($index * 5);
            $end = $start->addMinutes(5);
            $bucket = $orders->filter(fn (Order $order): bool => $order->processed_at !== null
                && $order->processed_at->greaterThanOrEqualTo($start)
                && $order->processed_at->lessThan($end));

            return $mode === 'sales'
                ? round((float) $bucket->sum('net_sales'), 2)
                : (float) $bucket->count();
        })->values()->all();
    }
}
