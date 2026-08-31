<?php

namespace App\Services\Personalization;

use App\Models\Store;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PersonalizationOrderSignalService
{
    /**
     * Return aggregate co-purchase signals without selecting customer or order identity fields.
     *
     * @return Collection<int, array{shopify_product_id: string, support_orders: int, units: int}>
     */
    public function frequentlyBoughtTogether(
        Store $store,
        int|string $shopifyProductId,
        int $limit = 12,
        int $lookbackDays = 90,
    ): Collection {
        $shopifyProductId = $this->numericId($shopifyProductId);
        $limit = min(100, max(1, $limit));
        $lookbackDays = min(365, max(1, $lookbackDays));
        $eligibleOrders = DB::table('order_items as seed')
            ->join('orders as source_orders', 'source_orders.id', '=', 'seed.order_id')
            ->where('source_orders.organization_id', $store->organization_id)
            ->where('source_orders.store_id', $store->getKey())
            ->where('source_orders.is_test', false)
            ->whereNull('source_orders.cancelled_at')
            ->where('source_orders.processed_at', '>=', now()->subDays($lookbackDays))
            ->where('seed.shopify_product_id', $shopifyProductId)
            ->where('seed.current_quantity', '>', 0)
            ->select('seed.order_id')
            ->distinct();

        return DB::query()
            ->fromSub($eligibleOrders, 'eligible_orders')
            ->join('order_items as companion', 'companion.order_id', '=', 'eligible_orders.order_id')
            ->join('products', function ($join) use ($store): void {
                $join->on('products.id', '=', 'companion.product_id')
                    ->where('products.organization_id', '=', $store->organization_id)
                    ->where('products.store_id', '=', $store->getKey());
            })
            ->where('companion.current_quantity', '>', 0)
            ->where('companion.shopify_product_id', '!=', $shopifyProductId)
            ->where('products.status', 'active')
            ->whereNotNull('products.published_at_shopify')
            ->whereExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('product_variants')
                ->whereColumn('product_variants.product_id', 'products.id')
                ->where('product_variants.available_for_sale', true))
            ->groupBy('products.shopify_product_id')
            ->orderByDesc('support_orders')
            ->orderByDesc('units')
            ->orderBy('products.shopify_product_id')
            ->limit($limit)
            ->get([
                'products.shopify_product_id',
                DB::raw('COUNT(DISTINCT eligible_orders.order_id) as support_orders'),
                DB::raw('SUM(companion.current_quantity) as units'),
            ])
            ->map(fn ($row): array => [
                'shopify_product_id' => (string) $row->shopify_product_id,
                'support_orders' => (int) $row->support_orders,
                'units' => (int) $row->units,
            ]);
    }

    private function numericId(int|string $value): string
    {
        $value = (string) $value;

        if (preg_match('/^[0-9]+$/', $value) !== 1) {
            throw new InvalidArgumentException('Shopify 商品 ID 格式无效。');
        }

        return $value;
    }
}
