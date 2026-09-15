<?php

namespace App\Http\Controllers;

use App\Enums\PersonalizationAlgorithm;
use App\Enums\PersonalizationPlacement;
use App\Exceptions\PersonalizationException;
use App\Models\Organization;
use App\Models\PersonalizationRecommendationComponent;
use App\Models\PersonalizationRecommendationStrategy;
use App\Models\Store;
use App\Services\Personalization\PersonalizationAnalyticsService;
use App\Services\Personalization\PersonalizationCatalogService;
use App\Services\Personalization\PersonalizationCheckoutService;
use App\Services\Personalization\PersonalizationConfigurationService;
use App\Services\Personalization\PersonalizationGlobalSettingsService;
use App\Services\Personalization\PersonalizationRecommendationService;
use App\Services\Personalization\PersonalizationStrategyWorkflowService;
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
        private PersonalizationAnalyticsService $analytics,
        private PersonalizationCheckoutService $checkout,
        private PersonalizationStrategyWorkflowService $strategyWorkflow,
        private PersonalizationGlobalSettingsService $globalSettings,
    ) {}

    public function index(Request $request, Organization $organization, Store $store): Response
    {
        $this->assertUserScope($request, $organization, $store, 'personalization.view');
        try {
            $configuration = $this->configuration->configuration($store, $request->user());
        } catch (PersonalizationException $exception) {
            abort($exception->statusCode, $exception->getMessage());
        }
        $products = $this->catalog->pickerProducts($store);
        $collections = $this->catalog->collections($store);
        $canViewAnalytics = $request->user()->hasPermission('personalization.analytics.read', $organization, $store);
        $timezone = $store->timezone ?: 'UTC';
        $today = now($timezone)->toDateString();
        $earliest = now($timezone)->subDays(89)->toDateString();
        $analyticsFilters = $request->validate([
            'from' => ['nullable', 'required_with:to', 'date_format:Y-m-d', "after_or_equal:{$earliest}", "before_or_equal:{$today}"],
            'to' => ['nullable', 'required_with:from', 'date_format:Y-m-d', 'after_or_equal:from', "before_or_equal:{$today}"],
        ]);
        try {
            $analytics = $canViewAnalytics
                ? $this->analytics->dashboard(
                    $store,
                    $request->user(),
                    from: $analyticsFilters['from'] ?? null,
                    to: $analyticsFilters['to'] ?? null,
                )
                : $this->emptyAnalytics($store);
        } catch (PersonalizationException $exception) {
            abort($exception->statusCode, $exception->getMessage());
        }
        try {
            $checkout = $this->checkout->configuration($store, $request->user());
        } catch (PersonalizationException $exception) {
            abort($exception->statusCode, $exception->getMessage());
        }
        try {
            $strategyRows = $this->strategyWorkflow->listing($store, $request->user());
            $globalSettings = $this->globalSettings->configuration($store, $request->user());
        } catch (PersonalizationException $exception) {
            abort($exception->statusCode, $exception->getMessage());
        }

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
                    'minimum_quantity' => $override->minimum_quantity,
                ])->values(),
            ])->values(),
            'strategyRows' => $strategyRows,
            'globalSettings' => $globalSettings,
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
                'compatibility_details' => $configuration['smart_cart']->compatibility_details ?? [],
                'compatibility_checked_at' => $configuration['smart_cart']->compatibility_checked_at?->toIso8601String(),
                'theme_id' => $configuration['smart_cart']->theme_id,
                'theme_name' => $configuration['smart_cart']->theme_name,
                'preview_confirmed_at' => $configuration['smart_cart']->preview_confirmed_at?->toIso8601String(),
                'enabled_at' => $configuration['smart_cart']->enabled_at?->toIso8601String(),
                'fallback_mode' => $configuration['smart_cart']->fallback_mode,
                'settings' => $configuration['smart_cart']->settings ?? [],
            ] : null,
            'products' => $products->map(fn (array $product): array => [
                'shopify_product_id' => $product['shopify_product_id'],
                'shopify_gid' => 'gid://shopify/Product/'.$product['shopify_product_id'],
                'title' => $product['title'],
                'handle' => $product['handle'],
                'vendor' => $product['vendor'],
                'image_url' => data_get($product, 'storefront.image.url'),
                'price' => data_get($product, 'price.minimum'),
                'currency' => data_get($product, 'price.currency'),
                'status' => $product['status'],
                'available_for_sale' => $product['available_for_sale'],
                'availability_label' => $product['availability_label'],
                'tags' => $product['tags'],
                'collection_ids' => collect($product['collections'] ?? [])->pluck('shopify_collection_id')->values(),
                'variants' => collect($product['variants'] ?? [])->map(fn (array $variant): array => [
                    'shopify_variant_id' => $variant['shopify_variant_id'],
                    'shopify_gid' => 'gid://shopify/ProductVariant/'.$variant['shopify_variant_id'],
                    'title' => $variant['title'],
                    'sku' => $variant['sku'],
                    'price' => $variant['price'],
                    'available_for_sale' => $variant['available_for_sale'],
                    'selected_options' => $variant['selected_options'],
                ])->values(),
            ])->values(),
            'collections' => $collections,
            'checkout' => [
                'uuid' => $checkout?->uuid,
                'strategy_uuid' => $checkout?->component?->strategy?->uuid,
                'thank_you' => [
                    'uuid' => $checkout?->thankYouComponent?->uuid,
                    'strategy_uuid' => $checkout?->thankYouComponent?->strategy?->uuid,
                    'heading' => $checkout?->thankYouComponent?->heading ?: 'Great Value Bundles for You',
                    'enabled' => $checkout?->thankYouComponent?->status?->value === 'active',
                ],
                'order_status' => [
                    'uuid' => $checkout?->orderStatusComponent?->uuid,
                    'strategy_uuid' => $checkout?->orderStatusComponent?->strategy?->uuid,
                    'heading' => $checkout?->orderStatusComponent?->heading ?: 'Great Value Bundles for You',
                    'enabled' => $checkout?->orderStatusComponent?->status?->value === 'active',
                ],
                'trust_items' => $checkout?->trust_items ?? $this->checkout->defaultTrustItems(),
                'icon_options' => array_map(fn (string $icon): array => [
                    'value' => $icon,
                    'label' => $this->checkoutIconLabel($icon),
                ], PersonalizationCheckoutService::ICONS),
            ],
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
                'viewAnalytics' => $canViewAnalytics,
            ],
            'analytics' => $analytics,
        ]);
    }

    public function storeStrategy(Request $request, Organization $organization, Store $store): RedirectResponse
    {
        $this->assertUserScope($request, $organization, $store, 'personalization.manage');
        $values = $request->validate($this->strategyRules());

        return $this->run(fn () => $this->configuration->createStrategy($store, $request->user(), $values), 'Recommendation strategy created.');
    }

    public function updateStrategy(
        Request $request,
        Organization $organization,
        Store $store,
        PersonalizationRecommendationStrategy $strategy,
    ): RedirectResponse {
        $this->assertUserScope($request, $organization, $store, 'personalization.manage');
        $values = $request->validate($this->strategyRules());

        return $this->run(fn () => $this->configuration->updateStrategy($store, $strategy, $request->user(), $values), 'Recommendation strategy saved. Assigned components returned to draft.');
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

        return $this->run(fn () => $this->configuration->replaceRules($store, $strategy, $request->user(), $rules), 'Recommendation rules saved. Assigned components returned to draft.');
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

        return $this->run(fn () => $this->configuration->replaceProductOverrides($store, $strategy, $request->user(), $overrides), 'Recommended products saved. Assigned components returned to draft.');
    }

    public function storeComponent(Request $request, Organization $organization, Store $store): RedirectResponse
    {
        $this->assertUserScope($request, $organization, $store, 'personalization.manage');
        $values = $request->validate($this->componentRules());
        $strategy = $this->ownedStrategy($store, $values['strategy_uuid']);

        return $this->run(fn () => $this->configuration->createComponent($store, $strategy, $request->user(), $values), 'Recommendation component draft created.');
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

        return $this->run(fn () => $this->configuration->updateComponent($store, $component, $strategy, $request->user(), $values), 'Recommendation component saved as a draft.');
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

        return $this->run(fn () => $this->configuration->updateStyle($store, $component, $request->user(), $values), 'Component style saved. Preview it again before enabling.');
    }

    public function activateComponent(
        Request $request,
        Organization $organization,
        Store $store,
        PersonalizationRecommendationComponent $component,
    ): RedirectResponse {
        $this->assertUserScope($request, $organization, $store, 'personalization.manage');

        return $this->run(fn () => $this->configuration->activateComponent($store, $component, $request->user()), 'Component configuration enabled. Enable the theme block separately during Test integration.');
    }

    public function disableComponent(
        Request $request,
        Organization $organization,
        Store $store,
        PersonalizationRecommendationComponent $component,
    ): RedirectResponse {
        $this->assertUserScope($request, $organization, $store, 'personalization.manage');

        return $this->run(fn () => $this->configuration->disableComponent($store, $component, $request->user()), 'Component configuration disabled.');
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

    public function saveSmartCart(Request $request, Organization $organization, Store $store): RedirectResponse
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
        ]), $strategy
            ? 'Smart Cart strategy saved and assigned to the native cart drawer.'
            : 'Smart Cart strategy cleared. The native cart will not show recommendations.');
    }

    public function saveCheckout(Request $request, Organization $organization, Store $store): RedirectResponse
    {
        $this->assertUserScope($request, $organization, $store, 'personalization.manage');
        $values = $request->validate([
            'strategy_uuid' => ['required', 'uuid'],
            'trust_items' => ['array', 'max:'.PersonalizationCheckoutService::MAX_TRUST_ITEMS],
            'trust_items.*.key' => ['required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/'],
            'trust_items.*.icon' => ['required', Rule::in(PersonalizationCheckoutService::ICONS)],
            'trust_items.*.title' => ['nullable', 'string', 'max:80'],
            'trust_items.*.description' => ['nullable', 'string', 'max:120'],
            'trust_items.*.enabled' => ['required', 'boolean'],
        ]);

        return $this->run(
            fn () => $this->checkout->save($store, $request->user(), $values),
            'Checkout strategy assigned. The block will use it automatically after it is added in Shopify Checkout Editor.',
        );
    }

    public function saveThankYou(Request $request, Organization $organization, Store $store): RedirectResponse
    {
        $this->assertUserScope($request, $organization, $store, 'personalization.manage');
        $values = $request->validate([
            'strategy_uuid' => ['required', 'uuid'],
            'heading' => ['nullable', 'string', 'max:120'],
        ]);

        return $this->run(
            fn () => $this->checkout->saveThankYou($store, $request->user(), $values),
            'Thank you page recommendation settings saved. The Deco recommendation block will use this strategy.',
        );
    }

    public function saveOrderStatus(Request $request, Organization $organization, Store $store): RedirectResponse
    {
        $this->assertUserScope($request, $organization, $store, 'personalization.manage');
        $values = $request->validate([
            'strategy_uuid' => ['required', 'uuid'],
            'heading' => ['nullable', 'string', 'max:120'],
        ]);

        return $this->run(
            fn () => $this->checkout->saveOrderStatus($store, $request->user(), $values),
            'Order status page recommendation settings saved. The Deco recommendation block will use this strategy.',
        );
    }

    private function assertUserScope(Request $request, Organization $organization, Store $store, string $permission): void
    {
        abort_unless($store->organization_id === $organization->id, 403);
        abort_unless($request->user()->canAccessStore($store), 403);
        abort_unless($request->user()->hasPermission($permission, $organization, $store), 403);
    }

    /** @return array<string, mixed> */
    private function emptyAnalytics(Store $store): array
    {
        $today = now($store->timezone ?: 'UTC')->toDateString();

        return [
            'status' => 'not_authorized',
            'period' => ['days' => 30, 'from' => $today, 'to' => $today, 'timezone' => $store->timezone ?: 'UTC'],
            'currency' => strtoupper((string) ($store->currency ?: 'USD')),
            'impressions' => 0,
            'clicks' => 0,
            'add_to_carts' => 0,
            'orders' => 0,
            'attributed_revenue' => '0.00',
            'aov' => '0.00',
            'quantity' => 0,
            'sales' => '0.00',
            'discounts' => '0.00',
            'revenue' => '0.00',
            'click_through_rate' => 0.0,
            'add_to_cart_rate' => 0.0,
            'reversed_orders' => 0,
            'excluded_currency_orders' => 0,
            'attribution' => [
                'model' => 'last_recommendation_click',
                'window_days' => 7,
                'click_only' => true,
                'refund_cancel_reversal' => true,
            ],
            'daily' => [],
            'placements' => [],
            'dimensions' => [],
        ];
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
            PersonalizationAlgorithm::Manual => 'Manual recommendations',
            PersonalizationAlgorithm::NextLlm => 'Next LLM (intelligent blend)',
            PersonalizationAlgorithm::FreeShippingUpsell => 'Free-shipping upsell',
            PersonalizationAlgorithm::SimilarProducts => 'Similar products',
            PersonalizationAlgorithm::SubstituteProducts => 'Substitute products',
            PersonalizationAlgorithm::BestSeller => 'Best sellers',
            PersonalizationAlgorithm::NewArrivals => 'New arrivals',
            PersonalizationAlgorithm::FrequentlyBoughtTogether => 'Frequently bought together',
            PersonalizationAlgorithm::FrequentlyViewedTogether => 'Frequently viewed together',
            PersonalizationAlgorithm::ComplementaryProducts => 'Complementary products',
            PersonalizationAlgorithm::RecentlyViewed => 'Recently viewed',
            PersonalizationAlgorithm::CompleteTheLook => 'Complete the look',
            PersonalizationAlgorithm::SameProductUpsell => 'Same-product upsell',
            PersonalizationAlgorithm::AllProducts => 'All products',
        };
    }

    private function placementLabel(PersonalizationPlacement $placement): string
    {
        return match ($placement) {
            PersonalizationPlacement::Homepage => 'Homepage',
            PersonalizationPlacement::ProductPage => 'Product page',
            PersonalizationPlacement::CartPage => 'Cart page',
            PersonalizationPlacement::SmartCart => 'Smart Cart',
            PersonalizationPlacement::Checkout => 'Checkout',
            PersonalizationPlacement::ThankYou => 'Thank you page',
            PersonalizationPlacement::OrderStatus => 'Order status page',
        };
    }

    private function checkoutIconLabel(string $icon): string
    {
        return match ($icon) {
            'store' => 'Trusted store',
            'truck' => 'Delivery',
            'star' => 'Guarantee',
            'check-circle' => 'Verified',
            'lock' => 'Secure',
            'savings' => 'Savings',
            'delivered' => 'Delivered',
            'return' => 'Returns',
            default => 'Information',
        };
    }
}
