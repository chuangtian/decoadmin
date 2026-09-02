<?php

namespace App\Services;

use App\Models\Store;
use App\Models\StorefrontEvent;
use App\Services\Shopify\Analytics\ShopifyLiveViewReportService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class LiveViewService
{
    public function __construct(private ShopifyLiveViewReportService $shopifyReports) {}

    /** @return array<string, mixed> */
    public function snapshot(Store $store): array
    {
        $timezone = $store->timezone ?: 'UTC';
        $now = CarbonImmutable::now($timezone);
        $mapFrom = $now->subMinutes(30);
        $events = StorefrontEvent::query()
            ->forOrganization($store->organization_id)
            ->forStore($store)
            ->whereBetween('occurred_at', [$mapFrom->utc(), $now->utc()])
            ->orderBy('occurred_at')
            ->get();
        $currentEvents = $this->eventsSince($events, $now->subMinutes(5));
        $behaviorEvents = $this->eventsSince($events, $now->subMinutes(10));
        $pixelEverReceived = StorefrontEvent::query()
            ->forOrganization($store->organization_id)
            ->forStore($store)
            ->exists();
        $currentSessions = $currentEvents->pluck('session_id_hash')->unique();
        $completedSessions = $behaviorEvents->where('event_name', 'checkout_completed')->pluck('session_id_hash')->unique();
        $cartSessions = $behaviorEvents->where('event_name', 'product_added_to_cart')->pluck('session_id_hash')->unique()->diff($completedSessions);
        $checkoutSessions = $behaviorEvents->whereIn('event_name', [
            'checkout_started', 'checkout_contact_info_submitted', 'checkout_address_info_submitted',
            'checkout_shipping_info_submitted', 'payment_info_submitted',
        ])->pluck('session_id_hash')->unique()->diff($completedSessions);
        $locations = $this->locations($events);
        $shopify = $this->shopifyReports->snapshot($store->loadMissing('shopifyConnection'), $now);
        $salesRows = $this->reportRows($shopify, 'sales');
        $sessionRows = $this->reportRows($shopify, 'sessions');
        $salesAvailable = $this->reportAvailable($shopify, 'sales');
        $sessionsAvailable = $this->reportAvailable($shopify, 'sessions');

        return [
            'schema' => 'live-view-v2',
            'store' => [
                'id' => $store->getKey(),
                'name' => $store->name,
                'currency' => $store->currency ?: 'USD',
                'timezone' => $timezone,
            ],
            'period' => [
                'label' => '今日累计',
                'date' => $now->toDateString(),
                'from' => $now->startOfDay()->toIso8601String(),
                'to' => $now->toIso8601String(),
                'current_visitors_minutes' => 5,
                'customer_behavior_minutes' => 10,
                'map_minutes' => 30,
            ],
            'metrics' => [
                'current_visitors' => $pixelEverReceived
                    ? $this->availableMetric($currentSessions->count(), [], '最近 5 分钟 · Web Pixel')
                    : $this->unavailableMetric('Web Pixel 尚未收到事件。'),
                'total_sales' => $salesAvailable
                    ? $this->availableMetric($this->reportTotal($salesRows, 'total_sales'), $this->reportTrend($salesRows, 'total_sales'), '今日累计 · Shopify Analytics')
                    : $this->unavailableMetric($this->reportError($shopify, 'sales'), 'shopifyql'),
                'visits' => $sessionsAvailable
                    ? $this->availableMetric((int) round($this->reportTotal($sessionRows, 'sessions')), $this->reportTrend($sessionRows, 'sessions'), '今日累计 · Shopify Analytics')
                    : $this->unavailableMetric($this->reportError($shopify, 'sessions'), 'shopifyql'),
                'orders' => $salesAvailable
                    ? $this->availableMetric((int) round($this->reportTotal($salesRows, 'orders')), $this->reportTrend($salesRows, 'orders'), '今日累计 · Shopify Analytics')
                    : $this->unavailableMetric($this->reportError($shopify, 'sales'), 'shopifyql'),
            ],
            'customer_behavior' => [
                'active_carts' => $pixelEverReceived ? $this->availableMetric($cartSessions->count(), [], '最近 10 分钟') : $this->unavailableMetric('需接入 Web Pixel 购物车事件。'),
                'checking_out' => $pixelEverReceived ? $this->availableMetric($checkoutSessions->count(), [], '最近 10 分钟') : $this->unavailableMetric('需接入 Web Pixel 结账事件。'),
                'purchased' => $pixelEverReceived ? $this->availableMetric($completedSessions->count(), [], '最近 10 分钟') : $this->unavailableMetric('需接入 Web Pixel 购买事件。'),
            ],
            'insights' => [
                'visits_by_location' => $this->locationInsight($shopify, $events),
                'visits_by_source' => $this->sourceInsight($shopify, $events),
                'new_vs_returning' => $this->customerInsight($shopify),
                'sales_by_product' => $this->productInsight($shopify),
            ],
            'traffic' => [
                'available' => $pixelEverReceived,
                'receiving' => $events->isNotEmpty(),
                'status' => ! $pixelEverReceived ? 'not_received' : ($events->isNotEmpty() ? 'active' : 'idle'),
                'reason_code' => ! $pixelEverReceived ? 'web_pixel_no_events' : ($events->isEmpty() ? 'web_pixel_idle' : null),
                'message' => ! $pixelEverReceived
                    ? '尚未收到当前店铺的 Web Pixel 事件。'
                    : ($events->isEmpty() ? 'Web Pixel 已有历史数据，但最近 30 分钟没有活动。' : 'Web Pixel 正在接收当前店铺事件。'),
            ],
            'shopify' => [
                'available' => (bool) ($shopify['available'] ?? false),
                'complete' => (bool) ($shopify['complete'] ?? false),
                'source' => 'shopifyql',
                'date' => $shopify['date'] ?? null,
                'fetched_at' => $shopify['fetched_at'] ?? null,
            ],
            'locations' => $locations,
            'privacy' => [
                'location_precision' => 'coarse',
                'raw_ip_collected' => false,
            ],
            'generated_at' => $now->toIso8601String(),
        ];
    }

    /** @param Collection<int, StorefrontEvent> $events */
    private function eventsSince(Collection $events, CarbonImmutable $from): Collection
    {
        return $events->filter(fn (StorefrontEvent $event): bool => $event->occurred_at !== null
            && $event->occurred_at->greaterThanOrEqualTo($from->utc()));
    }

    /** @return Collection<int, array<string, mixed>> */
    private function reportRows(array $shopify, string $key): Collection
    {
        $rows = data_get($shopify, "reports.{$key}.rows", []);

        return collect(is_array($rows) ? $rows : [])->filter(
            fn (mixed $row): bool => is_array($row),
        )->values();
    }

    private function reportAvailable(array $shopify, string $key): bool
    {
        return (bool) data_get($shopify, "reports.{$key}.available", false);
    }

    private function reportError(array $shopify, string $key): string
    {
        $message = data_get($shopify, "reports.{$key}.error");

        return is_string($message) && $message !== '' ? $message : 'Shopify 实时统计暂时不可用。';
    }

    /** @param Collection<int, array<string, mixed>> $rows */
    private function reportTotal(Collection $rows, string $metric): float
    {
        $totalsKey = "{$metric}__totals";
        $totals = $rows->first(fn (array $row): bool => array_key_exists($totalsKey, $row));

        return round((float) (is_array($totals) ? ($totals[$totalsKey] ?? 0) : $rows->sum(
            fn (array $row): float => (float) ($row[$metric] ?? 0),
        )), 2);
    }

    /** @param Collection<int, array<string, mixed>> $rows @return list<float> */
    private function reportTrend(Collection $rows, string $metric): array
    {
        return $rows->filter(fn (array $row): bool => filled($row['hour'] ?? null))
            ->map(fn (array $row): float => round((float) ($row[$metric] ?? 0), 2))
            ->values()->all();
    }

    /** @return array{value: float|int, available: true, message: string|null, trend: list<float>} */
    private function availableMetric(float|int $value, array $trend = [], ?string $message = null): array
    {
        return ['value' => $value, 'available' => true, 'message' => $message, 'trend' => $trend];
    }

    /** @return array{value: int, available: false, message: string, trend: list<float>, classification: string} */
    private function unavailableMetric(string $message, string $classification = 'public_integration_required'): array
    {
        return ['value' => 0, 'available' => false, 'message' => $message, 'trend' => [], 'classification' => $classification];
    }

    /** @return array{available: false, message: string, classification: string, items: list<array{label: string, value: float|int}>} */
    private function unavailableInsight(string $message, string $classification): array
    {
        return ['available' => false, 'message' => $message, 'classification' => $classification, 'items' => []];
    }

    /** @param list<array{label: string, value: float|int}> $items */
    private function availableInsight(array $items, string $classification): array
    {
        return ['available' => true, 'message' => '', 'classification' => $classification, 'items' => $items];
    }

    /** @param Collection<int, StorefrontEvent> $events */
    private function locationInsight(array $shopify, Collection $events): array
    {
        if ($this->reportAvailable($shopify, 'locations')) {
            $items = $this->reportRows($shopify, 'locations')->map(function (array $row): array {
                $label = implode(' · ', array_filter([
                    $row['session_country'] ?? null,
                    $row['session_region'] ?? null,
                ]));

                return ['label' => $label ?: '未知地点', 'value' => (int) round((float) ($row['sessions'] ?? 0))];
            })->filter(fn (array $item): bool => $item['value'] > 0)->take(30)->values()->all();

            return $this->availableInsight($items, 'shopifyql');
        }

        $locations = $this->locations($events);
        if ($locations !== []) {
            return $this->availableInsight(collect($locations)->map(fn (array $location): array => [
                'label' => $location['label'],
                'value' => $location['visitors'],
            ])->all(), 'web_pixel');
        }

        return $this->unavailableInsight($this->reportError($shopify, 'locations'), 'shopifyql');
    }

    /** @param Collection<int, StorefrontEvent> $events */
    private function sourceInsight(array $shopify, Collection $events): array
    {
        if ($this->reportAvailable($shopify, 'sources')) {
            $items = $this->reportRows($shopify, 'sources')->map(function (array $row): array {
                $label = $row['referrer_name'] ?? $row['referrer_source'] ?? null;

                return ['label' => filled($label) ? (string) $label : '直接访问', 'value' => (int) round((float) ($row['sessions'] ?? 0))];
            })->filter(fn (array $item): bool => $item['value'] > 0)->take(8)->values()->all();

            return $this->availableInsight($items, 'shopifyql');
        }

        if ($events->isNotEmpty()) {
            $items = $events->groupBy(fn (StorefrontEvent $event): string => $event->referrer_host ?: '直接访问')
                ->map(fn (Collection $group, string $label): array => [
                    'label' => $label,
                    'value' => $group->pluck('session_id_hash')->unique()->count(),
                ])->sortByDesc('value')->take(8)->values()->all();

            return $this->availableInsight($items, 'web_pixel');
        }

        return $this->unavailableInsight($this->reportError($shopify, 'sources'), 'shopifyql');
    }

    private function customerInsight(array $shopify): array
    {
        if (! $this->reportAvailable($shopify, 'customers')) {
            return $this->unavailableInsight($this->reportError($shopify, 'customers'), 'shopifyql');
        }

        $items = $this->reportRows($shopify, 'customers')->map(function (array $row): array {
            $segment = (string) ($row['new_or_returning_customer'] ?? '');
            $label = strcasecmp($segment, 'Returning') === 0 ? '回头客' : (strcasecmp($segment, 'New') === 0 ? '新客户' : $segment);

            return ['label' => $label ?: '未分类客户', 'value' => (int) round((float) ($row['customers'] ?? 0))];
        })->values()->all();

        return $this->availableInsight($items, 'shopifyql');
    }

    private function productInsight(array $shopify): array
    {
        if (! $this->reportAvailable($shopify, 'products')) {
            return $this->unavailableInsight($this->reportError($shopify, 'products'), 'shopifyql');
        }

        $items = $this->reportRows($shopify, 'products')->map(function (array $row): array {
            $label = implode(' · ', array_filter([$row['product_title'] ?? null, $row['product_vendor'] ?? null]));

            return ['label' => $label ?: '未命名商品', 'value' => round((float) ($row['total_sales'] ?? 0), 2)];
        })->values()->all();

        return $this->availableInsight($items, 'shopifyql');
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

                return [
                    'id' => sha1($key),
                    'label' => implode(' · ', array_filter([$event->country_code, $event->region_code, $event->city])),
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
}
