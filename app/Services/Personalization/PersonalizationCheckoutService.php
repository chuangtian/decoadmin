<?php

namespace App\Services\Personalization;

use App\Enums\PersonalizationComponentStatus;
use App\Enums\PersonalizationPlacement;
use App\Exceptions\PersonalizationException;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\PersonalizationCheckoutSetting;
use App\Models\PersonalizationRecommendationComponent;
use App\Models\ProductCollection;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PersonalizationCheckoutService
{
    public const COLLECTION_PAGE_SIZE = 20;

    public const MAX_TRUST_ITEMS = 6;

    /** @var list<string> */
    public const ICONS = [
        'store', 'truck', 'star', 'check-circle', 'lock', 'savings', 'delivered', 'return', 'info',
    ];

    public function __construct(
        private PersonalizationShopGuard $shopGuard,
        private PersonalizationRecommendationService $recommendations,
    ) {}

    public function configuration(Store $store, User $actor): ?PersonalizationCheckoutSetting
    {
        $this->authorize($store, $actor, 'personalization.view');

        return PersonalizationCheckoutSetting::query()
            ->where('organization_id', $store->organization_id)
            ->where('store_id', $store->id)
            ->with([
                'component.strategy',
                'collection',
            ])
            ->first();
    }

    /** @param array<string, mixed> $input */
    public function save(Store $store, User $actor, array $input): PersonalizationCheckoutSetting
    {
        $this->authorize($store, $actor, 'personalization.manage');
        $enabled = (bool) ($input['enabled'] ?? false);
        $component = $this->component($store, $input['component_uuid'] ?? null);
        $trustItems = $this->trustItems($input['trust_items'] ?? []);
        $collection = $this->collection($store, $input['shopify_collection_id'] ?? null);
        $maximumRecommendations = $this->maximumRecommendations($input['maximum_recommendations'] ?? null);

        if ($enabled) {
            if (! $component
                || $component->status !== PersonalizationComponentStatus::Active
                || ! $component->strategy?->enabled) {
                throw new PersonalizationException(
                    'CHECKOUT_COMPONENT_NOT_ACTIVE',
                    '启用 Checkout 前必须选择已启用的 Checkout 推荐组件与策略。',
                    409,
                );
            }
            if (! $collection) {
                throw new PersonalizationException(
                    'CHECKOUT_COLLECTION_REQUIRED',
                    '启用 Checkout 前必须选择一个 Shopify 商品集合。',
                    409,
                );
            }
        }

        $setting = DB::transaction(function () use ($store, $actor, $enabled, $component, $collection, $trustItems, $maximumRecommendations): PersonalizationCheckoutSetting {
            $setting = PersonalizationCheckoutSetting::query()->updateOrCreate(
                ['store_id' => $store->id],
                [
                    'organization_id' => $store->organization_id,
                    'component_id' => $component?->id,
                    'collection_id' => $collection?->id,
                    'shopify_collection_id' => $collection ? (string) $collection->shopify_collection_id : null,
                    'enabled' => $enabled,
                    'trust_items' => $trustItems,
                    'settings' => [
                        'candidate_source' => 'collection',
                        'candidate_order' => 'collection_default',
                        'variant_fallback' => 'first_available',
                        'candidate_page_size' => self::COLLECTION_PAGE_SIZE,
                        'sequence_mode' => 'sequential',
                        'sequence_exhaustion' => 'collection',
                        'hide_when_exhausted' => true,
                        'maximum_recommendations' => $maximumRecommendations,
                        'trust_placement' => 'WALLETS1',
                        'recommendation_placement' => 'ORDER_SUMMARY2',
                    ],
                    'updated_by' => $actor->id,
                ],
            );

            return $setting;
        });

        AuditLog::query()->create([
            'organization_id' => $store->organization_id,
            'store_id' => $store->id,
            'user_id' => $actor->id,
            'action' => 'personalization_checkout_configuration_saved',
            'subject_type' => $setting->getMorphClass(),
            'subject_id' => $setting->id,
            'new_values' => [
                'enabled' => $enabled,
                'component_uuid' => $component?->uuid,
                'shopify_collection_id' => $collection?->shopify_collection_id,
                'sequence_exhaustion' => 'collection',
                'maximum_recommendations' => $maximumRecommendations,
                'trust_item_count' => count(array_filter($trustItems, fn (array $item): bool => $item['enabled'])),
            ],
        ]);

        return $setting->load(['component.strategy', 'collection']);
    }

    /** @return array<string, mixed> */
    public function storefront(Store $store): array
    {
        $setting = $this->activeSetting($store);
        $component = $setting?->component;
        if (! $setting || ! $component) {
            return $this->disabledPayload();
        }

        return [
            'enabled' => true,
            'component' => [
                'uuid' => $component->uuid,
                'strategy_uuid' => $component->strategy->uuid,
                'placement' => PersonalizationPlacement::Checkout->value,
                'heading' => $component->heading ?: 'Great Value Bundles for You',
                'button_label' => $component->button_label ?: 'Add',
            ],
            'strategy' => [
                'version_uuid' => $component->strategyVersion?->uuid
                    ?? $component->strategy->publishedVersion?->uuid,
            ],
            'recommendations_url' => url('/api/shopify-app/personalization/checkout/recommendations'),
            'trust_items' => collect($setting->trust_items ?? [])
                ->filter(fn (mixed $item): bool => is_array($item) && ($item['enabled'] ?? false) === true)
                ->sortBy(fn (array $item): int => (int) ($item['position'] ?? 0))
                ->values()
                ->all(),
            'collection' => [
                'id' => 'gid://shopify/Collection/'.$setting->shopify_collection_id,
            ],
            'sequence' => [
                'mode' => 'sequential',
                'order' => 'collection_default',
                'variant_fallback' => 'first_available',
                'page_size' => min(250, max(1, (int) data_get(
                    $setting->settings,
                    'candidate_page_size',
                    data_get($setting->settings, 'candidate_scan_limit', self::COLLECTION_PAGE_SIZE),
                ))),
                'exhaustion' => 'collection',
                'hide_when_exhausted' => true,
                'maximum_recommendations' => data_get($setting->settings, 'maximum_recommendations'),
            ],
        ];
    }

    /** @param array<string, mixed> $context @return array<string, mixed> */
    public function recommendations(Store $store, array $context): array
    {
        $setting = $this->activeSetting($store);
        if (! $setting?->component) {
            return ['enabled' => false, 'items' => [], 'debug' => ['diagnostics' => [['code' => 'checkout_not_enabled']]]];
        }

        return [
            'enabled' => true,
            ...$this->recommendations->forComponent($store, $setting->component, [
                ...$context,
                'surface' => PersonalizationPlacement::Checkout->value,
                'placement' => PersonalizationPlacement::Checkout->value,
            ]),
        ];
    }

    private function activeSetting(Store $store): ?PersonalizationCheckoutSetting
    {
        $this->assertStore($store);
        $setting = PersonalizationCheckoutSetting::query()
            ->where('organization_id', $store->organization_id)
            ->where('store_id', $store->id)
            ->where('enabled', true)
            ->with([
                'component.strategy.publishedVersion',
                'component.strategyVersion',
                'collection',
            ])->first();
        $component = $setting?->component;
        if (! $setting
            || ! $component
            || ! $setting->collection
            || (int) $setting->collection->organization_id !== (int) $store->organization_id
            || (int) $setting->collection->store_id !== (int) $store->id
            || (string) $setting->collection->shopify_collection_id !== (string) $setting->shopify_collection_id
            || $component->placement !== PersonalizationPlacement::Checkout
            || $component->status !== PersonalizationComponentStatus::Active
            || ! $component->strategy?->enabled) {
            return null;
        }

        return $setting;
    }

    /** @return list<array{key: string, icon: string, title: string, description: string, position: int, enabled: bool}> */
    public function defaultTrustItems(): array
    {
        return [
            ['key' => 'trusted_seller', 'icon' => 'store', 'title' => 'Trusted seller', 'description' => '', 'position' => 1, 'enabled' => true],
            ['key' => 'free_shipping', 'icon' => 'truck', 'title' => 'Free US Shipping', 'description' => 'over $100', 'position' => 2, 'enabled' => true],
            ['key' => 'warranty', 'icon' => 'star', 'title' => '2-year warranty', 'description' => '', 'position' => 3, 'enabled' => true],
        ];
    }

    /** @return list<array{key: string, icon: string, title: string, description: string, position: int, enabled: bool}> */
    private function trustItems(mixed $items): array
    {
        if (! is_array($items) || count($items) > self::MAX_TRUST_ITEMS) {
            throw new PersonalizationException('INVALID_CHECKOUT_TRUST_ITEMS', 'Checkout 信任信息最多配置 6 项。');
        }

        $normalized = [];
        $keys = [];
        foreach (array_values($items) as $position => $item) {
            if (! is_array($item) || array_is_list($item)) {
                throw new PersonalizationException('INVALID_CHECKOUT_TRUST_ITEMS', 'Checkout 信任信息格式无效。');
            }
            $key = strtolower(trim((string) ($item['key'] ?? '')));
            $icon = trim((string) ($item['icon'] ?? ''));
            $title = trim((string) ($item['title'] ?? ''));
            $description = trim((string) ($item['description'] ?? ''));
            $enabled = (bool) ($item['enabled'] ?? false);
            if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $key) !== 1
                || isset($keys[$key])
                || ! in_array($icon, self::ICONS, true)
                || mb_strlen($title) > 80
                || mb_strlen($description) > 120
                || ($enabled && $title === '')) {
                throw new PersonalizationException('INVALID_CHECKOUT_TRUST_ITEMS', 'Checkout 信任信息字段无效或重复。');
            }
            $keys[$key] = true;
            $normalized[] = [
                'key' => $key,
                'icon' => $icon,
                'title' => $title,
                'description' => $description,
                'position' => $position + 1,
                'enabled' => $enabled,
            ];
        }

        return $normalized;
    }

    private function collection(Store $store, mixed $id): ?ProductCollection
    {
        $id = trim((string) $id);
        if ($id === '') {
            return null;
        }
        if (preg_match('#^(?:gid://shopify/Collection/)?(\d+)$#', $id, $matches) !== 1) {
            throw new PersonalizationException('INVALID_CHECKOUT_COLLECTION', 'Checkout 商品集合标识无效。');
        }

        $collection = ProductCollection::query()
            ->where('organization_id', $store->organization_id)
            ->where('store_id', $store->id)
            ->where('shopify_collection_id', $matches[1])
            ->first();
        if (! $collection) {
            throw new PersonalizationException('CHECKOUT_COLLECTION_NOT_FOUND', '所选 Shopify 商品集合不属于当前店铺或尚未同步。', 404);
        }

        return $collection;
    }

    private function maximumRecommendations(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1 || (int) $value > 1000) {
            throw new PersonalizationException('INVALID_CHECKOUT_MAXIMUM', 'Checkout 最大推荐数量必须在 1 到 1000 之间，留空表示遍历整个集合。');
        }

        return (int) $value;
    }

    private function component(Store $store, mixed $uuid): ?PersonalizationRecommendationComponent
    {
        $uuid = trim((string) $uuid);
        if ($uuid === '') {
            return null;
        }

        $component = PersonalizationRecommendationComponent::query()
            ->where('organization_id', $store->organization_id)
            ->where('store_id', $store->id)
            ->where('uuid', $uuid)
            ->with('strategy')
            ->first();
        if (! $component || $component->placement !== PersonalizationPlacement::Checkout) {
            throw new PersonalizationException('CHECKOUT_COMPONENT_NOT_FOUND', '找不到当前店铺的 Checkout 推荐组件。', 404);
        }

        return $component;
    }

    private function authorize(Store $store, User $actor, string $permission): void
    {
        $this->assertStore($store);
        $organization = $store->organization;
        if (! $organization instanceof Organization
            || ! $actor->canAccessStore($store)
            || ! $actor->hasPermission($permission, $organization, $store)) {
            throw new PersonalizationException('PERSONALIZATION_CHECKOUT_ACCESS_DENIED', '无权管理当前店铺的 Checkout 推荐。', 403);
        }
    }

    private function assertStore(Store $store): void
    {
        $this->shopGuard->assertAllowed((string) $store->shopify_domain);
        $organization = $store->organization;
        if (! $organization instanceof Organization
            || (int) $organization->id !== (int) $store->organization_id
            || $organization->status !== 'active'
            || $store->status !== 'active') {
            throw new PersonalizationException('STORE_NOT_AVAILABLE', '当前店铺未启用 Checkout 个性化推荐。', 404);
        }
    }

    /** @return array{enabled: false, trust_items: array{}, collection: null} */
    private function disabledPayload(): array
    {
        return ['enabled' => false, 'trust_items' => [], 'collection' => null];
    }
}
