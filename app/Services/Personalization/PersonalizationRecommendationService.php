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
        $component->loadMissing(['strategy.rules', 'strategy.productOverrides', 'strategy.publishedVersion', 'strategyVersion', 'style']);
        $strategy = $component->strategy;
        if (! $strategy) {
            throw new PersonalizationException('STRATEGY_NOT_FOUND', '推荐组件缺少有效策略。', 409);
        }
        if (! $preview
            && ($component->status !== PersonalizationComponentStatus::Active || ! $strategy->enabled)) {
            throw new PersonalizationException('COMPONENT_NOT_ACTIVE', '推荐组件当前未启用。', 409);
        }

        $placement = $component->placement->value;
        $version = $component->strategyVersion ?: $strategy->publishedVersion;
        $result = $this->recommend($store, $strategy, [
            ...$context,
            'surface' => $placement,
            'placement' => $placement,
        ]);
        $result['strategy']['version_uuid'] = $version?->uuid;
        $result['strategy']['version_number'] = $version?->version_number;

        return [
            'component' => [
                'uuid' => $component->uuid,
                'name' => $component->name,
                'placement' => $component->placement->value,
                'heading' => $component->heading,
                'button_label' => $component->button_label,
                'style' => $this->stylePayload($component),
            ],
            ...$result,
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
        $strategy->loadMissing(['rules', 'productOverrides', 'publishedVersion']);
        $normalizedContext = $this->context($context);
        $selection = $this->ruleProductSelection($store, $strategy, $normalizedContext);
        $ranked = $selection['scores'];
        $pinned = $strategy->productOverrides
            ->where('type', PersonalizationProductOverrideType::Pinned)
            ->sortBy('position')
            ->pluck('shopify_product_id')
            ->map(fn ($id): string => (string) $id)
            ->values()
            ->all();
        $ordinaryExcluded = $strategy->productOverrides
            ->where('type', PersonalizationProductOverrideType::Excluded)
            ->pluck('shopify_product_id')
            ->map(fn ($id): string => (string) $id)
            ->values()
            ->all();
        $minimumQuantities = $strategy->productOverrides
            ->whereIn('type', [PersonalizationProductOverrideType::Manual, PersonalizationProductOverrideType::Pinned])
            ->sortBy('position')
            ->groupBy(fn ($override): string => (string) $override->shopify_product_id)
            ->map(fn (Collection $overrides): int => max(1, (int) ($overrides->firstWhere('type', PersonalizationProductOverrideType::Pinned)?->minimum_quantity
                ?? $overrides->first()?->minimum_quantity
                ?? 1)));
        foreach ($selection['minimum_quantities'] as $productId => $minimumQuantity) {
            $minimumQuantities->put((string) $productId, max(1, (int) $minimumQuantity));
        }
        foreach ($strategy->productOverrides
            ->where('type', PersonalizationProductOverrideType::Pinned) as $override) {
            $minimumQuantities->put((string) $override->shopify_product_id, max(1, (int) $override->minimum_quantity));
        }
        $excludeCart = $this->booleanRule($strategy, PersonalizationRuleType::ExcludeCartProducts, true);
        $excludePurchased = $this->booleanRule($strategy, PersonalizationRuleType::ExcludePurchasedProducts, true);
        $contextExcluded = array_values(array_unique([
            ...($excludeCart ? $normalizedContext['cart_product_ids'] : []),
            ...($excludePurchased ? $normalizedContext['purchased_product_ids'] : []),
            ...($normalizedContext['seed_product_id'] === null ? [] : [$normalizedContext['seed_product_id']]),
        ]));

        $debugOrderedIds = array_values(array_unique([...$pinned, ...array_keys($ranked)]));
        $pinnedIds = array_values(array_diff(array_unique($pinned), $contextExcluded));
        $normalIds = array_values(array_diff(array_keys($ranked), [
            ...$contextExcluded,
            ...$ordinaryExcluded,
            ...$pinnedIds,
        ]));
        $pinnedCandidates = $pinnedIds === [] ? collect() : $this->catalog->candidates($store, [
            'product_ids' => $pinnedIds,
            'exclude_product_ids' => $contextExcluded,
            'in_stock_only' => true,
            'limit' => 100,
        ]);
        $normalCandidates = $normalIds === [] ? collect() : $this->catalog->candidates(
            $store,
            $this->catalogFilters($strategy, $normalIds, [...$contextExcluded, ...$ordinaryExcluded]),
        );
        $normalCandidates = $this->applyPostFilters($store, $strategy, $normalCandidates);
        $candidates = $pinnedCandidates->concat($normalCandidates)->unique('shopify_product_id')->values();
        $byId = $candidates->keyBy(fn (array $product): string => $product['shopify_product_id']);
        $orderedIds = array_values(array_unique([...$pinnedIds, ...$normalIds]));

        $items = [];
        foreach ($orderedIds as $shopifyProductId) {
            $product = $byId->get($shopifyProductId);
            if (! is_array($product)) {
                continue;
            }
            $isPinned = in_array($shopifyProductId, $pinned, true);
            $preferredVariantGid = $selection['variant_gids'][$shopifyProductId]
                ?? $strategy->productOverrides
                    ->first(fn ($override): bool => (string) $override->shopify_product_id === $shopifyProductId
                        && filled($override->shopify_variant_gid))?->shopify_variant_gid;
            $preferredVariantId = is_string($preferredVariantGid)
                && preg_match('#^gid://shopify/ProductVariant/(\d+)$#', $preferredVariantGid, $matches) === 1
                ? $matches[1]
                : null;
            $selectedVariant = collect($product['variants'] ?? [])->first(
                fn (array $variant): bool => $preferredVariantId !== null
                    && (string) ($variant['shopify_variant_id'] ?? '') === $preferredVariantId
                    && ($variant['available_for_sale'] ?? false) === true,
            ) ?? collect($product['variants'] ?? [])->first(
                fn (array $variant): bool => ($variant['available_for_sale'] ?? false) === true,
            );
            $items[] = [
                ...$product,
                'rank' => count($items) + 1,
                'reason_code' => $isPinned
                    ? 'pinned'
                    : ($selection['reason_codes'][$shopifyProductId] ?? $strategy->algorithm->value),
                'score' => $isPinned ? null : ($ranked[$shopifyProductId] ?? null),
                'minimum_purchase_quantity' => $minimumQuantities->get($shopifyProductId, 1),
                'selected_variant_gid' => is_array($selectedVariant)
                    ? 'gid://shopify/ProductVariant/'.($selectedVariant['shopify_variant_id'] ?? '')
                    : null,
                'status' => 'available',
                'rule_id' => $selection['rule_ids'][$shopifyProductId] ?? null,
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
                'mode' => data_get($strategy->settings, 'recommendation_rule.mode', 'preset'),
                'version_uuid' => $strategy->publishedVersion?->uuid,
                'version_number' => $strategy->publishedVersion?->version_number,
            ],
            'context' => [
                'seed_product_id' => $normalizedContext['seed_product_id'],
                'cart_product_count' => count($normalizedContext['cart_product_ids']),
                'recently_viewed_product_count' => count($normalizedContext['recently_viewed_product_ids']),
                'surface' => $normalizedContext['surface'],
                'placement' => $normalizedContext['placement'],
                'market' => $normalizedContext['market'],
                'currency' => $normalizedContext['currency'],
                'language' => $normalizedContext['language'],
            ],
            'items' => $items,
            'discount' => $this->discountPayload($strategy),
            'debug' => [
                'diagnostics' => $selection['diagnostics'],
                'excluded' => $this->excludedDiagnostics(
                    $debugOrderedIds,
                    $items,
                    $contextExcluded,
                    $ordinaryExcluded,
                ),
            ],
        ];
    }

    /**
     * @param  array{seed_product_id: ?string, cart_product_ids: list<string>, purchased_product_ids: list<string>, recently_viewed_product_ids: list<string>}  $context
     * @return array{scores: array<string, int|float>, minimum_quantities: array<string, int>, reason_codes: array<string, string>, variant_gids: array<string, string>, rule_ids: array<string, string>, diagnostics: list<array<string, mixed>>}
     */
    private function ruleProductSelection(
        Store $store,
        PersonalizationRecommendationStrategy $strategy,
        array $context,
    ): array {
        $settings = is_array($strategy->settings) ? $strategy->settings : [];
        $recommendationRule = is_array($settings['recommendation_rule'] ?? null)
            ? $settings['recommendation_rule']
            : null;
        if (($recommendationRule['mode'] ?? null) !== 'custom') {
            return [
                'scores' => $this->algorithmProductIds($store, $strategy, $context),
                'minimum_quantities' => [],
                'reason_codes' => [],
                'variant_gids' => [],
                'rule_ids' => [],
                'diagnostics' => [],
            ];
        }

        return $this->customRuleProductSelection($store, $strategy, $context, $recommendationRule['custom'] ?? []);
    }

    /**
     * @param  array{seed_product_id: ?string, cart_product_ids: list<string>, purchased_product_ids: list<string>, recently_viewed_product_ids: list<string>}  $context
     * @param  array<string, mixed>  $custom
     * @return array{scores: array<string, int>, minimum_quantities: array<string, int>, reason_codes: array<string, string>, variant_gids: array<string, string>, rule_ids: array<string, string>, diagnostics: list<array<string, mixed>>}
     */
    private function customRuleProductSelection(
        Store $store,
        PersonalizationRecommendationStrategy $strategy,
        array $context,
        array $custom,
    ): array {
        $cartProducts = Product::query()
            ->where('organization_id', $store->organization_id)
            ->where('store_id', $store->id)
            ->whereIn('shopify_product_id', $context['cart_product_ids'])
            ->with('collections:id,shopify_collection_id')
            ->get();
        $cartFacts = [
            'cart_product_ids' => $context['cart_product_ids'],
            'cart_collection_ids' => $cartProducts->flatMap(fn (Product $product) => $product->collections)
                ->pluck('shopify_collection_id')->map(fn ($id): string => (string) $id)->unique()->values()->all(),
            'cart_tags' => $cartProducts->flatMap(fn (Product $product): array => array_values($product->tags ?? []))
                ->map(fn ($tag): string => (string) $tag)->unique()->values()->all(),
            'cart_vendors' => $cartProducts->pluck('vendor')->filter()->map(fn ($vendor): string => (string) $vendor)
                ->unique()->values()->all(),
        ];
        $actions = collect($custom['rules'] ?? [])->pluck('action')
            ->push($custom['fallback']['action'] ?? [])
            ->filter(fn ($action): bool => is_array($action));
        $actionProductIds = $actions
            ->flatMap(fn (array $action): array => array_column($action['products'] ?? [], 'shopify_product_id'))
            ->map(fn ($id): string => (string) $id)->unique()->values()->all();
        $actionProducts = Product::query()
            ->where('organization_id', $store->organization_id)
            ->where('store_id', $store->id)
            ->whereIn('shopify_product_id', $actionProductIds)
            ->with('collections:id,shopify_collection_id')
            ->get()->keyBy(fn (Product $product): string => (string) $product->shopify_product_id);

        $ordered = [];
        $minimumQuantities = [];
        $variantGids = [];
        $reasonCodes = [];
        $ruleIds = [];
        $diagnostics = [];
        $rules = collect($custom['rules'] ?? [])->sortBy('priority')->values();
        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                continue;
            }
            $referenceErrors = $this->customRuleReferenceErrors($store, $rule);
            if ($referenceErrors !== []) {
                $diagnostics[] = [
                    'code' => 'custom_rule_configuration_error',
                    'rule_id' => $rule['id'] ?? null,
                    'details' => $referenceErrors,
                ];

                continue;
            }
            if (! $context['cart_context_available']
                && collect($rule['conditions'] ?? [])->contains(
                    fn ($condition): bool => is_array($condition) && str_starts_with((string) ($condition['field'] ?? ''), 'cart_'),
                )) {
                $diagnostics[] = [
                    'code' => 'missing_context',
                    'rule_id' => $rule['id'] ?? null,
                    'surface' => $context['surface'],
                    'details' => ['cart'],
                ];

                continue;
            }
            if (! $this->customRuleMatches($rule, $cartFacts)) {
                continue;
            }
            $this->appendCustomAction(
                $rule['action'] ?? [],
                $actionProducts,
                $ordered,
                $minimumQuantities,
                $variantGids,
                $reasonCodes,
                $ruleIds,
                'custom_rule',
                (string) ($rule['id'] ?? ''),
            );
            if ((bool) ($rule['exit_on_match'] ?? false)) {
                break;
            }
        }
        if ((bool) ($custom['fallback']['enabled'] ?? false) && count($ordered) < $strategy->item_limit) {
            $fallbackAction = $custom['fallback']['action'] ?? [];
            $fallbackErrors = $this->customRuleReferenceErrors($store, ['conditions' => [], 'action' => $fallbackAction]);
            if ($fallbackErrors !== []) {
                $diagnostics[] = ['code' => 'custom_fallback_configuration_error', 'rule_id' => null, 'details' => $fallbackErrors];
            } else {
                $this->appendCustomAction(
                    $fallbackAction,
                    $actionProducts,
                    $ordered,
                    $minimumQuantities,
                    $variantGids,
                    $reasonCodes,
                    $ruleIds,
                    'custom_fallback',
                    '',
                );
            }
        }
        $ordered = array_slice($ordered, 0, $strategy->item_limit);
        $scores = [];
        foreach ($ordered as $position => $productId) {
            $scores[$productId] = 1_000_000 - $position;
        }

        return [
            'scores' => $scores,
            'minimum_quantities' => array_intersect_key($minimumQuantities, $scores),
            'reason_codes' => array_intersect_key($reasonCodes, $scores),
            'variant_gids' => array_intersect_key($variantGids, $scores),
            'rule_ids' => array_intersect_key($ruleIds, $scores),
            'diagnostics' => $diagnostics,
        ];
    }

    /** @param array<string, list<string>> $facts */
    private function customRuleMatches(array $rule, array $facts): bool
    {
        $conditions = is_array($rule['conditions'] ?? null) ? $rule['conditions'] : [];
        if ($conditions === []) {
            return false;
        }
        $matches = array_map(function ($condition) use ($facts): bool {
            if (! is_array($condition)) {
                return false;
            }
            $field = (string) ($condition['field'] ?? '');
            $values = array_values(array_map('strval', is_array($condition['values'] ?? null) ? $condition['values'] : []));
            if ($values === [] || ! isset($facts[$field])) {
                return false;
            }

            return $this->setMatches($facts[$field], (string) ($condition['operator'] ?? ''), $values);
        }, $conditions);

        return ($rule['match'] ?? 'all') === 'any'
            ? in_array(true, $matches, true)
            : ! in_array(false, $matches, true);
    }

    /**
     * @param  Collection<string, Product>  $products
     * @param  list<string>  $ordered
     * @param  array<string, int>  $minimumQuantities
     * @param  array<string, string>  $variantGids
     * @param  array<string, string>  $reasonCodes
     * @param  array<string, string>  $ruleIds
     */
    private function appendCustomAction(
        mixed $action,
        Collection $products,
        array &$ordered,
        array &$minimumQuantities,
        array &$variantGids,
        array &$reasonCodes,
        array &$ruleIds,
        string $reasonCode,
        string $ruleId,
    ): void {
        if (! is_array($action)) {
            return;
        }
        $filters = is_array($action['filters'] ?? null) ? $action['filters'] : [];
        foreach ($action['products'] ?? [] as $selection) {
            if (! is_array($selection)) {
                continue;
            }
            $productId = (string) ($selection['shopify_product_id'] ?? '');
            $product = $products->get($productId);
            if (! $product instanceof Product || ! $this->customActionFiltersMatch($product, $filters)) {
                continue;
            }
            if (! in_array($productId, $ordered, true)) {
                $ordered[] = $productId;
                $minimumQuantities[$productId] = max(1, (int) ($selection['minimum_quantity'] ?? 1));
                $variantGids[$productId] = (string) ($selection['variant_gid'] ?? '');
                $reasonCodes[$productId] = $reasonCode;
                if ($ruleId !== '') {
                    $ruleIds[$productId] = $ruleId;
                }
            }
        }
    }

    /** @param list<array<string, mixed>> $filters */
    private function customActionFiltersMatch(Product $product, array $filters): bool
    {
        foreach ($filters as $filter) {
            if (! is_array($filter)) {
                return false;
            }
            $values = array_values(array_map('strval', is_array($filter['values'] ?? null) ? $filter['values'] : []));
            if ($values === []) {
                return false;
            }
            $facts = match ($filter['field'] ?? '') {
                'product_tags' => array_values(array_map('strval', $product->tags ?? [])),
                'product_collections' => $product->collections->pluck('shopify_collection_id')
                    ->map(fn ($id): string => (string) $id)->values()->all(),
                'product_vendors' => filled($product->vendor) ? [(string) $product->vendor] : [],
                default => [],
            };
            if (! $this->setMatches($facts, (string) ($filter['operator'] ?? ''), $values)) {
                return false;
            }
        }

        return true;
    }

    /** @param list<string> $facts @param list<string> $values */
    private function setMatches(array $facts, string $operator, array $values): bool
    {
        $facts = array_values(array_unique(array_map('strval', $facts)));
        $values = array_values(array_unique(array_map('strval', $values)));

        return match ($operator) {
            'contains_any' => array_intersect($facts, $values) !== [],
            'contains_all' => array_diff($values, $facts) === [],
            'contains_none' => array_intersect($facts, $values) === [],
            default => false,
        };
    }

    /** @return list<string> */
    private function customRuleReferenceErrors(Store $store, array $rule): array
    {
        $productIds = collect($rule['conditions'] ?? [])
            ->filter(fn ($condition): bool => is_array($condition) && ($condition['field'] ?? null) === 'cart_product_ids')
            ->flatMap(fn (array $condition): array => $condition['values'] ?? [])
            ->merge(array_column($rule['action']['products'] ?? [], 'shopify_product_id'))
            ->map(fn ($id): string => (string) $id)->filter()->unique()->values();
        $collectionIds = collect($rule['conditions'] ?? [])
            ->filter(fn ($condition): bool => is_array($condition) && ($condition['field'] ?? null) === 'cart_collection_ids')
            ->flatMap(fn (array $condition): array => $condition['values'] ?? [])
            ->merge(collect($rule['action']['filters'] ?? [])
                ->filter(fn ($filter): bool => is_array($filter) && ($filter['field'] ?? null) === 'product_collections')
                ->flatMap(fn (array $filter): array => $filter['values'] ?? []))
            ->map(fn ($id): string => (string) $id)->filter()->unique()->values();
        $errors = [];
        if ($productIds->isNotEmpty() && Product::query()
            ->where('organization_id', $store->organization_id)
            ->where('store_id', $store->id)
            ->whereIn('shopify_product_id', $productIds->all())
            ->count() !== $productIds->count()) {
            $errors[] = 'invalid_product_reference';
        }
        if ($collectionIds->isNotEmpty() && DB::table('product_collections')
            ->where('organization_id', $store->organization_id)
            ->where('store_id', $store->id)
            ->whereIn('shopify_collection_id', $collectionIds->all())
            ->count() !== $collectionIds->count()) {
            $errors[] = 'invalid_collection_reference';
        }

        return $errors;
    }

    /** @return array<string, mixed>|null */
    private function discountPayload(PersonalizationRecommendationStrategy $strategy): ?array
    {
        $discount = is_array($strategy->settings) ? ($strategy->settings['discount'] ?? null) : null;
        if (! is_array($discount)
            || ($discount['enabled'] ?? false) !== true
            || ($discount['status'] ?? null) !== 'active'
            || ! filled($discount['reference'] ?? null)
            || ! filled($discount['validated_at'] ?? null)) {
            return null;
        }
        try {
            if (now()->diffInMinutes($discount['validated_at'], absolute: true) > 15) {
                return null;
            }
        } catch (\Throwable) {
            return null;
        }

        return [
            'reference' => (string) $discount['reference'],
            'title' => (string) ($discount['title'] ?? ''),
            'summary' => (string) ($discount['summary'] ?? ''),
            'code' => (string) ($discount['code'] ?? ''),
            'minimum_purchase_quantity_enforced' => true,
        ];
    }

    /**
     * @param  list<string>  $orderedIds
     * @param  list<array<string, mixed>>  $items
     * @param  list<string>  $contextExcluded
     * @param  list<string>  $ordinaryExcluded
     * @return list<array{shopify_product_id: string, reason: string}>
     */
    private function excludedDiagnostics(
        array $orderedIds,
        array $items,
        array $contextExcluded,
        array $ordinaryExcluded,
    ): array {
        $visible = array_column($items, 'shopify_product_id');

        return collect($orderedIds)
            ->reject(fn (string $id): bool => in_array($id, $visible, true))
            ->map(fn (string $id): array => [
                'shopify_product_id' => $id,
                'reason' => in_array($id, $contextExcluded, true)
                    ? 'context_excluded'
                    : (in_array($id, $ordinaryExcluded, true) ? 'excluded_by_strategy' : 'unavailable_or_filtered'),
            ])->values()->all();
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
        $excludePurchaseOptions = [];
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
            if ($rule->type === PersonalizationRuleType::ExcludePurchaseOptions) {
                $excludePurchaseOptions = array_map('strval', $rule->value['purchase_options'] ?? []);
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
        if ($excludePurchaseOptions !== []) {
            $candidates = $candidates->reject(function (array $product) use ($excludePurchaseOptions): bool {
                foreach ($product['variants'] ?? [] as $variant) {
                    foreach ($variant['selected_options'] ?? [] as $option) {
                        $name = trim((string) ($option['name'] ?? ''));
                        $value = trim((string) ($option['value'] ?? ''));
                        if ($name !== '' && $value !== '' && in_array("{$name}: {$value}", $excludePurchaseOptions, true)) {
                            return true;
                        }
                    }
                }

                return false;
            });
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
     * @return array<string, mixed>
     */
    private function context(array $context): array
    {
        $allowed = [
            'seed_product_id', 'current_product_id', 'cart_product_ids', 'cart_lines',
            'purchased_product_ids', 'order_product_ids', 'recently_viewed_product_ids',
            'surface', 'placement', 'market', 'currency', 'language',
        ];
        if (array_diff(array_keys($context), $allowed) !== []) {
            throw new PersonalizationException('INVALID_RECOMMENDATION_CONTEXT', '推荐上下文包含不支持的字段。');
        }

        $cartLines = $this->cartLines($context['cart_lines'] ?? []);
        $cartProductIds = $this->productIds($context['cart_product_ids'] ?? [], 50);
        $cartProductIds = array_values(array_unique([
            ...$cartProductIds,
            ...array_column($cartLines, 'product_id'),
        ]));
        $purchasedProductIds = array_values(array_unique([
            ...$this->productIds($context['purchased_product_ids'] ?? [], 100),
            ...$this->productIds($context['order_product_ids'] ?? [], 100),
        ]));
        $surface = trim((string) ($context['surface'] ?? $context['placement'] ?? 'unknown'));
        $placement = trim((string) ($context['placement'] ?? $surface));
        $allowedSurfaces = [
            'homepage', 'product_page', 'cart_page', 'smart_cart', 'checkout',
            'thank_you', 'order_status', 'post_purchase', 'email', 'unknown',
        ];
        if (! in_array($surface, $allowedSurfaces, true) || ! in_array($placement, $allowedSurfaces, true)) {
            throw new PersonalizationException('INVALID_RECOMMENDATION_SURFACE', '推荐展示场景无效。');
        }

        return [
            'seed_product_id' => ($context['seed_product_id'] ?? $context['current_product_id'] ?? null) === null
                ? null
                : $this->numericProductId($context['seed_product_id'] ?? $context['current_product_id']),
            'cart_product_ids' => $cartProductIds,
            'cart_lines' => $cartLines,
            'cart_context_available' => array_key_exists('cart_product_ids', $context) || array_key_exists('cart_lines', $context),
            'purchased_product_ids' => $purchasedProductIds,
            'order_context_available' => array_key_exists('purchased_product_ids', $context) || array_key_exists('order_product_ids', $context),
            'recently_viewed_product_ids' => $this->productIds($context['recently_viewed_product_ids'] ?? [], 50),
            'surface' => $surface,
            'placement' => $placement,
            'market' => $this->contextText($context['market'] ?? '', 80),
            'currency' => strtoupper($this->contextText($context['currency'] ?? '', 3)),
            'language' => $this->contextText($context['language'] ?? '', 20),
        ];
    }

    /** @return list<array{product_id: string, variant_id: ?string, quantity: int}> */
    private function cartLines(mixed $lines): array
    {
        if (! is_array($lines) || count($lines) > 50) {
            throw new PersonalizationException('INVALID_RECOMMENDATION_CONTEXT', '购物车商品行格式无效。');
        }
        $result = [];
        foreach ($lines as $line) {
            if (! is_array($line) || array_is_list($line)
                || array_diff(array_keys($line), ['product_id', 'variant_id', 'quantity']) !== []) {
                throw new PersonalizationException('INVALID_RECOMMENDATION_CONTEXT', '购物车商品行格式无效。');
            }
            $result[] = [
                'product_id' => $this->numericProductId($line['product_id'] ?? null),
                'variant_id' => filled($line['variant_id'] ?? null)
                    ? $this->numericVariantId($line['variant_id']) : null,
                'quantity' => max(1, min(999, (int) ($line['quantity'] ?? 1))),
            ];
        }

        return $result;
    }

    private function numericVariantId(mixed $value): string
    {
        $value = is_int($value) || is_string($value) ? trim((string) $value) : '';
        if (preg_match('#^gid://shopify/ProductVariant/(\d+)$#', $value, $matches) === 1) {
            return $matches[1];
        }
        if (preg_match('/^\d+$/', $value) !== 1) {
            throw new PersonalizationException('INVALID_RECOMMENDATION_CONTEXT', 'Shopify 变体 ID 格式无效。');
        }

        return $value;
    }

    private function contextText(mixed $value, int $maximum): string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';
        if (mb_strlen($value) > $maximum) {
            throw new PersonalizationException('INVALID_RECOMMENDATION_CONTEXT', '推荐上下文字段过长。');
        }

        return $value;
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
