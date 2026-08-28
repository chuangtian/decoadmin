<?php

namespace App\Http\Controllers;

use App\Enums\PersonalizationAlgorithm;
use App\Enums\PersonalizationPlacement;
use App\Exceptions\PersonalizationException;
use App\Models\Organization;
use App\Models\PersonalizationRecommendationComponent;
use App\Models\PersonalizationRecommendationStrategy;
use App\Models\Store;
use App\Services\Personalization\PersonalizationCatalogService;
use App\Services\Personalization\PersonalizationConfigurationService;
use App\Services\Personalization\PersonalizationRecommendationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PersonalizationController extends Controller
{
    public function __construct(
        private PersonalizationConfigurationService $configuration,
        private PersonalizationRecommendationService $recommendations,
        private PersonalizationCatalogService $catalog,
    ) {}

    public function index(Request $request, Organization $organization, Store $store): Response
    {
        $this->assertUserScope($request, $organization, $store, 'personalization.view');
        try {
            $configuration = $this->configuration->configuration($store, $request->user());
        } catch (PersonalizationException $exception) {
            abort($exception->statusCode, $exception->getMessage());
        }
        $products = $this->catalog->candidates($store, ['in_stock_only' => false, 'limit' => 100]);

        return Inertia::render('Personalization/Index', [
            'organization' => ['id' => $organization->id, 'name' => $organization->name],
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
                'shopify_domain' => $store->shopify_domain,
                'currency' => $store->currency,
            ],
            'strategies' => $configuration['strategies']->map(fn ($strategy): array => [
                'uuid' => $strategy->uuid,
                'name' => $strategy->name,
                'algorithm' => $strategy->algorithm->value,
                'enabled' => $strategy->enabled,
                'item_limit' => $strategy->item_limit,
                'rules' => $strategy->rules->map(fn ($rule): array => [
                    'type' => $rule->type->value,
                    'value' => $rule->value,
                    'enabled' => $rule->enabled,
                ])->values(),
                'product_overrides' => $strategy->productOverrides->map(fn ($override): array => [
                    'shopify_product_id' => (string) $override->shopify_product_id,
                    'type' => $override->type->value,
                    'position' => $override->position,
                ])->values(),
            ])->values(),
            'components' => $configuration['components']->map(fn ($component): array => [
                'uuid' => $component->uuid,
                'strategy_uuid' => $component->strategy?->uuid,
                'strategy_name' => $component->strategy?->name,
                'name' => $component->name,
                'placement' => $component->placement->value,
                'status' => $component->status->value,
                'heading' => $component->heading,
                'button_label' => $component->button_label,
                'published_at' => $component->published_at?->toIso8601String(),
                'style' => [
                    'layout' => $component->style?->layout ?? 'carousel',
                    'desktop_columns' => $component->style?->desktop_columns ?? 4,
                    'mobile_columns' => $component->style?->mobile_columns ?? 2,
                    'show_image' => $component->style?->show_image ?? true,
                    'show_vendor' => $component->style?->show_vendor ?? false,
                    'show_price' => $component->style?->show_price ?? true,
                    'show_compare_at_price' => $component->style?->show_compare_at_price ?? true,
                    'show_add_to_cart' => $component->style?->show_add_to_cart ?? true,
                    'tokens' => $component->style?->tokens ?? [],
                ],
            ])->values(),
            'smartCart' => $configuration['smart_cart'] ? [
                'uuid' => $configuration['smart_cart']->uuid,
                'strategy_uuid' => $configuration['smart_cart']->strategy?->uuid,
                'enabled' => $configuration['smart_cart']->enabled,
                'compatibility_status' => $configuration['smart_cart']->compatibility_status->value,
                'fallback_mode' => $configuration['smart_cart']->fallback_mode,
                'settings' => $configuration['smart_cart']->settings ?? [],
            ] : null,
            'products' => $products->map(fn (array $product): array => [
                'shopify_product_id' => $product['shopify_product_id'],
                'shopify_gid' => 'gid://shopify/Product/'.$product['shopify_product_id'],
                'title' => $product['title'],
                'handle' => $product['handle'],
                'image_url' => data_get($product, 'storefront.image.url'),
                'price' => data_get($product, 'price.minimum'),
                'currency' => data_get($product, 'price.currency'),
                'tags' => $product['tags'],
            ])->values(),
            'options' => [
                'algorithms' => array_map(fn (PersonalizationAlgorithm $algorithm): array => [
                    'value' => $algorithm->value,
                    'label' => $this->algorithmLabel($algorithm),
                ], PersonalizationAlgorithm::cases()),
                'placements' => array_map(fn (PersonalizationPlacement $placement): array => [
                    'value' => $placement->value,
                    'label' => $this->placementLabel($placement),
                ], PersonalizationPlacement::cases()),
            ],
            'permissions' => [
                'manage' => $request->user()->hasPermission('personalization.manage', $organization, $store),
                'manageSmartCart' => $request->user()->hasPermission('personalization.smart_cart.manage', $organization, $store),
                'viewAnalytics' => $request->user()->hasPermission('personalization.analytics.read', $organization, $store),
            ],
            'analytics' => [
                'status' => 'pending_event_collection',
                'impressions' => 0,
                'clicks' => 0,
                'add_to_carts' => 0,
                'orders' => 0,
                'attributed_revenue' => '0.00',
                'aov' => '0.00',
            ],
        ]);
    }

    public function storeStrategy(Request $request, Organization $organization, Store $store): RedirectResponse
    {
        $this->assertUserScope($request, $organization, $store, 'personalization.manage');
        $values = $request->validate($this->strategyRules());

        return $this->run(fn () => $this->configuration->createStrategy($store, $request->user(), $values), '推荐策略已创建。');
    }

    public function updateStrategy(
        Request $request,
        Organization $organization,
        Store $store,
        PersonalizationRecommendationStrategy $strategy,
    ): RedirectResponse {
        $this->assertUserScope($request, $organization, $store, 'personalization.manage');
        $values = $request->validate($this->strategyRules());

        return $this->run(fn () => $this->configuration->updateStrategy($store, $strategy, $request->user(), $values), '推荐策略已保存，相关组件已退回草稿。');
    }

    public function updateRules(
        Request $request,
        Organization $organization,
        Store $store,
        PersonalizationRecommendationStrategy $strategy,
    ): RedirectResponse {
        $this->assertUserScope($request, $organization, $store, 'personalization.manage');
        $values = $request->validate([
            'include_tags' => ['array', 'max:50'],
            'include_tags.*' => ['string', 'max:100', 'distinct'],
            'exclude_tags' => ['array', 'max:50'],
            'exclude_tags.*' => ['string', 'max:100', 'distinct'],
            'minimum_price' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'maximum_price' => ['nullable', 'numeric', 'min:0', 'max:999999999', 'gte:minimum_price'],
            'minimum_inventory' => ['nullable', 'integer', 'between:0,1000000'],
            'in_stock_only' => ['required', 'boolean'],
        ]);
        $rules = array_values(array_filter([
            ($values['include_tags'] ?? []) === [] ? null : ['type' => 'include_tags', 'value' => $values['include_tags']],
            ($values['exclude_tags'] ?? []) === [] ? null : ['type' => 'exclude_tags', 'value' => $values['exclude_tags']],
            ($values['minimum_price'] ?? null) === null ? null : ['type' => 'minimum_price', 'value' => $values['minimum_price']],
            ($values['maximum_price'] ?? null) === null ? null : ['type' => 'maximum_price', 'value' => $values['maximum_price']],
            ($values['minimum_inventory'] ?? null) === null ? null : ['type' => 'minimum_inventory', 'value' => $values['minimum_inventory']],
            ['type' => 'in_stock_only', 'value' => $values['in_stock_only']],
        ]));

        return $this->run(fn () => $this->configuration->replaceRules($store, $strategy, $request->user(), $rules), '推荐规则已保存，相关组件已退回草稿。');
    }

    public function updateProducts(
        Request $request,
        Organization $organization,
        Store $store,
        PersonalizationRecommendationStrategy $strategy,
    ): RedirectResponse {
        $this->assertUserScope($request, $organization, $store, 'personalization.manage');
        $values = $request->validate([
            'manual' => ['array', 'max:500'],
            'manual.*' => ['string', 'distinct', 'regex:/^gid:\/\/shopify\/Product\/\d+$/'],
            'pinned' => ['array', 'max:500'],
            'pinned.*' => ['string', 'distinct', 'regex:/^gid:\/\/shopify\/Product\/\d+$/'],
            'excluded' => ['array', 'max:500'],
            'excluded.*' => ['string', 'distinct', 'regex:/^gid:\/\/shopify\/Product\/\d+$/'],
        ]);
        $overrides = [];
        foreach (['manual', 'pinned', 'excluded'] as $type) {
            foreach ($values[$type] ?? [] as $shopifyProductId) {
                $overrides[] = ['shopify_product_id' => $shopifyProductId, 'type' => $type];
            }
        }

        return $this->run(fn () => $this->configuration->replaceProductOverrides($store, $strategy, $request->user(), $overrides), '推荐商品已保存，相关组件已退回草稿。');
    }

    public function storeComponent(Request $request, Organization $organization, Store $store): RedirectResponse
    {
        $this->assertUserScope($request, $organization, $store, 'personalization.manage');
        $values = $request->validate($this->componentRules());
        $strategy = $this->ownedStrategy($store, $values['strategy_uuid']);

        return $this->run(fn () => $this->configuration->createComponent($store, $strategy, $request->user(), $values), '推荐组件草稿已创建。');
    }

    public function updateComponent(
        Request $request,
        Organization $organization,
        Store $store,
        PersonalizationRecommendationComponent $component,
    ): RedirectResponse {
        $this->assertUserScope($request, $organization, $store, 'personalization.manage');
        $values = $request->validate($this->componentRules());
        $strategy = $this->ownedStrategy($store, $values['strategy_uuid']);

        return $this->run(fn () => $this->configuration->updateComponent($store, $component, $strategy, $request->user(), $values), '推荐组件已保存为草稿。');
    }

    public function updateStyle(
        Request $request,
        Organization $organization,
        Store $store,
        PersonalizationRecommendationComponent $component,
    ): RedirectResponse {
        $this->assertUserScope($request, $organization, $store, 'personalization.manage');
        $values = $request->validate([
            'layout' => ['required', Rule::in(['carousel', 'grid'])],
            'desktop_columns' => ['required', 'integer', 'between:1,6'],
            'mobile_columns' => ['required', 'integer', 'between:1,3'],
            'show_image' => ['required', 'boolean'],
            'show_vendor' => ['required', 'boolean'],
            'show_price' => ['required', 'boolean'],
            'show_compare_at_price' => ['required', 'boolean'],
            'show_add_to_cart' => ['required', 'boolean'],
            'tokens' => ['array'],
            'tokens.text_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'tokens.background_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'tokens.button_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'tokens.button_text_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'tokens.border_radius' => ['nullable', 'integer', 'between:0,48'],
            'tokens.gap' => ['nullable', 'integer', 'between:0,48'],
        ]);

        return $this->run(fn () => $this->configuration->updateStyle($store, $component, $request->user(), $values), '组件样式已保存，启用前请重新预览。');
    }

    public function activateComponent(
        Request $request,
        Organization $organization,
        Store $store,
        PersonalizationRecommendationComponent $component,
    ): RedirectResponse {
        $this->assertUserScope($request, $organization, $store, 'personalization.manage');

        return $this->run(fn () => $this->configuration->activateComponent($store, $component, $request->user()), '组件后端配置已启用；主题区块仍需在后续 Test 联调中单独启用。');
    }

    public function disableComponent(
        Request $request,
        Organization $organization,
        Store $store,
        PersonalizationRecommendationComponent $component,
    ): RedirectResponse {
        $this->assertUserScope($request, $organization, $store, 'personalization.manage');

        return $this->run(fn () => $this->configuration->disableComponent($store, $component, $request->user()), '组件后端配置已停用。');
    }

    public function preview(
        Request $request,
        Organization $organization,
        Store $store,
        PersonalizationRecommendationComponent $component,
    ): JsonResponse {
        $this->assertUserScope($request, $organization, $store, 'personalization.view');
        $values = $request->validate([
            'seed_product_id' => ['nullable', 'string', 'max:255'],
            'cart_product_ids' => ['array', 'max:20'],
            'cart_product_ids.*' => ['string', 'max:255'],
            'recently_viewed_product_ids' => ['array', 'max:50'],
            'recently_viewed_product_ids.*' => ['string', 'max:255'],
        ]);
        try {
            return response()->json(['data' => $this->recommendations->forComponent($store, $component, $values, true)]);
        } catch (PersonalizationException $exception) {
            return response()->json(['error' => [
                'code' => $exception->errorCode,
                'message' => $exception->getMessage(),
            ]], $exception->statusCode);
        }
    }

    public function saveSmartCartDraft(Request $request, Organization $organization, Store $store): RedirectResponse
    {
        $this->assertUserScope($request, $organization, $store, 'personalization.smart_cart.manage');
        $values = $request->validate([
            'strategy_uuid' => ['nullable', 'uuid'],
            'heading' => ['nullable', 'string', 'max:120'],
        ]);
        $strategy = filled($values['strategy_uuid'] ?? null)
            ? $this->ownedStrategy($store, $values['strategy_uuid'])
            : null;

        return $this->run(fn () => $this->configuration->saveSmartCartDraft($store, $request->user(), $strategy, [
            'heading' => trim((string) ($values['heading'] ?? '')),
        ]), 'Smart Cart 草稿已保存，仍保持关闭。');
    }

    private function assertUserScope(Request $request, Organization $organization, Store $store, string $permission): void
    {
        abort_unless($store->organization_id === $organization->id, 403);
        abort_unless($request->user()->canAccessStore($store), 403);
        abort_unless($request->user()->hasPermission($permission, $organization, $store), 403);
    }

    /** @return array<string, array<int, mixed>> */
    private function strategyRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80', 'regex:/\S/u'],
            'algorithm' => ['required', Rule::in(array_column(PersonalizationAlgorithm::cases(), 'value'))],
            'item_limit' => ['required', 'integer', 'between:1,50'],
        ];
    }

    /** @return array<string, array<int, mixed>> */
    private function componentRules(): array
    {
        return [
            'strategy_uuid' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:80', 'regex:/\S/u'],
            'placement' => ['required', Rule::in(array_column(PersonalizationPlacement::cases(), 'value'))],
            'heading' => ['nullable', 'string', 'max:120'],
            'button_label' => ['nullable', 'string', 'max:60'],
        ];
    }

    private function ownedStrategy(Store $store, string $uuid): PersonalizationRecommendationStrategy
    {
        return PersonalizationRecommendationStrategy::query()
            ->where('organization_id', $store->organization_id)
            ->where('store_id', $store->id)
            ->where('uuid', $uuid)
            ->firstOrFail();
    }

    private function run(callable $action, string $success): RedirectResponse
    {
        try {
            $action();
        } catch (PersonalizationException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', $success);
    }

    private function algorithmLabel(PersonalizationAlgorithm $algorithm): string
    {
        return match ($algorithm) {
            PersonalizationAlgorithm::Manual => '手动推荐',
            PersonalizationAlgorithm::BestSeller => '畅销商品',
            PersonalizationAlgorithm::NewArrivals => '新品',
            PersonalizationAlgorithm::FrequentlyBoughtTogether => '经常一起购买',
            PersonalizationAlgorithm::RecentlyViewed => '最近浏览',
            PersonalizationAlgorithm::SimilarProducts => '相似商品',
        };
    }

    private function placementLabel(PersonalizationPlacement $placement): string
    {
        return match ($placement) {
            PersonalizationPlacement::Homepage => '首页',
            PersonalizationPlacement::ProductPage => '商品页',
            PersonalizationPlacement::CartPage => '购物车页面',
            PersonalizationPlacement::SmartCart => 'Smart Cart',
        };
    }
}
