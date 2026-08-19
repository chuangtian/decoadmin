<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class AnalyticsQueryService
{
    public function __construct(private AnalyticsCacheVersionService $cacheVersion) {}

    /** @param int|array<string, mixed> $filters */
    public function sales(Store $store, int|array $filters = 30): array
    {
        $period = $this->period($store, $filters);
        $version = $this->cacheVersion->current((int) $store->getKey());
        // Keep the response schema version in the cache key so older dashboard
        // payloads cannot be reused after new metrics are introduced.
        $key = 'analytics:sales:schema-v2:'.$store->getKey().":v{$version}:".sha1(json_encode($period));

        return Cache::remember($key, now()->addMinutes(5), fn (): array => $this->buildSales($store, $period));
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

    /** @param array<string, mixed> $period */
    private function buildSales(Store $store, array $period): array
    {
        $orders = $this->orders($store, $period);
        $summary = $this->summary(clone $orders);
        $previousPeriod = $this->comparisonPeriod($period, 'previous');
        $previousOrders = $this->orders($store, $previousPeriod);
        $previous = $this->summary(clone $previousOrders);
        $year = $this->comparisonSummary($store, $period, 'year');
        $rankings = $this->productInsights($store, $period);
        $inventory = $this->inventoryInsights($store, $period);

        return [
            'period' => [
                'days' => $period['days'],
                'from' => $period['local_start']->toDateString(),
                'to' => $period['local_end']->toDateString(),
                'timezone' => $period['timezone'],
                'include_test' => $period['include_test'],
                'include_cancelled' => $period['include_cancelled'],
            ],
            'summary' => [...$summary, 'sales' => (string) $summary['net_sales']],
            'comparisons' => [
                'previous' => $this->compare($summary, $previous),
                'year_over_year' => $this->compare($summary, $year),
            ],
            'trend' => $this->trend($orders, $period),
            'comparison_trend' => [
                'previous' => $this->trend($previousOrders, $previousPeriod),
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
        foreach (range(0, $period['days'] - 1) as $offset) {
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
                $daily = $period['days'] > 0 ? $sold / $period['days'] : 0;
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
        return $this->summary($this->orders($store, $this->comparisonPeriod($period, $type)));
    }

    /** @param array<string, mixed> $period */
    private function comparisonPeriod(array $period, string $type): array
    {
        $localEnd = $type === 'year' ? $period['local_end']->subYear() : $period['local_start']->subDay()->endOfDay();
        $localStart = $type === 'year' ? $period['local_start']->subYear() : $localEnd->subDays($period['days'] - 1)->startOfDay();

        return [...$period, 'local_start' => $localStart, 'local_end' => $localEnd, 'start' => $localStart->utc(), 'end' => $localEnd->utc()];
    }

    /** @param int|array<string, mixed> $filters */
    private function period(Store $store, int|array $filters): array
    {
        $values = is_int($filters) ? ['days' => $filters] : $filters;
        $timezone = $store->timezone ?: 'UTC';
        $days = min(max((int) ($values['days'] ?? 30), 1), 366);
        $now = CarbonImmutable::now($timezone);
        $localEnd = $now->endOfDay();
        $localStart = $localEnd->subDays($days - 1)->startOfDay();

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
            'days' => $days, 'timezone' => $timezone, 'local_start' => $localStart, 'local_end' => $localEnd,
            'start' => $localStart->utc(), 'end' => $localEnd->utc(),
            'include_test' => filter_var($values['include_test'] ?? false, FILTER_VALIDATE_BOOL),
            'include_cancelled' => filter_var($values['include_cancelled'] ?? false, FILTER_VALIDATE_BOOL),
        ];
    }
}
