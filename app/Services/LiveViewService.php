<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Store;
use App\Models\StorefrontEvent;
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
        $events = StorefrontEvent::query()
            ->forOrganization($store->organization_id)
            ->forStore($store)
            ->whereBetween('occurred_at', [$from->utc(), $now->utc()])
            ->orderBy('occurred_at')
            ->get();
        $recentEvents = $events->filter(fn (StorefrontEvent $event): bool => $event->occurred_at !== null
            && $event->occurred_at->greaterThanOrEqualTo($now->subMinutes(5)->utc()));
        $sessions = $events->pluck('session_id_hash')->unique();
        $recentSessions = $recentEvents->pluck('session_id_hash')->unique();
        $completedSessions = $events->where('event_name', 'checkout_completed')->pluck('session_id_hash')->unique();
        $cartSessions = $events->where('event_name', 'product_added_to_cart')->pluck('session_id_hash')->unique()->diff($completedSessions);
        $checkoutSessions = $events->whereIn('event_name', [
            'checkout_started', 'checkout_contact_info_submitted', 'checkout_address_info_submitted',
            'checkout_shipping_info_submitted', 'payment_info_submitted',
        ])->pluck('session_id_hash')->unique()->diff($completedSessions);
        $trafficAvailable = $events->isNotEmpty();
        $locations = $this->locations($events);

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
                'current_visitors' => $trafficAvailable ? $this->availableMetric($recentSessions->count()) : $this->unavailableMetric('Web Pixel 尚未收到事件。'),
                'visits' => $trafficAvailable ? $this->availableMetric($sessions->count(), $this->eventSparkline($events, $from)) : $this->unavailableMetric('Web Pixel 尚未收到事件。'),
                'orders' => $this->availableMetric($orders->count(), $this->sparkline($orders, $from, 'count')),
                'net_sales' => $this->availableMetric(round((float) $orders->sum('net_sales'), 2), $this->sparkline($orders, $from, 'sales')),
            ],
            'customer_behavior' => [
                'active_carts' => $trafficAvailable ? $this->availableMetric($cartSessions->count()) : $this->unavailableMetric('需接入 Web Pixel 购物车事件。'),
                'checking_out' => $trafficAvailable ? $this->availableMetric($checkoutSessions->count()) : $this->unavailableMetric('需接入 Web Pixel 结账事件。'),
                'purchased' => $trafficAvailable ? $this->availableMetric($completedSessions->count()) : $this->availableMetric($orders->count()),
            ],
            'insights' => [
                'visits_by_location' => $trafficAvailable ? $this->availableInsight(collect($locations)->map(fn (array $location): array => ['label' => $location['label'], 'value' => $location['visitors']])->all()) : $this->unavailableInsight('Web Pixel 尚未收到粗粒度地点。'),
                'visits_by_source' => $trafficAvailable ? $this->sourceInsight($events) : $this->unavailableInsight('Web Pixel 尚未收到访问来源。'),
                'new_vs_returning' => $this->unavailableInsight('Shopify 不通过公开 Web Pixel 提供实时新客/回头客判定。', 'shopify_internal'),
                'sales_by_product' => $this->unavailableInsight('Shopify 不通过公开接口提供与原生实时视图完全一致的商品销售指标。', 'shopify_internal'),
            ],
            'traffic' => [
                'available' => $trafficAvailable,
                'reason_code' => $trafficAvailable ? null : 'web_pixel_not_connected',
                'message' => $trafficAvailable ? 'Web Pixel 正在接收当前店铺事件。' : '接入 Shopify Web Pixel 后，将展示当前访客、访问来源和粗粒度地点。',
            ],
            'locations' => $locations,
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
    private function unavailableMetric(string $message, string $classification = 'public_integration_required'): array
    {
        return ['value' => 0, 'available' => false, 'message' => $message, 'trend' => [], 'classification' => $classification];
    }

    /** @return array{available: false, message: string, classification: string, items: list<array{label: string, value: float|int}>} */
    private function unavailableInsight(string $message, string $classification = 'public_integration_required'): array
    {
        return ['available' => false, 'message' => $message, 'classification' => $classification, 'items' => []];
    }

    /** @param list<array{label: string, value: float|int}> $items */
    private function availableInsight(array $items): array
    {
        return ['available' => true, 'message' => '', 'classification' => 'web_pixel', 'items' => $items];
    }

    /** @param Collection<int, StorefrontEvent> $events */
    private function sourceInsight(Collection $events): array
    {
        $items = $events->groupBy(fn (StorefrontEvent $event): string => $event->referrer_host ?: '直接访问')
            ->map(fn (Collection $group, string $label): array => [
                'label' => $label,
                'value' => $group->pluck('session_id_hash')->unique()->count(),
            ])->sortByDesc('value')->take(8)->values()->all();

        return $this->availableInsight($items);
    }

    /** @param Collection<int, StorefrontEvent> $events @return list<array<string, mixed>> */
    private function locations(Collection $events): array
    {
        return $events->filter(fn (StorefrontEvent $event): bool => $event->country_code !== null)
            ->groupBy(fn (StorefrontEvent $event): string => implode('|', array_filter([$event->country_code, $event->region_code, $event->city])))
            ->map(function (Collection $group, string $key): array {
                /** @var StorefrontEvent $event */
                $event = $group->first();
                [$latitude, $longitude] = $this->coarseCoordinates((string) $event->country_code, $key);
                $label = implode(' · ', array_filter([$event->country_code, $event->region_code, $event->city]));

                return [
                    'id' => sha1($key),
                    'label' => $label,
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'visitors' => $group->pluck('session_id_hash')->unique()->count(),
                    'orders' => $group->where('event_name', 'checkout_completed')->pluck('session_id_hash')->unique()->count(),
                ];
            })->sortByDesc('visitors')->take(30)->values()->all();
    }

    /** @return array{0: float, 1: float} */
    private function coarseCoordinates(string $countryCode, string $seed): array
    {
        $centres = [
            'US' => [39.5, -98.35], 'CA' => [56.13, -106.35], 'GB' => [55.38, -3.44],
            'DE' => [51.17, 10.45], 'FR' => [46.23, 2.21], 'ES' => [40.46, -3.75],
            'IT' => [41.87, 12.57], 'AU' => [-25.27, 133.78], 'JP' => [36.20, 138.25],
            'CN' => [35.86, 104.20], 'IN' => [20.59, 78.96], 'BR' => [-14.24, -51.93],
            'MX' => [23.63, -102.55], 'NL' => [52.13, 5.29], 'SE' => [60.13, 18.64],
        ];
        [$latitude, $longitude] = $centres[$countryCode] ?? [0.0, 0.0];
        $hash = hexdec(substr(sha1($seed), 0, 8));

        return [
            round($latitude + (($hash % 401) - 200) / 100, 4),
            round($longitude + ((intdiv($hash, 401) % 401) - 200) / 100, 4),
        ];
    }

    /** @param Collection<int, StorefrontEvent> $events @return list<float> */
    private function eventSparkline(Collection $events, CarbonImmutable $from): array
    {
        return collect(range(0, 5))->map(function (int $index) use ($events, $from): float {
            $start = $from->addMinutes($index * 5)->utc();
            $end = $start->addMinutes(5);

            return (float) $events->filter(fn (StorefrontEvent $event): bool => $event->occurred_at !== null
                && $event->occurred_at->greaterThanOrEqualTo($start)
                && $event->occurred_at->lessThan($end))
                ->pluck('session_id_hash')->unique()->count();
        })->all();
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
