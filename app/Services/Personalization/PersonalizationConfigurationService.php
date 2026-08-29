<?php

namespace App\Services\Personalization;

use App\Enums\PersonalizationAlgorithm;
use App\Enums\PersonalizationComponentStatus;
use App\Enums\PersonalizationPlacement;
use App\Enums\PersonalizationProductOverrideType;
use App\Enums\PersonalizationRuleType;
use App\Enums\PersonalizationSmartCartCompatibilityStatus;
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
    /** @var array<string, string> */
    private const SMART_CART_REQUIRED_CHECKS = [
        'unpublished_copy' => '使用未发布的测试主题副本',
        'app_embed_loaded' => 'App Embed 已在测试主题预览中加载',
        'browser_dialog' => '浏览器支持安全购物车抽屉',
        'cart_link' => '测试主题可识别购物车入口',
        'cart_routes' => 'Shopify 购物车接口可用',
        'cart_behaviour_verified' => '购物车打开、数量、删除与加购行为已验证',
    ];

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
        if ($strategy->algorithm === PersonalizationAlgorithm::Manual
            && ! $strategy->productOverrides->contains('type', PersonalizationProductOverrideType::Manual)
            && ! $strategy->rules->contains(fn ($rule): bool => $rule->enabled
                && $rule->type === PersonalizationRuleType::IncludeCollections
                && ($rule->value['collection_ids'] ?? []) !== [])) {
            throw new PersonalizationException(
                'MANUAL_PRODUCTS_REQUIRED',
                '手动推荐策略至少需要选择一个手动推荐商品。',
                409,
            );
        }
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
        }
        $settings = $this->boundedArray($settings, 'INVALID_SMART_CART_SETTINGS');

        $setting = PersonalizationSmartCartSetting::query()->updateOrCreate(
            ['store_id' => $store->id],
            [
                'organization_id' => $store->organization_id,
                'strategy_id' => $strategy?->id,
                // Enabling requires compatibility checking, preview and a separate
                // guarded activation flow implemented in the Smart Cart stage.
                'enabled' => false,
                'compatibility_status' => PersonalizationSmartCartCompatibilityStatus::Unchecked,
                'compatibility_details' => null,
                'compatibility_checked_at' => null,
                'theme_id' => null,
                'theme_name' => null,
                'preview_confirmed_at' => null,
                'enabled_at' => null,
                'disabled_at' => now(),
                'fallback_mode' => 'shopify_default',
                'settings' => $settings ?: null,
                'updated_by' => $actor->id,
            ],
        );
        $this->audit($store, $actor, $setting, 'personalization_smart_cart_draft_saved', [
            'enabled' => false,
            'compatibility_status' => PersonalizationSmartCartCompatibilityStatus::Unchecked->value,
            'fallback_mode' => 'shopify_default',
            'strategy_uuid' => $strategy?->uuid,
        ]);

        return $setting;
    }

    /** @param list<array<string, mixed>> $checks */
    public function recordSmartCartCompatibility(
        Store $store,
        User $actor,
        string $themeId,
        string $themeName,
        array $checks,
    ): PersonalizationSmartCartSetting {
        $this->authorize($store, $actor, 'personalization.smart_cart.manage');
        $themeId = trim($themeId);
        $themeName = trim($themeName);
        if (preg_match('/^\d{1,64}$/', $themeId) !== 1 || $themeName === '' || mb_strlen($themeName) > 120) {
            throw new PersonalizationException('INVALID_SMART_CART_THEME', '测试主题名称或 Theme ID 无效。');
        }
        if ($checks === [] || count($checks) > 20) {
            throw new PersonalizationException('INVALID_COMPATIBILITY_CHECKS', '兼容性检查结果无效。');
        }

        $normalized = [];
        $seen = [];
        foreach ($checks as $check) {
            if (! is_array($check)) {
                throw new PersonalizationException('INVALID_COMPATIBILITY_CHECKS', '兼容性检查结果无效。');
            }
            $key = trim((string) ($check['key'] ?? ''));
            $label = trim((string) ($check['label'] ?? ''));
            $details = trim((string) ($check['details'] ?? ''));
            if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $key) !== 1
                || ! isset(self::SMART_CART_REQUIRED_CHECKS[$key])
                || isset($seen[$key])
                || $label === '' || mb_strlen($label) > 120
                || mb_strlen($details) > 240) {
                throw new PersonalizationException('INVALID_COMPATIBILITY_CHECKS', '兼容性检查结果包含无效字段。');
            }
            $seen[$key] = true;
            $normalized[] = [
                'key' => $key,
                'label' => self::SMART_CART_REQUIRED_CHECKS[$key],
                'passed' => (bool) ($check['passed'] ?? false),
                'details' => $details ?: null,
            ];
        }
        if (array_diff_key(self::SMART_CART_REQUIRED_CHECKS, $seen) !== []) {
            throw new PersonalizationException('INVALID_COMPATIBILITY_CHECKS', '兼容性检查缺少必填项目。');
        }
        $status = collect($normalized)->every(fn (array $check): bool => $check['passed'])
            ? PersonalizationSmartCartCompatibilityStatus::Compatible
            : PersonalizationSmartCartCompatibilityStatus::Incompatible;
        $setting = PersonalizationSmartCartSetting::query()->firstOrNew(['store_id' => $store->id]);
        $setting->fill([
            'organization_id' => $store->organization_id,
            'enabled' => false,
            'compatibility_status' => $status,
            'compatibility_details' => ['checks' => $normalized, 'source' => 'authenticated_admin'],
            'compatibility_checked_at' => now(),
            'theme_id' => $themeId,
            'theme_name' => $themeName,
            'preview_confirmed_at' => null,
            'enabled_at' => null,
            'disabled_at' => now(),
            'fallback_mode' => 'shopify_default',
            'updated_by' => $actor->id,
        ])->save();
        $this->audit($store, $actor, $setting, 'personalization_smart_cart_compatibility_recorded', [
            'theme_id' => $themeId,
            'status' => $status->value,
            'checks' => count($normalized),
        ]);

        return $setting->refresh();
    }

    public function confirmSmartCartPreview(
        Store $store,
        User $actor,
    ): PersonalizationSmartCartSetting {
        $this->authorize($store, $actor, 'personalization.smart_cart.manage');
        $setting = $this->smartCartSetting($store);
        if ($setting->compatibility_status !== PersonalizationSmartCartCompatibilityStatus::Compatible
            || ! $setting->compatibility_checked_at
            || ! $setting->theme_id) {
            throw new PersonalizationException(
                'SMART_CART_COMPATIBILITY_REQUIRED',
                '必须先完成指定测试主题的兼容性检查。',
                409,
            );
        }
        $setting->forceFill([
            'enabled' => false,
            'preview_confirmed_at' => now(),
            'enabled_at' => null,
            'disabled_at' => now(),
            'fallback_mode' => 'shopify_default',
            'updated_by' => $actor->id,
        ])->save();
        $this->audit($store, $actor, $setting, 'personalization_smart_cart_preview_confirmed', [
            'theme_id' => $setting->theme_id,
        ]);

        return $setting->refresh();
    }

    public function activateSmartCart(Store $store, User $actor): PersonalizationSmartCartSetting
    {
        $this->authorize($store, $actor, 'personalization.smart_cart.manage');
        $setting = $this->smartCartSetting($store);
        if ($setting->compatibility_status !== PersonalizationSmartCartCompatibilityStatus::Compatible
            || ! $setting->compatibility_checked_at
            || $setting->compatibility_checked_at->lt(now()->subDays(7))) {
            throw new PersonalizationException(
                'SMART_CART_COMPATIBILITY_REQUIRED',
                'Smart Cart 需要最近 7 天内通过兼容性检查。',
                409,
            );
        }
        if (! $setting->preview_confirmed_at
            || $setting->preview_confirmed_at->lt($setting->compatibility_checked_at)) {
            throw new PersonalizationException(
                'SMART_CART_PREVIEW_REQUIRED',
                '必须先确认桌面和移动端预览。',
                409,
            );
        }
        $strategy = $setting->strategy;
        if (! $strategy || (int) $strategy->store_id !== (int) $store->id) {
            throw new PersonalizationException('SMART_CART_STRATEGY_REQUIRED', 'Smart Cart 尚未选择有效推荐策略。', 409);
        }
        if ($strategy->algorithm === PersonalizationAlgorithm::Manual
            && ! $strategy->productOverrides()->where('type', PersonalizationProductOverrideType::Manual->value)->exists()
            && ! $strategy->rules()->where('type', PersonalizationRuleType::IncludeCollections->value)->where('enabled', true)->exists()) {
            throw new PersonalizationException('MANUAL_PRODUCTS_REQUIRED', '手动推荐策略至少需要一个商品或集合。', 409);
        }

        DB::transaction(function () use ($setting, $strategy, $actor): void {
            $strategy->forceFill(['enabled' => true, 'updated_by' => $actor->id])->save();
            $setting->forceFill([
                'enabled' => true,
                'enabled_at' => now(),
                'disabled_at' => null,
                'fallback_mode' => 'shopify_default',
                'updated_by' => $actor->id,
            ])->save();
        });
        $this->audit($store, $actor, $setting, 'personalization_smart_cart_activated', [
            'theme_id' => $setting->theme_id,
            'strategy_uuid' => $strategy->uuid,
        ]);

        return $setting->refresh();
    }

    public function restoreShopifyCart(Store $store, User $actor): PersonalizationSmartCartSetting
    {
        $this->authorize($store, $actor, 'personalization.smart_cart.manage');
        $setting = $this->smartCartSetting($store);
        $setting->forceFill([
            'enabled' => false,
            'enabled_at' => null,
            'disabled_at' => now(),
            'fallback_mode' => 'shopify_default',
            'updated_by' => $actor->id,
        ])->save();
        $this->audit($store, $actor, $setting, 'personalization_smart_cart_restored', [
            'theme_id' => $setting->theme_id,
            'fallback_mode' => 'shopify_default',
        ]);

        return $setting->refresh();
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

    private function smartCartSetting(Store $store): PersonalizationSmartCartSetting
    {
        $setting = PersonalizationSmartCartSetting::query()
            ->where('organization_id', $store->organization_id)
            ->where('store_id', $store->id)
            ->with(['strategy.productOverrides'])
            ->first();
        if (! $setting) {
            throw new PersonalizationException('SMART_CART_DRAFT_REQUIRED', '请先保存 Smart Cart 草稿。', 409);
        }

        return $setting;
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
        $allowed = ['text_color', 'background_color', 'button_color', 'button_text_color', 'border_radius', 'gap'];
        if (array_diff(array_keys($tokens), $allowed) !== []) {
            throw new PersonalizationException('INVALID_STYLE_TOKENS', '样式包含不支持的字段。');
        }
        foreach (['text_color', 'background_color', 'button_color', 'button_text_color'] as $colorKey) {
            if (isset($tokens[$colorKey])
                && (! is_string($tokens[$colorKey]) || preg_match('/^#[0-9a-fA-F]{6}$/', $tokens[$colorKey]) !== 1)) {
                throw new PersonalizationException('INVALID_STYLE_TOKENS', '颜色值格式无效。');
            }
        }
        foreach (['border_radius', 'gap'] as $sizeKey) {
            if (isset($tokens[$sizeKey])) {
                $tokens[$sizeKey] = $this->integer($tokens[$sizeKey], 0, 48, 'INVALID_STYLE_TOKENS');
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
