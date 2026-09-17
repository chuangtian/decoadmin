<?php

namespace App\Services\Personalization;

use App\Enums\PersonalizationAlgorithm;
use App\Enums\PersonalizationComponentStatus;
use App\Enums\PersonalizationPlacement;
use App\Enums\PersonalizationProductOverrideType;
use App\Enums\PersonalizationRuleType;
use App\Enums\PersonalizationSmartCartCompatibilityStatus;
use App\Enums\PersonalizationStrategyStatus;
use App\Exceptions\PersonalizationException;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\PersonalizationComponentStyle;
use App\Models\PersonalizationRecommendationComponent;
use App\Models\PersonalizationRecommendationStrategy;
use App\Models\PersonalizationSmartCartSetting;
use App\Models\PersonalizationStrategyProductOverride;
use App\Models\PersonalizationStrategyRule;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PersonalizationConfigurationService
{
    public function __construct(private PersonalizationShopGuard $shopGuard) {}

    /** @param array<string, mixed> $input */
    public function createStrategy(Store $store, User $actor, array $input): PersonalizationRecommendationStrategy
    {
        $this->authorize($store, $actor, 'personalization.manage');
        $algorithm = $this->algorithm($input['algorithm'] ?? null);
        $name = $this->text($input['name'] ?? null, 'STRATEGY_NAME_REQUIRED', 80);
        $itemLimit = $this->integer($input['item_limit'] ?? 8, 1, 50, 'INVALID_ITEM_LIMIT');
        $settings = $this->boundedArray($input['settings'] ?? [], 'INVALID_STRATEGY_SETTINGS');

        $strategy = PersonalizationRecommendationStrategy::query()->create([
            'organization_id' => $store->organization_id,
            'store_id' => $store->id,
            'name' => $name,
            'algorithm' => $algorithm,
            // A strategy must be reviewed with its rules and products before activation.
            'enabled' => false,
            'item_limit' => $itemLimit,
            'settings' => $settings ?: null,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        $this->audit($store, $actor, $strategy, 'personalization_strategy_created', [
            'algorithm' => $algorithm->value,
            'item_limit' => $itemLimit,
        ]);

        return $strategy;
    }

    /** @param array<string, mixed> $input */
    public function updateStrategy(
        Store $store,
        PersonalizationRecommendationStrategy $strategy,
        User $actor,
        array $input,
    ): PersonalizationRecommendationStrategy {
        $this->authorize($store, $actor, 'personalization.manage');
        $this->assertStrategy($store, $strategy);
        $algorithm = $this->algorithm($input['algorithm'] ?? $strategy->algorithm->value);
        $name = $this->text($input['name'] ?? $strategy->name, 'STRATEGY_NAME_REQUIRED', 80);
        $itemLimit = $this->integer($input['item_limit'] ?? $strategy->item_limit, 1, 50, 'INVALID_ITEM_LIMIT');

        DB::transaction(function () use ($strategy, $actor, $algorithm, $name, $itemLimit): void {
            $strategy->forceFill([
                'name' => $name,
                'algorithm' => $algorithm,
                'item_limit' => $itemLimit,
                'enabled' => false,
                'updated_by' => $actor->id,
            ])->save();
            $this->resetPublication($strategy);
        });
        $this->audit($store, $actor, $strategy, 'personalization_strategy_updated', [
            'algorithm' => $algorithm->value,
            'item_limit' => $itemLimit,
            'publication_reset' => true,
        ]);

        return $strategy->refresh();
    }

    /** @param list<array<string, mixed>> $rules */
    public function replaceRules(
        Store $store,
        PersonalizationRecommendationStrategy $strategy,
        User $actor,
        array $rules,
    ): PersonalizationRecommendationStrategy {
        $this->authorize($store, $actor, 'personalization.manage');
        $this->assertStrategy($store, $strategy);
        if (count($rules) > 20) {
            throw new PersonalizationException('TOO_MANY_STRATEGY_RULES', '单个策略最多配置 20 条规则。');
        }

        $normalized = [];
        $seen = [];
        foreach ($rules as $position => $rule) {
            if (! is_array($rule)) {
                throw new PersonalizationException('INVALID_STRATEGY_RULE', '推荐规则格式无效。');
            }
            $type = PersonalizationRuleType::tryFrom((string) ($rule['type'] ?? ''));
            if (! $type || isset($seen[$type->value])) {
                throw new PersonalizationException('INVALID_STRATEGY_RULE', '推荐规则类型无效或重复。');
            }
            $seen[$type->value] = true;
            $normalized[] = [
                'type' => $type,
                'value' => $this->normalizeRuleValue($type, $rule['value'] ?? null),
                'enabled' => (bool) ($rule['enabled'] ?? true),
                'position' => $position + 1,
            ];
        }

        DB::transaction(function () use ($store, $strategy, $normalized): void {
            $strategy->rules()->delete();
            foreach ($normalized as $rule) {
                PersonalizationStrategyRule::query()->create([
                    'organization_id' => $store->organization_id,
                    'store_id' => $store->id,
                    'strategy_id' => $strategy->id,
                    ...$rule,
                ]);
            }
            $strategy->forceFill(['enabled' => false])->save();
            $this->resetPublication($strategy);
        });
        $this->audit($store, $actor, $strategy, 'personalization_strategy_rules_replaced', [
            'rules' => count($normalized),
            'types' => array_map(fn (array $rule): string => $rule['type']->value, $normalized),
        ]);

        return $strategy->load('rules');
    }

    /** @param list<array<string, mixed>> $overrides */
    public function replaceProductOverrides(
        Store $store,
        PersonalizationRecommendationStrategy $strategy,
        User $actor,
        array $overrides,
    ): PersonalizationRecommendationStrategy {
        $this->authorize($store, $actor, 'personalization.manage');
        $this->assertStrategy($store, $strategy);
        if (count($overrides) > 500) {
            throw new PersonalizationException('TOO_MANY_PRODUCT_OVERRIDES', '单个策略最多配置 500 个商品。');
        }

        $normalized = [];
        $seen = [];
        foreach ($overrides as $position => $override) {
            if (! is_array($override)) {
                throw new PersonalizationException('INVALID_PRODUCT_OVERRIDE', '推荐商品配置格式无效。');
            }
            $shopifyProductId = trim((string) ($override['shopify_product_id'] ?? ''));
            $type = PersonalizationProductOverrideType::tryFrom((string) ($override['type'] ?? ''));
            if (! $type || preg_match('#^gid://shopify/Product/(\d+)$#', $shopifyProductId, $matches) !== 1) {
                throw new PersonalizationException('INVALID_PRODUCT_OVERRIDE', '推荐商品标识或操作类型无效。');
            }
            $shopifyProductId = $matches[1];
            if (isset($seen[$shopifyProductId])) {
                throw new PersonalizationException('CONFLICTING_PRODUCT_OVERRIDE', '同一商品不能重复配置推荐操作。');
            }
            $seen[$shopifyProductId] = true;
            $normalized[] = [
                'shopify_product_id' => $shopifyProductId,
                'type' => $type,
                'position' => $position + 1,
            ];
        }

        $ownedProducts = Product::query()
            ->where('organization_id', $store->organization_id)
            ->where('store_id', $store->id)
            ->whereIn('shopify_product_id', array_keys($seen))
            ->pluck('id', 'shopify_product_id');
        if ($ownedProducts->count() !== count($seen)) {
            throw new PersonalizationException(
                'PRODUCT_OVERRIDE_NOT_FOUND',
                '部分推荐商品不属于当前店铺或尚未同步。',
                404,
            );
        }

        DB::transaction(function () use ($store, $strategy, $normalized, $ownedProducts): void {
            $strategy->productOverrides()->delete();
            foreach ($normalized as $override) {
                PersonalizationStrategyProductOverride::query()->create([
                    'organization_id' => $store->organization_id,
                    'store_id' => $store->id,
                    'strategy_id' => $strategy->id,
                    'product_id' => $ownedProducts->get($override['shopify_product_id']),
                    ...$override,
                ]);
            }
            $strategy->forceFill(['enabled' => false])->save();
            $this->resetPublication($strategy);
        });
        $this->audit($store, $actor, $strategy, 'personalization_strategy_products_replaced', [
            'products' => count($normalized),
            'types' => collect($normalized)->countBy(fn (array $row): string => $row['type']->value)->all(),
        ]);

        return $strategy->load('productOverrides.product');
    }

    /** @param array<string, mixed> $input */
    public function createComponent(
        Store $store,
        PersonalizationRecommendationStrategy $strategy,
        User $actor,
        array $input,
    ): PersonalizationRecommendationComponent {
        $this->authorize($store, $actor, 'personalization.manage');
        $this->assertStrategy($store, $strategy);
        $placement = PersonalizationPlacement::tryFrom((string) ($input['placement'] ?? ''));
        if (! $placement) {
            throw new PersonalizationException('INVALID_COMPONENT_PLACEMENT', '推荐组件展示位置无效。');
        }
        $name = $this->text($input['name'] ?? null, 'COMPONENT_NAME_REQUIRED', 80);
        $heading = $this->optionalText($input['heading'] ?? null, 120, 'INVALID_COMPONENT_HEADING');
        $buttonLabel = $this->optionalText($input['button_label'] ?? null, 60, 'INVALID_COMPONENT_BUTTON_LABEL');
        $settings = $this->boundedArray($input['settings'] ?? [], 'INVALID_COMPONENT_SETTINGS');

        $component = DB::transaction(function () use ($store, $strategy, $actor, $placement, $name, $heading, $buttonLabel, $settings): PersonalizationRecommendationComponent {
            $position = (int) PersonalizationRecommendationComponent::query()
                ->where('store_id', $store->id)
                ->where('placement', $placement->value)
                ->lockForUpdate()
                ->max('position');
            $component = PersonalizationRecommendationComponent::query()->create([
                'organization_id' => $store->organization_id,
                'store_id' => $store->id,
                'strategy_id' => $strategy->id,
                'name' => $name,
                'placement' => $placement,
                'status' => PersonalizationComponentStatus::Draft,
                'heading' => $heading,
                'button_label' => $buttonLabel,
                'position' => $position + 1,
                'settings' => $settings ?: null,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
            PersonalizationComponentStyle::query()->create([
                'organization_id' => $store->organization_id,
                'store_id' => $store->id,
                'component_id' => $component->id,
            ]);

            return $component;
        });
        $this->audit($store, $actor, $component, 'personalization_component_created', [
            'placement' => $placement->value,
            'strategy_uuid' => $strategy->uuid,
        ]);

        return $component->load(['strategy', 'style']);
    }

    /** @param array<string, mixed> $input */
    public function updateComponent(
        Store $store,
        PersonalizationRecommendationComponent $component,
        PersonalizationRecommendationStrategy $strategy,
        User $actor,
        array $input,
    ): PersonalizationRecommendationComponent {
        $this->authorize($store, $actor, 'personalization.manage');
        $this->assertComponent($store, $component);
        $this->assertStrategy($store, $strategy);
        $placement = PersonalizationPlacement::tryFrom((string) ($input['placement'] ?? $component->placement->value));
        if (! $placement) {
            throw new PersonalizationException('INVALID_COMPONENT_PLACEMENT', '推荐组件展示位置无效。');
        }

        $component->forceFill([
            'strategy_id' => $strategy->id,
            'name' => $this->text($input['name'] ?? $component->name, 'COMPONENT_NAME_REQUIRED', 80),
            'placement' => $placement,
            'heading' => $this->optionalText($input['heading'] ?? null, 120, 'INVALID_COMPONENT_HEADING'),
            'button_label' => $this->optionalText($input['button_label'] ?? null, 60, 'INVALID_COMPONENT_BUTTON_LABEL'),
            'settings' => array_key_exists('experiment', $input)
                ? array_replace($component->settings ?? [], ['editor_experiment' => $input['experiment']])
                : $component->settings,
            'status' => PersonalizationComponentStatus::Draft,
            'published_at' => null,
            'updated_by' => $actor->id,
        ])->save();
        $this->audit($store, $actor, $component, 'personalization_component_updated', [
            'placement' => $placement->value,
            'strategy_uuid' => $strategy->uuid,
            'publication_reset' => true,
        ]);

        return $component->load(['strategy', 'style']);
    }

    public function activateComponent(
        Store $store,
        PersonalizationRecommendationComponent $component,
        User $actor,
    ): PersonalizationRecommendationComponent {
        $this->authorize($store, $actor, 'personalization.manage');
        $this->assertComponent($store, $component);
        $component->loadMissing(['strategy.productOverrides', 'strategy.rules', 'style']);
        $strategy = $component->strategy;
        if (! $strategy) {
            throw new PersonalizationException('STRATEGY_NOT_FOUND', '推荐组件缺少有效策略。', 409);
        }
        $this->assertStrategyReady($strategy);
        if (! $component->style) {
            throw new PersonalizationException('COMPONENT_STYLE_REQUIRED', '推荐组件缺少样式配置。', 409);
        }

        DB::transaction(function () use ($strategy, $component, $actor): void {
            $strategy->forceFill(['enabled' => true, 'updated_by' => $actor->id])->save();
            $component->forceFill([
                'status' => PersonalizationComponentStatus::Active,
                'published_at' => now(),
                'updated_by' => $actor->id,
            ])->save();
        });
        $this->audit($store, $actor, $component, 'personalization_component_activated', [
            'placement' => $component->placement->value,
            'strategy_uuid' => $strategy->uuid,
        ]);

        return $component->refresh()->load(['strategy', 'style']);
    }

    public function disableComponent(
        Store $store,
        PersonalizationRecommendationComponent $component,
        User $actor,
    ): PersonalizationRecommendationComponent {
        $this->authorize($store, $actor, 'personalization.manage');
        $this->assertComponent($store, $component);
        $component->forceFill([
            'status' => PersonalizationComponentStatus::Disabled,
            'published_at' => null,
            'updated_by' => $actor->id,
        ])->save();
        $this->audit($store, $actor, $component, 'personalization_component_disabled', [
            'placement' => $component->placement->value,
        ]);

        return $component->refresh();
    }

    /** @param array<string, mixed> $input */
    public function updateStyle(
        Store $store,
        PersonalizationRecommendationComponent $component,
        User $actor,
        array $input,
    ): PersonalizationComponentStyle {
        $this->authorize($store, $actor, 'personalization.manage');
        $this->assertComponent($store, $component);
        $style = $component->style()->firstOrCreate([
            'organization_id' => $store->organization_id,
            'store_id' => $store->id,
        ]);
        $layout = (string) ($input['layout'] ?? $style->layout);
        if (! in_array($layout, ['carousel', 'grid'], true)) {
            throw new PersonalizationException('INVALID_COMPONENT_LAYOUT', '推荐组件布局无效。');
        }
        $tokens = $this->styleTokens($input['tokens'] ?? ($style->tokens ?? []));

        $style->fill([
            'layout' => $layout,
            'desktop_columns' => $this->integer($input['desktop_columns'] ?? $style->desktop_columns, 1, 6, 'INVALID_DESKTOP_COLUMNS'),
            'mobile_columns' => $this->integer($input['mobile_columns'] ?? $style->mobile_columns, 1, 3, 'INVALID_MOBILE_COLUMNS'),
            'show_image' => (bool) ($input['show_image'] ?? $style->show_image),
            'show_vendor' => (bool) ($input['show_vendor'] ?? $style->show_vendor),
            'show_price' => (bool) ($input['show_price'] ?? $style->show_price),
            'show_compare_at_price' => (bool) ($input['show_compare_at_price'] ?? $style->show_compare_at_price),
            'show_add_to_cart' => (bool) ($input['show_add_to_cart'] ?? $style->show_add_to_cart),
            'tokens' => $tokens ?: null,
        ])->save();
        if ($component->status === PersonalizationComponentStatus::Active) {
            $component->forceFill([
                'status' => PersonalizationComponentStatus::Draft,
                'published_at' => null,
                'updated_by' => $actor->id,
            ])->save();
        }
        $this->audit($store, $actor, $component, 'personalization_component_style_updated', [
            'layout' => $layout,
            'desktop_columns' => $style->desktop_columns,
            'mobile_columns' => $style->mobile_columns,
        ]);

        return $style->refresh();
    }

    /** @param array<string, mixed> $settings */
    public function saveSmartCartDraft(
        Store $store,
        User $actor,
        ?PersonalizationRecommendationStrategy $strategy,
        array $settings = [],
    ): PersonalizationSmartCartSetting {
        $this->authorize($store, $actor, 'personalization.smart_cart.manage');
        if ($strategy) {
            $this->assertStrategy($store, $strategy);
            $this->assertStrategyReady($strategy);
        }
        $settings = $this->boundedArray($settings, 'INVALID_SMART_CART_SETTINGS');

        $setting = DB::transaction(function () use ($store, $actor, $strategy, $settings): PersonalizationSmartCartSetting {
            $existing = PersonalizationSmartCartSetting::query()
                ->where('organization_id', $store->organization_id)
                ->where('store_id', $store->id)
                ->lockForUpdate()
                ->first();
            $previousStrategy = $existing?->strategy;
            $enabled = $strategy !== null;
            $setting = PersonalizationSmartCartSetting::query()->updateOrCreate(
                ['store_id' => $store->id],
                [
                    'organization_id' => $store->organization_id,
                    'strategy_id' => $strategy?->id,
                    'enabled' => $enabled,
                    'compatibility_status' => PersonalizationSmartCartCompatibilityStatus::Unchecked,
                    'compatibility_details' => ['mode' => 'native_cart_embed'],
                    'compatibility_checked_at' => null,
                    'preview_confirmed_at' => null,
                    'enabled_at' => $enabled ? now() : null,
                    'disabled_at' => $enabled ? null : now(),
                    'fallback_mode' => 'native_cart',
                    'settings' => $settings ?: null,
                    'updated_by' => $actor->id,
                ],
            );
            if ($strategy) {
                $strategy->forceFill([
                    'enabled' => true,
                    'status' => PersonalizationStrategyStatus::Enabled,
                    'updated_by' => $actor->id,
                ])->save();
            }
            if ($previousStrategy && (! $strategy || (int) $previousStrategy->id !== (int) $strategy->id)) {
                $hasLivePlacement = $previousStrategy->components()
                    ->where('status', PersonalizationComponentStatus::Active->value)
                    ->exists();
                $previousStrategy->forceFill([
                    'enabled' => $hasLivePlacement,
                    'status' => $hasLivePlacement
                        ? PersonalizationStrategyStatus::Enabled
                        : ($previousStrategy->published_version_id
                            ? PersonalizationStrategyStatus::Disabled
                            : PersonalizationStrategyStatus::Draft),
                    'updated_by' => $actor->id,
                ])->save();
            }

            return $setting->refresh()->load('strategy');
        });
        $this->audit($store, $actor, $setting, 'personalization_smart_cart_configuration_saved', [
            'enabled' => $setting->enabled,
            'mode' => 'native_cart_embed',
            'strategy_uuid' => $strategy?->uuid,
        ]);

        return $setting;
    }

    private function assertStrategyReady(PersonalizationRecommendationStrategy $strategy): void
    {
        $mode = data_get($strategy->settings, 'recommendation_rule.mode', 'preset');
        if ($mode === 'custom') {
            $products = collect(data_get($strategy->settings, 'recommendation_rule.custom.rules', []))
                ->flatMap(fn (array $rule): array => data_get($rule, 'action.products', []))
                ->merge(data_get($strategy->settings, 'recommendation_rule.custom.fallback.action.products', []))
                ->filter(fn (mixed $product): bool => is_array($product) && filled($product['shopify_product_id'] ?? null));
            if ($products->isEmpty()) {
                throw new PersonalizationException('CUSTOM_RULE_PRODUCTS_REQUIRED', 'Custom rules require at least one action product or fallback product.', 409);
            }

            return;
        }
        if ($strategy->algorithm === PersonalizationAlgorithm::Manual
            && ! $strategy->productOverrides()->where('type', PersonalizationProductOverrideType::Manual->value)->exists()
            && ! $strategy->rules()->where('type', PersonalizationRuleType::IncludeCollections->value)->where('enabled', true)->exists()) {
            throw new PersonalizationException('MANUAL_PRODUCTS_REQUIRED', 'A manual recommendation strategy requires at least one product or collection.', 409);
        }
    }

    /** @return array{strategies: mixed, components: mixed, smart_cart: ?PersonalizationSmartCartSetting} */
    public function configuration(Store $store, User $actor): array
    {
        $this->authorize($store, $actor, 'personalization.view');

        return [
            'strategies' => PersonalizationRecommendationStrategy::query()
                ->where('organization_id', $store->organization_id)
                ->where('store_id', $store->id)
                ->with(['rules', 'productOverrides.product'])
                ->orderBy('name')
                ->get(),
            'components' => PersonalizationRecommendationComponent::query()
                ->where('organization_id', $store->organization_id)
                ->where('store_id', $store->id)
                ->with(['strategy', 'style'])
                ->orderBy('placement')
                ->orderBy('position')
                ->get(),
            'smart_cart' => PersonalizationSmartCartSetting::query()
                ->where('organization_id', $store->organization_id)
                ->where('store_id', $store->id)
                ->with('strategy')
                ->first(),
        ];
    }

    private function authorize(Store $store, User $actor, string $permission): void
    {
        $this->shopGuard->assertAllowed((string) $store->shopify_domain);
        $organization = $store->organization;
        if (! $organization instanceof Organization
            || (int) $store->organization_id !== (int) $organization->id
            || $store->status !== 'active'
            || $organization->status !== 'active'
            || ! $actor->canAccessStore($store)
            || ! $actor->hasPermission($permission, $organization, $store)) {
            throw new PersonalizationException(
                'PERSONALIZATION_ACCESS_DENIED',
                '无权访问当前店铺的个性化推荐配置。',
                403,
            );
        }
    }

    private function assertStrategy(Store $store, PersonalizationRecommendationStrategy $strategy): void
    {
        if ((int) $strategy->organization_id !== (int) $store->organization_id
            || (int) $strategy->store_id !== (int) $store->id) {
            throw new PersonalizationException('STRATEGY_NOT_FOUND', '找不到该推荐策略。', 404);
        }
    }

    private function assertComponent(Store $store, PersonalizationRecommendationComponent $component): void
    {
        if ((int) $component->organization_id !== (int) $store->organization_id
            || (int) $component->store_id !== (int) $store->id) {
            throw new PersonalizationException('COMPONENT_NOT_FOUND', '找不到该推荐组件。', 404);
        }
    }

    private function resetPublication(PersonalizationRecommendationStrategy $strategy): void
    {
        PersonalizationRecommendationComponent::query()
            ->where('organization_id', $strategy->organization_id)
            ->where('store_id', $strategy->store_id)
            ->where('strategy_id', $strategy->id)
            ->where('status', PersonalizationComponentStatus::Active->value)
            ->update([
                'status' => PersonalizationComponentStatus::Draft->value,
                'published_at' => null,
                'updated_at' => now(),
            ]);
    }

    private function algorithm(mixed $value): PersonalizationAlgorithm
    {
        $algorithm = PersonalizationAlgorithm::tryFrom((string) $value);
        if (! $algorithm) {
            throw new PersonalizationException('INVALID_RECOMMENDATION_ALGORITHM', '推荐算法无效。');
        }

        return $algorithm;
    }

    private function text(mixed $value, string $code, int $maxLength): string
    {
        $value = is_string($value) ? trim($value) : '';
        if ($value === '' || mb_strlen($value) > $maxLength) {
            throw new PersonalizationException($code, '名称不能为空或超过允许长度。');
        }

        return $value;
    }

    private function optionalText(mixed $value, int $maxLength, string $code): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) || mb_strlen(trim($value)) > $maxLength) {
            throw new PersonalizationException($code, '文案超过允许长度。');
        }

        return trim($value);
    }

    private function integer(mixed $value, int $minimum, int $maximum, string $code): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new PersonalizationException($code, '数值格式无效。');
        }
        $value = (int) $value;
        if ($value < $minimum || $value > $maximum) {
            throw new PersonalizationException($code, '数值超出允许范围。');
        }

        return $value;
    }

    /** @return array<string, mixed> */
    private function boundedArray(mixed $value, string $code): array
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new PersonalizationException($code, '配置必须是对象。');
        }
        $json = json_encode($value);
        if (! is_string($json) || strlen($json) > 16 * 1024) {
            throw new PersonalizationException($code, '配置超过允许大小。');
        }

        return $value;
    }

    /** @return array<string, mixed> */
    private function normalizeRuleValue(PersonalizationRuleType $type, mixed $value): array
    {
        return match ($type) {
            PersonalizationRuleType::IncludeTags,
            PersonalizationRuleType::ExcludeTags => ['tags' => $this->tags($value)],
            PersonalizationRuleType::MinimumPrice,
            PersonalizationRuleType::MaximumPrice => ['amount' => $this->decimal($value, 'INVALID_PRICE_RULE')],
            PersonalizationRuleType::MinimumInventory => [
                'quantity' => $this->integer($value, 0, 1_000_000, 'INVALID_INVENTORY_RULE'),
            ],
            PersonalizationRuleType::InStockOnly => ['enabled' => (bool) $value],
            PersonalizationRuleType::IncludeCollections,
            PersonalizationRuleType::ExcludeCollections => ['collection_ids' => $this->resourceIds($value)],
            PersonalizationRuleType::ExcludeVendors => ['vendors' => $this->tags($value)],
            PersonalizationRuleType::ExcludePurchaseOptions => ['purchase_options' => $this->tags($value)],
            PersonalizationRuleType::ExcludeCartProducts,
            PersonalizationRuleType::ExcludePurchasedProducts => ['enabled' => (bool) $value],
        };
    }

    /** @return list<string> */
    private function resourceIds(mixed $value): array
    {
        if (! is_array($value) || count($value) > 100) {
            throw new PersonalizationException('INVALID_COLLECTION_RULE', '集合规则最多包含 100 个集合。');
        }
        $ids = [];
        foreach ($value as $id) {
            $id = trim((string) $id);
            if (preg_match('#^gid://shopify/Collection/(\d+)$#', $id, $matches) === 1) {
                $id = $matches[1];
            }
            if (preg_match('/^\d+$/', $id) !== 1) {
                throw new PersonalizationException('INVALID_COLLECTION_RULE', '集合规则包含无效 Shopify ID。');
            }
            $ids[] = $id;
        }

        return array_values(array_unique($ids));
    }

    /** @return list<string> */
    private function tags(mixed $value): array
    {
        if (! is_array($value) || count($value) > 50) {
            throw new PersonalizationException('INVALID_TAG_RULE', '标签规则最多包含 50 个标签。');
        }
        $tags = [];
        foreach ($value as $tag) {
            if (! is_string($tag) || trim($tag) === '' || mb_strlen(trim($tag)) > 100) {
                throw new PersonalizationException('INVALID_TAG_RULE', '标签规则包含无效标签。');
            }
            $tags[] = trim($tag);
        }

        return array_values(array_unique($tags));
    }

    private function decimal(mixed $value, string $code): string
    {
        if (! is_numeric($value) || (float) $value < 0 || (float) $value > 999_999_999) {
            throw new PersonalizationException($code, '价格规则数值无效。');
        }

        return number_format((float) $value, 2, '.', '');
    }

    /** @return array<string, mixed> */
    private function styleTokens(mixed $value): array
    {
        $tokens = $this->boundedArray($value, 'INVALID_STYLE_TOKENS');
        $allowed = [
            'text_color', 'background_color', 'button_color', 'button_text_color', 'border_color',
            'title_color', 'title_color_mobile', 'title_color_desktop', 'product_background_color', 'product_name_color', 'description_color',
            'price_color', 'compare_at_color', 'discount_color', 'selector_background_color',
            'selector_border_color', 'selector_text_color', 'button_border_color',
            'border_radius', 'gap', 'mobile_padding_y', 'mobile_padding_x', 'desktop_padding_y',
            'desktop_padding_x', 'title_padding', 'product_padding', 'content_radius', 'image_radius',
            'selector_radius', 'button_radius', 'title_font_size_mobile', 'title_font_size_desktop',
            'product_font_size', 'button_font_size', 'title_font_weight_mobile',
            'title_font_weight_desktop', 'product_font_weight', 'button_font_weight',
            'title_font_family_mobile', 'title_font_family_desktop', 'product_font_family', 'button_font_family', 'title_alignment',
            'content_alignment', 'unified_alignment', 'mobile_layout', 'desktop_layout', 'image_source',
            'image_aspect_ratio', 'image_border', 'widget_border', 'show_product_name',
            'show_description', 'show_reviews', 'show_discount_value', 'discount_text',
            'comparison_source', 'variant_display', 'variant_layout', 'variant_preselection',
            'show_subscription_options', 'allow_quantity', 'select_variant_text', 'primary_action',
            'success_message', 'auto_sync_theme', 'custom_css',
        ];
        if (array_diff(array_keys($tokens), $allowed) !== []) {
            throw new PersonalizationException('INVALID_STYLE_TOKENS', '样式包含不支持的字段。');
        }
        foreach ([
            'text_color', 'background_color', 'button_color', 'button_text_color', 'border_color',
            'title_color', 'title_color_mobile', 'title_color_desktop', 'product_background_color', 'product_name_color', 'description_color',
            'price_color', 'compare_at_color', 'discount_color', 'selector_background_color',
            'selector_border_color', 'selector_text_color', 'button_border_color',
        ] as $colorKey) {
            if (isset($tokens[$colorKey])
                && (! is_string($tokens[$colorKey]) || preg_match('/^#[0-9a-fA-F]{6}$/', $tokens[$colorKey]) !== 1)) {
                throw new PersonalizationException('INVALID_STYLE_TOKENS', '颜色值格式无效。');
            }
        }
        foreach ([
            'border_radius', 'gap', 'mobile_padding_y', 'mobile_padding_x', 'desktop_padding_y',
            'desktop_padding_x', 'title_padding', 'product_padding', 'content_radius', 'image_radius',
            'selector_radius', 'button_radius',
        ] as $sizeKey) {
            if (isset($tokens[$sizeKey])) {
                $tokens[$sizeKey] = $this->integer($tokens[$sizeKey], 0, 48, 'INVALID_STYLE_TOKENS');
            }
        }
        foreach (['title_font_size_mobile', 'title_font_size_desktop', 'product_font_size', 'button_font_size'] as $sizeKey) {
            if (isset($tokens[$sizeKey])) {
                $tokens[$sizeKey] = $this->integer($tokens[$sizeKey], 12, 40, 'INVALID_STYLE_TOKENS');
            }
        }
        foreach (['title_font_weight_mobile', 'title_font_weight_desktop', 'product_font_weight', 'button_font_weight'] as $weightKey) {
            if (isset($tokens[$weightKey])) {
                $tokens[$weightKey] = $this->integer($tokens[$weightKey], 400, 800, 'INVALID_STYLE_TOKENS');
            }
        }
        $fontChoices = [
            'theme', 'Arial', 'Abril Fatface', 'Anton', 'Arvo', 'Bangers', 'Bitter', 'Caveat',
            'Cousine', 'Cutive Mono', 'Delius', 'Gravitas One', 'Josefin Slab', 'Lato', 'Lora',
            'Montserrat', 'Old Standard TT', 'Open Sans', 'Oswald', 'Source Sans Pro', 'Tangerine',
            'Raleway', 'Courier New', 'Arial Black', 'Comic Sans MS', 'Georgia', 'Impact', 'Tahoma',
            'Times New Roman', 'Trebuchet MS', 'Verdana', 'Microsoft Sans Serif',
        ];
        foreach (['title_font_family_mobile', 'title_font_family_desktop', 'product_font_family', 'button_font_family'] as $fontKey) {
            if (isset($tokens[$fontKey]) && (! is_string($tokens[$fontKey]) || ! in_array($tokens[$fontKey], $fontChoices, true))) {
                throw new PersonalizationException('INVALID_STYLE_TOKENS', '字体选项无效。');
            }
        }
        foreach ([
            'title_alignment' => ['left', 'center', 'right'], 'content_alignment' => ['left', 'center', 'right'],
            'mobile_layout' => ['list', 'grid', 'carousel'], 'desktop_layout' => ['list', 'grid', 'carousel'],
            'image_source' => ['product', 'variant'], 'image_aspect_ratio' => ['1:1', '4:5', '4:3'],
            'image_border' => ['none', 'solid'], 'widget_border' => ['none', 'solid'],
            'comparison_source' => ['compare_at_price', 'product_price'], 'variant_display' => ['dynamic', 'always', 'hidden'],
            'variant_layout' => ['dropdown', 'buttons'], 'variant_preselection' => ['smart_match', 'first_available'],
            'primary_action' => ['stay', 'cart', 'checkout'],
        ] as $key => $choices) {
            if (isset($tokens[$key]) && (! is_string($tokens[$key]) || ! in_array($tokens[$key], $choices, true))) {
                throw new PersonalizationException('INVALID_STYLE_TOKENS', '样式选项无效。');
            }
        }
        foreach (['unified_alignment', 'show_product_name', 'show_description', 'show_reviews', 'show_discount_value', 'show_subscription_options', 'allow_quantity', 'auto_sync_theme'] as $booleanKey) {
            if (isset($tokens[$booleanKey]) && ! is_bool($tokens[$booleanKey])) {
                throw new PersonalizationException('INVALID_STYLE_TOKENS', '样式开关无效。');
            }
        }
        foreach (['discount_text', 'select_variant_text', 'success_message'] as $textKey) {
            if (isset($tokens[$textKey]) && (! is_string($tokens[$textKey]) || mb_strlen($tokens[$textKey]) > 200)) {
                throw new PersonalizationException('INVALID_STYLE_TOKENS', '样式文案无效。');
            }
        }
        if (isset($tokens['custom_css'])) {
            if (! is_string($tokens['custom_css']) || strlen($tokens['custom_css']) > 2000
                || preg_match('/[{}@]|url\s*\(|expression\s*\(/i', $tokens['custom_css']) === 1) {
                throw new PersonalizationException('INVALID_STYLE_TOKENS', '自定义 CSS 仅支持安全的属性声明。');
            }
        }

        return $tokens;
    }

    /** @param array<string, mixed> $metadata */
    private function audit(Store $store, User $actor, object $subject, string $action, array $metadata): void
    {
        AuditLog::query()->create([
            'organization_id' => $store->organization_id,
            'store_id' => $store->id,
            'user_id' => $actor->id,
            'action' => $action,
            'subject_type' => $subject::class,
            'subject_id' => $subject->id,
            'metadata' => [
                'scope' => 'store',
                'environment' => (string) config('personalization.environment'),
                ...$metadata,
            ],
        ]);
    }
}
