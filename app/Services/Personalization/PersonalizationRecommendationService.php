<?php

namespace App\Services\Personalization;

use App\Enums\PersonalizationAlgorithm;
use App\Enums\PersonalizationComponentStatus;
use App\Enums\PersonalizationProductOverrideType;
use App\Enums\PersonalizationRuleType;
use App\Exceptions\PersonalizationException;
use App\Models\PersonalizationRecommendationComponent;
use App\Models\PersonalizationRecommendationStrategy;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PersonalizationRecommendationService
{
    public function __construct(
        private PersonalizationCatalogService $catalog,
        private PersonalizationOrderSignalService $orderSignals,
        private PersonalizationShopGuard $shopGuard,
    ) {}

    /**
     * @param  array{
     *   seed_product_id?: int|string|null,
     *   cart_product_ids?: list<int|string>,
     *   recently_viewed_product_ids?: list<int|string>,
     *   purchased_product_ids?: list<int|string>
     * }  $context
     * @return array<string, mixed>
     */
    public function forComponent(
        Store $store,
        PersonalizationRecommendationComponent $component,
        array $context = [],
        bool $preview = false,
    ): array {
        $this->assertStore($store);
        if ((int) $component->organization_id !== (int) $store->organization_id
            || (int) $component->store_id !== (int) $store->id) {
            throw new PersonalizationException('COMPONENT_NOT_FOUND', '找不到该推荐组件。', 404);
        }
        $component->loadMissing(['strategy.rules', 'strategy.productOverrides', 'style']);
        $strategy = $component->strategy;
        if (! $strategy) {
            throw new PersonalizationException('STRATEGY_NOT_FOUND', '推荐组件缺少有效策略。', 409);
        }
        if (! $preview
            && ($component->status !== PersonalizationComponentStatus::Active || ! $strategy->enabled)) {
            throw new PersonalizationException('COMPONENT_NOT_ACTIVE', '推荐组件当前未启用。', 409);
        }

        return [
            'component' => [
                'uuid' => $component->uuid,
                'name' => $component->name,
                'placement' => $component->placement->value,
                'heading' => $component->heading,
                'button_label' => $component->button_label,
                'style' => $this->stylePayload($component),
            ],
            ...$this->recommend($store, $strategy, $context),
        ];
    }

    /**
     * @param  array{
     *   seed_product_id?: int|string|null,
     *   cart_product_ids?: list<int|string>,
     *   recently_viewed_product_ids?: list<int|string>,
     *   purchased_product_ids?: list<int|string>
     * }  $context
     * @return array{strategy: array<string, mixed>, context: array<string, mixed>, items: list<array<string, mixed>>}
     */
    public function recommend(
        Store $store,
        PersonalizationRecommendationStrategy $strategy,
        array $context = [],
    ): array {
        $this->assertStore($store);
        if ((int) $strategy->organization_id !== (int) $store->organization_id
            || (int) $strategy->store_id !== (int) $store->id) {
            throw new PersonalizationException('STRATEGY_NOT_FOUND', '找不到该推荐策略。', 404);
        }
        $strategy->loadMissing(['rules', 'productOverrides']);
        $normalizedContext = $this->context($context);
        $ranked = $this->algorithmProductIds($store, $strategy, $normalizedContext);
        $pinned = $strategy->productOverrides
            ->where('type', PersonalizationProductOverrideType::Pinned)
            ->sortBy('position')
            ->pluck('shopify_product_id')
            ->map(fn ($id): string => (string) $id)
            ->values()
            ->all();
        $excluded = $strategy->productOverrides
            ->where('type', PersonalizationProductOverrideType::Excluded)
            ->pluck('shopify_product_id')
            ->map(fn ($id): string => (string) $id)
            ->values()
            ->all();
        $excludeCart = $this->booleanRule($strategy, PersonalizationRuleType::ExcludeCartProducts, true);
        $excludePurchased = $this->booleanRule($strategy, PersonalizationRuleType::ExcludePurchasedProducts, false);
        $excluded = array_values(array_unique([
            ...$excluded,
            ...($excludeCart ? $normalizedContext['cart_product_ids'] : []),
            ...($excludePurchased ? $normalizedContext['purchased_product_ids'] : []),
            ...($normalizedContext['seed_product_id'] === null ? [] : [$normalizedContext['seed_product_id']]),
        ]));

        $orderedIds = array_values(array_unique([
            ...$pinned,
            ...array_keys($ranked),
        ]));
        $orderedIds = array_values(array_diff($orderedIds, $excluded));
        $filters = $this->catalogFilters($strategy, $orderedIds, $excluded);
        $candidates = $orderedIds === []
            ? collect()
            : $this->catalog->candidates($store, $filters);
        $candidates = $this->applyPostFilters($store, $strategy, $candidates);
        $byId = $candidates->keyBy(fn (array $product): string => $product['shopify_product_id']);

        $items = [];
        foreach ($orderedIds as $shopifyProductId) {
            $product = $byId->get($shopifyProductId);
            if (! is_array($product)) {
                continue;
            }
            $isPinned = in_array($shopifyProductId, $pinned, true);
            $items[] = [
                ...$product,
                'rank' => count($items) + 1,
                'reason_code' => $isPinned ? 'pinned' : $strategy->algorithm->value,
                'score' => $isPinned ? null : ($ranked[$shopifyProductId] ?? null),
            ];
            if (count($items) >= $strategy->item_limit) {
                break;
            }
        }

        return [
            'strategy' => [
                'uuid' => $strategy->uuid,
                'name' => $strategy->name,
                'algorithm' => $strategy->algorithm->value,
            ],
            'context' => [
                'seed_product_id' => $normalizedContext['seed_product_id'],
                'cart_product_count' => count($normalizedContext['cart_product_ids']),
                'recently_viewed_product_count' => count($normalizedContext['recently_viewed_product_ids']),
            ],
            'items' => $items,
        ];
    }

    /**
     * @param  array{seed_product_id: ?string, cart_product_ids: list<string>, purchased_product_ids: list<string>, recently_viewed_product_ids: list<string>}  $context
     * @return array<string, int|float>
     */
    private function algorithmProductIds(
        Store $store,
        PersonalizationRecommendationStrategy $strategy,
        array $context,
    ): array {
        return match ($strategy->algorithm) {
            PersonalizationAlgorithm::Manual => $this->manualIds($store, $strategy),
            PersonalizationAlgorithm::BestSeller => $this->bestSellerIds($store),
            PersonalizationAlgorithm::NewArrivals => $this->newArrivalIds($store),
            PersonalizationAlgorithm::FrequentlyBoughtTogether => $this->frequentlyBoughtTogetherIds($store, $context),
            PersonalizationAlgorithm::RecentlyViewed => $this->recentlyViewedIds($context),
            PersonalizationAlgorithm::SimilarProducts => $this->similarProductIds($store, $context['seed_product_id']),
        };
    }

    /** @return array<string, int> */
    private function manualIds(Store $store, PersonalizationRecommendationStrategy $strategy): array
    {
        $result = [];
        foreach ($strategy->productOverrides
            ->where('type', PersonalizationProductOverrideType::Manual)
            ->sortBy('position') as $position => $override) {
            $result[(string) $override->shopify_product_id] = 1_000_000 - (int) $position;
        }
        $collectionIds = $strategy->rules
            ->where('enabled', true)
            ->where('type', PersonalizationRuleType::IncludeCollections)
            ->flatMap(fn ($rule) => $rule->value['collection_ids'] ?? [])
            ->map(fn ($id): string => (string) $id)
            ->unique()->values()->all();
        if ($collectionIds !== []) {
            $collectionProducts = DB::table('product_collection_memberships')
                ->join('product_collections', 'product_collections.id', '=', 'product_collection_memberships.product_collection_id')
                ->join('products', 'products.id', '=', 'product_collection_memberships.product_id')
                ->where('product_collection_memberships.organization_id', $store->organization_id)
                ->where('product_collection_memberships.store_id', $store->id)
                ->whereIn('product_collections.shopify_collection_id', $collectionIds)
                ->where('products.organization_id', $store->organization_id)
                ->where('products.store_id', $store->id)
                ->where('products.status', 'active')
                ->orderBy('product_collection_memberships.id')
                ->limit(500)
                ->pluck('products.shopify_product_id');
            foreach ($collectionProducts as $position => $productId) {
                $result[(string) $productId] ??= 500_000 - (int) $position;
            }
        }

        return $result;
    }

    /** @return array<string, int> */
    private function bestSellerIds(Store $store): array
    {
        $rows = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->where('orders.organization_id', $store->organization_id)
            ->where('orders.store_id', $store->id)
            ->where('orders.is_test', false)
            ->whereNull('orders.cancelled_at')
            ->where('orders.processed_at', '>=', now()->subDays(90))
            ->where('order_items.current_quantity', '>', 0)
            ->where('products.organization_id', $store->organization_id)
            ->where('products.store_id', $store->id)
            ->where('products.status', 'active')
            ->whereNotNull('products.published_at_shopify')
            ->groupBy('products.shopify_product_id')
            ->orderByDesc('units')
            ->orderByDesc('orders_count')
            ->orderBy('products.shopify_product_id')
            ->limit(100)
            ->get([
                'products.shopify_product_id',
                DB::raw('SUM(order_items.current_quantity) as units'),
                DB::raw('COUNT(DISTINCT orders.id) as orders_count'),
            ]);

        return $rows->mapWithKeys(fn ($row): array => [
            (string) $row->shopify_product_id => (int) $row->units * 1_000 + (int) $row->orders_count,
        ])->all();
    }

    /** @return array<string, int> */
    private function newArrivalIds(Store $store): array
    {
        return Product::query()
            ->where('organization_id', $store->organization_id)
            ->where('store_id', $store->id)
            ->where('status', 'active')
            ->whereNotNull('published_at_shopify')
            ->orderByDesc('published_at_shopify')
            ->orderByDesc('id')
            ->limit(100)
            ->get(['shopify_product_id', 'published_at_shopify'])
            ->mapWithKeys(fn (Product $product): array => [
                (string) $product->shopify_product_id => (int) $product->published_at_shopify->timestamp,
            ])->all();
    }

    /**
     * @param  array{seed_product_id: ?string, cart_product_ids: list<string>, purchased_product_ids: list<string>, recently_viewed_product_ids: list<string>}  $context
     * @return array<string, int>
     */
    private function frequentlyBoughtTogetherIds(Store $store, array $context): array
    {
        $seeds = $context['cart_product_ids'];
        if ($seeds === [] && $context['seed_product_id'] !== null) {
            $seeds = [$context['seed_product_id']];
        }
        $seeds = array_slice($seeds, 0, 10);
        $scores = [];
        foreach ($seeds as $seed) {
            foreach ($this->orderSignals->frequentlyBoughtTogether($store, $seed, 50) as $signal) {
                $id = $signal['shopify_product_id'];
                $scores[$id] = ($scores[$id] ?? 0)
                    + $signal['support_orders'] * 1_000
                    + $signal['units'];
            }
        }
        foreach ($seeds as $seed) {
            unset($scores[$seed]);
        }
        arsort($scores, SORT_NUMERIC);

        return array_slice($scores, 0, 100, true);
    }

    /**
     * @param  array{seed_product_id: ?string, cart_product_ids: list<string>, recently_viewed_product_ids: list<string>}  $context
     * @return array<string, int>
     */
    private function recentlyViewedIds(array $context): array
    {
        $result = [];
        $total = count($context['recently_viewed_product_ids']);
        foreach ($context['recently_viewed_product_ids'] as $position => $id) {
            $result[$id] = $total - $position;
        }

        return $result;
    }

    /** @return array<string, float> */
    private function similarProductIds(Store $store, ?string $seedProductId): array
    {
        if ($seedProductId === null) {
            return [];
        }
        $seed = Product::query()
            ->where('organization_id', $store->organization_id)
            ->where('store_id', $store->id)
            ->where('shopify_product_id', $seedProductId)
            ->with(['collections:id,shopify_collection_id', 'variants:id,product_id,price'])
            ->first();
        if (! $seed) {
            return [];
        }
        $seedTags = array_values($seed->tags ?? []);
        $seedCollections = $seed->collections->pluck('shopify_collection_id')->map(fn ($id): string => (string) $id)->all();
        $seedPrice = (float) ($seed->variants->pluck('price')->filter()->sort(SORT_NUMERIC)->first() ?? 0);

        $scores = Product::query()
            ->where('organization_id', $store->organization_id)
            ->where('store_id', $store->id)
            ->where('status', 'active')
            ->whereNotNull('published_at_shopify')
            ->where('shopify_product_id', '!=', $seedProductId)
            ->with(['collections:id,shopify_collection_id', 'variants:id,product_id,price'])
            ->orderByDesc('published_at_shopify')
            ->limit(200)
            ->get()
            ->mapWithKeys(function (Product $candidate) use ($seed, $seedTags, $seedCollections, $seedPrice): array {
                $commonTags = count(array_intersect($seedTags, array_values($candidate->tags ?? [])));
                $candidateCollections = $candidate->collections->pluck('shopify_collection_id')->map(fn ($id): string => (string) $id)->all();
                $commonCollections = count(array_intersect($seedCollections, $candidateCollections));
                $score = min(5, $commonTags)
                    + min(3, $commonCollections) * 2
                    + ($seed->product_type && $seed->product_type === $candidate->product_type ? 4 : 0)
                    + ($seed->vendor && $seed->vendor === $candidate->vendor ? 3 : 0);
                $candidatePrice = (float) ($candidate->variants->pluck('price')->filter()->sort(SORT_NUMERIC)->first() ?? 0);
                if ($seedPrice > 0 && $candidatePrice > 0) {
                    $ratio = abs($candidatePrice - $seedPrice) / $seedPrice;
                    $score += $ratio <= 0.1 ? 2 : ($ratio <= 0.25 ? 1 : 0);
                }

                return $score > 0 ? [(string) $candidate->shopify_product_id => (float) $score] : [];
            })
            ->all();
        arsort($scores, SORT_NUMERIC);

        return array_slice($scores, 0, 100, true);
    }

    /**
     * @param  list<string>  $orderedIds
     * @param  list<string>  $excluded
     * @return array<string, mixed>
     */
    private function catalogFilters(
        PersonalizationRecommendationStrategy $strategy,
        array $orderedIds,
        array $excluded,
    ): array {
        $filters = [
            'product_ids' => $orderedIds,
            'exclude_product_ids' => $excluded,
            'in_stock_only' => true,
            'limit' => 100,
        ];
        foreach ($strategy->rules->where('enabled', true) as $rule) {
            $value = $rule->value;
            match ($rule->type) {
                PersonalizationRuleType::MinimumPrice => $filters['min_price'] = $value['amount'] ?? null,
                PersonalizationRuleType::MaximumPrice => $filters['max_price'] = $value['amount'] ?? null,
                PersonalizationRuleType::InStockOnly => $filters['in_stock_only'] = (bool) ($value['enabled'] ?? true),
                default => null,
            };
        }

        return $filters;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $candidates
     * @return Collection<int, array<string, mixed>>
     */
    private function applyPostFilters(
        Store $store,
        PersonalizationRecommendationStrategy $strategy,
        Collection $candidates,
    ): Collection {
        $includeTags = [];
        $excludeTags = [];
        $excludeCollections = [];
        $excludeVendors = [];
        $minimumInventory = null;
        foreach ($strategy->rules->where('enabled', true) as $rule) {
            if ($rule->type === PersonalizationRuleType::IncludeTags) {
                $includeTags = $rule->value['tags'] ?? [];
            }
            if ($rule->type === PersonalizationRuleType::ExcludeTags) {
                $excludeTags = $rule->value['tags'] ?? [];
            }
            if ($rule->type === PersonalizationRuleType::MinimumInventory) {
                $minimumInventory = (int) ($rule->value['quantity'] ?? 0);
            }
            if ($rule->type === PersonalizationRuleType::ExcludeCollections) {
                $excludeCollections = array_map('strval', $rule->value['collection_ids'] ?? []);
            }
            if ($rule->type === PersonalizationRuleType::ExcludeVendors) {
                $excludeVendors = array_map('strval', $rule->value['vendors'] ?? []);
            }
        }
        if ($includeTags !== []) {
            $candidates = $candidates->filter(fn (array $product): bool => array_intersect(
                $includeTags,
                array_values($product['tags'] ?? []),
            ) !== []);
        }
        if ($excludeTags !== []) {
            $candidates = $candidates->reject(fn (array $product): bool => array_intersect(
                $excludeTags,
                array_values($product['tags'] ?? []),
            ) !== []);
        }
        if ($excludeCollections !== []) {
            $candidates = $candidates->reject(fn (array $product): bool => collect($product['collections'] ?? [])
                ->pluck('shopify_collection_id')->map(fn ($id): string => (string) $id)
                ->intersect($excludeCollections)->isNotEmpty());
        }
        if ($excludeVendors !== []) {
            $candidates = $candidates->reject(fn (array $product): bool => in_array((string) ($product['vendor'] ?? ''), $excludeVendors, true));
        }
        if ($minimumInventory !== null) {
            $available = $this->inventoryByProduct($store, $candidates->pluck('id')->all());
            $candidates = $candidates->filter(fn (array $product): bool => ($available[$product['id']] ?? null) !== null
                && $available[$product['id']] >= $minimumInventory);
        }

        return $candidates->values();
    }

    /** @param list<int> $productIds @return array<int, int> */
    private function inventoryByProduct(Store $store, array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        return DB::table('product_variants')
            ->join('inventory_items', 'inventory_items.variant_id', '=', 'product_variants.id')
            ->join('inventory_levels', 'inventory_levels.inventory_item_id', '=', 'inventory_items.id')
            ->where('inventory_items.organization_id', $store->organization_id)
            ->where('inventory_items.store_id', $store->id)
            ->whereIn('product_variants.product_id', $productIds)
            ->groupBy('product_variants.product_id')
            ->selectRaw('product_variants.product_id, SUM(inventory_levels.available) as total_available')
            ->pluck('total_available', 'product_variants.product_id')
            ->map(fn ($quantity): int => (int) $quantity)
            ->all();
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{seed_product_id: ?string, cart_product_ids: list<string>, purchased_product_ids: list<string>, recently_viewed_product_ids: list<string>}
     */
    private function context(array $context): array
    {
        $allowed = ['seed_product_id', 'cart_product_ids', 'purchased_product_ids', 'recently_viewed_product_ids'];
        if (array_diff(array_keys($context), $allowed) !== []) {
            throw new PersonalizationException('INVALID_RECOMMENDATION_CONTEXT', '推荐上下文包含不支持的字段。');
        }

        return [
            'seed_product_id' => ($context['seed_product_id'] ?? null) === null
                ? null
                : $this->numericProductId($context['seed_product_id']),
            'cart_product_ids' => $this->productIds($context['cart_product_ids'] ?? [], 20),
            'purchased_product_ids' => $this->productIds($context['purchased_product_ids'] ?? [], 50),
            'recently_viewed_product_ids' => $this->productIds($context['recently_viewed_product_ids'] ?? [], 50),
        ];
    }

    private function booleanRule(
        PersonalizationRecommendationStrategy $strategy,
        PersonalizationRuleType $type,
        bool $default,
    ): bool {
        $rule = $strategy->rules->first(fn ($rule): bool => $rule->enabled && $rule->type === $type);

        return $rule ? (bool) ($rule->value['enabled'] ?? $default) : $default;
    }

    /** @return list<string> */
    private function productIds(mixed $values, int $maximum): array
    {
        if (! is_array($values) || count($values) > $maximum) {
            throw new PersonalizationException('INVALID_RECOMMENDATION_CONTEXT', '推荐上下文中的商品列表无效。');
        }

        return array_values(array_unique(array_map(fn ($value): string => $this->numericProductId($value), $values)));
    }

    private function numericProductId(mixed $value): string
    {
        $value = is_int($value) || is_string($value) ? trim((string) $value) : '';
        if (preg_match('#^gid://shopify/Product/(\d+)$#', $value, $matches) === 1) {
            return $matches[1];
        }
        if (preg_match('/^\d+$/', $value) !== 1) {
            throw new PersonalizationException('INVALID_RECOMMENDATION_CONTEXT', 'Shopify 商品 ID 格式无效。');
        }

        return $value;
    }

    private function assertStore(Store $store): void
    {
        $this->shopGuard->assertAllowed((string) $store->shopify_domain);
        if ($store->status !== 'active'
            || ! $store->organization
            || $store->organization->status !== 'active') {
            throw new PersonalizationException('STORE_NOT_CONNECTED', '店铺当前不可用于个性化推荐。', 409);
        }
    }

    /** @return array<string, mixed> */
    private function stylePayload(PersonalizationRecommendationComponent $component): array
    {
        $style = $component->style;

        return [
            'layout' => $style?->layout ?? 'carousel',
            'desktop_columns' => $style?->desktop_columns ?? 4,
            'mobile_columns' => $style?->mobile_columns ?? 2,
            'show_image' => $style?->show_image ?? true,
            'show_vendor' => $style?->show_vendor ?? false,
            'show_price' => $style?->show_price ?? true,
            'show_compare_at_price' => $style?->show_compare_at_price ?? true,
            'show_add_to_cart' => $style?->show_add_to_cart ?? true,
            'tokens' => $style?->tokens ?? [],
        ];
    }
}
