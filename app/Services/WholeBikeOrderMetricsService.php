<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

class WholeBikeOrderMetricsService
{
    /** @var list<string> */
    private const PRODUCT_HANDLES = [
        'macfox-x1',
        'macfox-x7',
        'x1s-x-bs-zay',
        'macfox-x2',
        'macfox-m16-ebike',
    ];

    /**
     * @param  array<string, mixed>  $period
     * @param  array<string, mixed>|null  $comparisonPeriod
     * @return array<string, mixed>
     */
    public function forStore(Store $store, array $period, ?array $comparisonPeriod): array
    {
        $productIds = Product::query()
            ->forOrganization($store->organization_id)
            ->forStore($store)
            ->whereIn('handle', self::PRODUCT_HANDLES)
            ->pluck('id')
            ->unique()
            ->values();

        if ($productIds->count() !== count(self::PRODUCT_HANDLES)) {
            return $this->unavailable($productIds->count());
        }

        $currentRange = $this->range($store, $period);
        $comparisonRange = $comparisonPeriod === null
            ? null
            : $this->range($store, [...$period, ...$comparisonPeriod]);

        if ($currentRange === null || ($comparisonPeriod !== null && $comparisonRange === null)) {
            return $this->unavailable($productIds->count());
        }

        return [
            'available' => true,
            'product_handles' => self::PRODUCT_HANDLES,
            'product_count' => $productIds->count(),
            'current' => $this->metrics($store, $currentRange, $period, $productIds->all()),
            'comparison' => $comparisonRange === null
                ? null
                : $this->metrics($store, $comparisonRange, $period, $productIds->all()),
        ];
    }

    /** @return array<string, mixed> */
    private function unavailable(int $productCount): array
    {
        return [
            'available' => false,
            'product_handles' => self::PRODUCT_HANDLES,
            'product_count' => $productCount,
            'current' => null,
            'comparison' => null,
        ];
    }

    /**
     * @param  array{start: CarbonImmutable, end: CarbonImmutable, local_start: CarbonImmutable, local_end: CarbonImmutable, timezone: string}  $range
     * @param  array<string, mixed>  $filters
     * @param  list<int>  $productIds
     * @return array<string, mixed>
     */
    private function metrics(Store $store, array $range, array $filters, array $productIds): array
    {
        $orders = $this->orders($store, $range, $filters, $productIds);
        $summary = (clone $orders)
            ->selectRaw('COUNT(*) AS orders_count, COALESCE(SUM(net_sales), 0) AS net_sales')
            ->first();
        $orderCount = (int) ($summary?->orders_count ?? 0);
        $netSales = round((float) ($summary?->net_sales ?? 0), 2);
        $trend = [];

        for ($date = $range['local_start']; $date->lte($range['local_end']); $date = $date->addDay()) {
            $trend[$date->toDateString()] = [
                'date' => $date->toDateString(),
                'orders' => 0,
                'net_sales' => 0.0,
                'average_order_value' => 0.0,
            ];
        }

        (clone $orders)
            ->select(['id', 'created_at_shopify', 'net_sales'])
            ->orderBy('id')
            ->lazyById(1000)
            ->each(function (Order $order) use (&$trend, $range): void {
                $date = $order->created_at_shopify?->timezone($range['timezone'])->toDateString();
                if ($date === null || ! isset($trend[$date])) {
                    return;
                }

                $trend[$date]['orders']++;
                $trend[$date]['net_sales'] += (float) $order->net_sales;
            });

        foreach ($trend as &$row) {
            $row['net_sales'] = round($row['net_sales'], 2);
            $row['average_order_value'] = $row['orders'] > 0
                ? round($row['net_sales'] / $row['orders'], 2)
                : 0.0;
        }
        unset($row);

        return [
            'orders' => $orderCount,
            'net_sales' => $netSales,
            'average_order_value' => $orderCount > 0 ? round($netSales / $orderCount, 2) : 0.0,
            'trend' => array_values($trend),
        ];
    }

    /**
     * @param  array{start: CarbonImmutable, end: CarbonImmutable}  $range
     * @param  array<string, mixed>  $filters
     * @param  list<int>  $productIds
     */
    private function orders(Store $store, array $range, array $filters, array $productIds): Builder
    {
        return Order::query()
            ->forOrganization($store->organization_id)
            ->forStore($store)
            ->whereBetween('created_at_shopify', [$range['start'], $range['end']])
            ->when(! filter_var($filters['include_test'] ?? false, FILTER_VALIDATE_BOOL), fn (Builder $query) => $query->where('is_test', false))
            ->when(! filter_var($filters['include_cancelled'] ?? true, FILTER_VALIDATE_BOOL), fn (Builder $query) => $query->whereNull('cancelled_at'))
            ->whereHas('items', fn (Builder $query) => $query->whereIn('product_id', $productIds));
    }

    /**
     * @param  array<string, mixed>  $period
     * @return array{start: CarbonImmutable, end: CarbonImmutable, local_start: CarbonImmutable, local_end: CarbonImmutable, timezone: string}|null
     */
    private function range(Store $store, array $period): ?array
    {
        try {
            $timezone = $store->timezone ?: 'UTC';
            $localStart = CarbonImmutable::createFromFormat('!Y-m-d', (string) $period['from'], $timezone)->startOfDay();
            $localEnd = CarbonImmutable::createFromFormat('!Y-m-d', (string) $period['to'], $timezone)->endOfDay();

            return [
                'start' => $localStart->utc(),
                'end' => $localEnd->utc(),
                'local_start' => $localStart,
                'local_end' => $localEnd,
                'timezone' => $timezone,
            ];
        } catch (Throwable) {
            return null;
        }
    }
}
