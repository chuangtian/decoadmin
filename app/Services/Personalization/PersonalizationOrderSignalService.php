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

    /**
     * Return anonymous co-view signals aggregated by the Web Pixel session hash.
     * No customer, browser, or raw session identifiers are selected or returned.
     *
     * @return Collection<int, array{shopify_product_id: string, support_sessions: int, views: int}>
     */
    public function frequentlyViewedTogether(
        Store $store,
        int|string $shopifyProductId,
        int $limit = 12,
        int $lookbackDays = 30,
    ): Collection {
        $shopifyProductId = $this->numericId($shopifyProductId);
        $limit = min(100, max(1, $limit));
        $lookbackDays = min(90, max(1, $lookbackDays));
        $eligibleSessions = DB::table('personalization_event_products as seed_product')
            ->join('personalization_events as seed_event', 'seed_event.id', '=', 'seed_product.event_id')
            ->where('seed_event.organization_id', $store->organization_id)
            ->where('seed_event.store_id', $store->getKey())
            ->where('seed_event.event_name', PersonalizationEventIngestionService::PRODUCT_VIEWED)
            ->where('seed_event.occurred_at', '>=', now()->subDays($lookbackDays))
            ->where('seed_product.shopify_product_id', $shopifyProductId)
            ->select('seed_event.session_id_hash')
            ->distinct();

        return DB::query()
            ->fromSub($eligibleSessions, 'eligible_sessions')
            ->join('personalization_events as companion_event', function ($join) use ($store, $lookbackDays): void {
                $join->on('companion_event.session_id_hash', '=', 'eligible_sessions.session_id_hash')
                    ->where('companion_event.organization_id', '=', $store->organization_id)
                    ->where('companion_event.store_id', '=', $store->getKey())
                    ->where('companion_event.event_name', '=', PersonalizationEventIngestionService::PRODUCT_VIEWED)
                    ->where('companion_event.occurred_at', '>=', now()->subDays($lookbackDays));
            })
            ->join('personalization_event_products as companion_product', 'companion_product.event_id', '=', 'companion_event.id')
            ->join('products', function ($join) use ($store): void {
                $join->on('products.id', '=', 'companion_product.product_id')
                    ->where('products.organization_id', '=', $store->organization_id)
                    ->where('products.store_id', '=', $store->getKey());
            })
            ->where('companion_product.shopify_product_id', '!=', $shopifyProductId)
            ->where('products.status', 'active')
            ->whereNotNull('products.published_at_shopify')
            ->whereExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('product_variants')
                ->whereColumn('product_variants.product_id', 'products.id')
                ->where('product_variants.available_for_sale', true))
            ->groupBy('products.shopify_product_id')
            ->orderByDesc('support_sessions')
            ->orderByDesc('views')
            ->orderBy('products.shopify_product_id')
            ->limit($limit)
            ->get([
                'products.shopify_product_id',
                DB::raw('COUNT(DISTINCT companion_event.session_id_hash) as support_sessions'),
                DB::raw('COUNT(DISTINCT companion_event.id) as views'),
            ])
            ->map(fn ($row): array => [
                'shopify_product_id' => (string) $row->shopify_product_id,
                'support_sessions' => (int) $row->support_sessions,
                'views' => (int) $row->views,
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
