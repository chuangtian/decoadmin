<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class AnalyticsQueryService
{
    /** @return array<string, mixed> */
    public function sales(Store $store, int $days = 30): array
    {
        $days = min(max($days, 7), 90);
        $timezone = $store->timezone ?: 'UTC';
        $end = CarbonImmutable::now($timezone)->endOfDay()->utc();
        $start = $end->subDays($days - 1)->startOfDay();
        $orders = Order::query()->forOrganization($store->organization_id)->forStore($store);
        $rows = (clone $orders)
            ->whereBetween('created_at_shopify', [$start, $end])
            ->selectRaw('DATE(created_at_shopify) as day, COUNT(*) as orders_count, COALESCE(SUM(total_price), 0) as sales_total')
            ->groupByRaw('DATE(created_at_shopify)')
            ->orderBy('day')
            ->get()
            ->keyBy('day');

        return [
            'period' => ['days' => $days, 'from' => $start->toDateString(), 'to' => $end->toDateString()],
            'summary' => [
                'sales' => (string) (clone $orders)->whereBetween('created_at_shopify', [$start, $end])->sum('total_price'),
                'orders' => (clone $orders)->whereBetween('created_at_shopify', [$start, $end])->count(),
                'average_order_value' => $this->averageOrderValue($orders, $start, $end),
            ],
            'trend' => $this->trend($start, $days, $rows),
            'top_products' => $this->topProducts($store),
            'low_stock' => $this->lowStock($store),
        ];
    }

    /** @return array<string, mixed> */
    public function storeComparison(Organization $organization, User $user, int $days = 30): array
    {
        $days = min(max($days, 7), 90);
        $start = now()->subDays($days - 1)->startOfDay();
        $stores = $this->authorizedStores($organization, $user);
        $rows = Order::query()
            ->where('organization_id', $organization->id)
            ->whereIn('store_id', $stores->modelKeys())
            ->where('created_at_shopify', '>=', $start)
            ->selectRaw('store_id, COUNT(*) as orders_count, COALESCE(SUM(total_price), 0) as sales_total')
            ->groupBy('store_id')
            ->get()
            ->keyBy('store_id');

        return [
            'period' => ['days' => $days, 'from' => $start->toDateString(), 'to' => now()->toDateString()],
            'stores' => $stores->map(function (Store $store) use ($rows): array {
                $row = $rows->get($store->id);
                $orders = (int) ($row->orders_count ?? 0);
                $sales = (float) ($row->sales_total ?? 0);

                return [
                    'id' => $store->id,
                    'name' => $store->name,
                    'currency' => $store->currency ?: 'USD',
                    'orders' => $orders,
                    'sales' => $sales,
                    'average_order_value' => $orders > 0 ? round($sales / $orders, 2) : 0,
                ];
            })->sortByDesc('sales')->values()->all(),
        ];
    }

    /** @return Collection<int, Store> */
    public function authorizedStores(Organization $organization, User $user): Collection
    {
        return $user->isSuperAdmin()
            ? $organization->stores()->where('status', 'active')->orderBy('name')->get()
            : $user->stores()->where('stores.organization_id', $organization->id)->where('stores.status', 'active')->orderBy('stores.name')->get();
    }

    private function averageOrderValue(Builder $orders, CarbonImmutable $start, CarbonImmutable $end): string
    {
        $period = (clone $orders)->whereBetween('created_at_shopify', [$start, $end]);
        $count = (clone $period)->count();

        return $count > 0 ? number_format((float) (clone $period)->sum('total_price') / $count, 2, '.', '') : '0.00';
    }

    /** @param Collection<string, object> $rows */
    private function trend(CarbonImmutable $start, int $days, Collection $rows): array
    {
        return collect(range(0, $days - 1))->map(function (int $offset) use ($start, $rows): array {
            $date = $start->addDays($offset);
            $row = $rows->get($date->format('Y-m-d'));

            return [
                'date' => $date->toDateString(),
                'label' => $date->format('m/d'),
                'sales' => (float) ($row->sales_total ?? 0),
                'orders' => (int) ($row->orders_count ?? 0),
            ];
        })->all();
    }

    private function topProducts(Store $store): array
    {
        return Order::query()
            ->forOrganization($store->organization_id)
            ->forStore($store)
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->where('orders.created_at_shopify', '>=', now()->subDays(30))
            ->selectRaw('COALESCE(order_items.product_id, 0) as product_key, order_items.title, SUM(order_items.quantity) as units, SUM(order_items.quantity * order_items.price) as revenue')
            ->groupBy('product_key', 'order_items.title')
            ->orderByDesc('units')
            ->limit(8)
            ->get()
            ->map(fn (object $row): array => [
                'product_id' => (int) $row->product_key ?: null,
                'title' => $row->title,
                'units' => (int) $row->units,
                'revenue' => (float) $row->revenue,
            ])->all();
    }

    private function lowStock(Store $store): array
    {
        return InventoryItem::query()
            ->where('inventory_items.organization_id', $store->organization_id)
            ->where('inventory_items.store_id', $store->id)
            ->leftJoin('inventory_levels', 'inventory_levels.inventory_item_id', '=', 'inventory_items.id')
            ->selectRaw('inventory_items.id, inventory_items.sku, COALESCE(SUM(inventory_levels.available), 0) as available')
            ->groupBy('inventory_items.id', 'inventory_items.sku')
            ->havingRaw('COALESCE(SUM(inventory_levels.available), 0) <= 10')
            ->orderBy('available')
            ->limit(8)
            ->get()
            ->map(fn (object $row): array => ['id' => (int) $row->id, 'sku' => $row->sku ?: '未设置 SKU', 'available' => (int) $row->available])
            ->all();
    }
}
