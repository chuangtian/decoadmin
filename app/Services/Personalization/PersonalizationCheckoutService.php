<?php

namespace App\Services\Personalization;

use App\Enums\PersonalizationComponentStatus;
use App\Enums\PersonalizationPlacement;
use App\Enums\PersonalizationStrategyStatus;
use App\Exceptions\PersonalizationException;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\PersonalizationCheckoutSetting;
use App\Models\PersonalizationRecommendationComponent;
use App\Models\PersonalizationRecommendationStrategy;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PersonalizationCheckoutService
{
    public const MAX_TRUST_ITEMS = 6;

    /** @var list<string> */
    public const ICONS = [
        'store', 'truck', 'star', 'check-circle', 'lock', 'savings', 'delivered', 'return', 'info',
    ];

    public function __construct(
        private PersonalizationShopGuard $shopGuard,
        private PersonalizationRecommendationService $recommendations,
        private PersonalizationConfigurationService $configuration,
    ) {}

    public function configuration(Store $store, User $actor): ?PersonalizationCheckoutSetting
    {
        $this->authorize($store, $actor, 'personalization.view');

        return PersonalizationCheckoutSetting::query()
            ->where('organization_id', $store->organization_id)
            ->where('store_id', $store->id)
            ->with('component.strategy')
            ->first();
    }

    /** @param array<string, mixed> $input */
    public function save(Store $store, User $actor, array $input): PersonalizationCheckoutSetting
    {
        $this->authorize($store, $actor, 'personalization.manage');
        $strategy = $this->strategy($store, $input['strategy_uuid'] ?? null);
        $trustItems = $this->trustItems($input['trust_items'] ?? []);

        $setting = DB::transaction(function () use ($store, $actor, $strategy, $trustItems): PersonalizationCheckoutSetting {
            $component = $this->bindStrategy($store, $actor, $strategy);
            $setting = PersonalizationCheckoutSetting::query()->updateOrCreate(
                ['store_id' => $store->id],
                [
                    'organization_id' => $store->organization_id,
                    'component_id' => $component->id,
                    'collection_id' => null,
                    'shopify_collection_id' => null,
                    'enabled' => true,
                    'trust_items' => $trustItems,
                    'settings' => [
                        'candidate_source' => 'strategy',
                        'sequence_mode' => 'sequential',
                        'sequence_exhaustion' => 'strategy',
                        'hide_when_exhausted' => true,
                        'trust_placement' => 'WALLETS1',
                        'recommendation_placement' => 'ORDER_SUMMARY2',
                    ],
                    'updated_by' => $actor->id,
                ],
            );

            return $setting->load('component.strategy');
        });

        AuditLog::query()->create([
            'organization_id' => $store->organization_id,
            'store_id' => $store->id,
            'user_id' => $actor->id,
            'action' => 'personalization_checkout_configuration_saved',
            'subject_type' => $setting->getMorphClass(),
            'subject_id' => $setting->id,
            'new_values' => [
                'strategy_uuid' => $strategy->uuid,
                'component_uuid' => $setting->component?->uuid,
                'placement' => PersonalizationPlacement::Checkout->value,
                'sequence_exhaustion' => 'strategy',
                'trust_item_count' => count(array_filter($trustItems, fn (array $item): bool => $item['enabled'])),
            ],
        ]);

        return $setting;
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
            ])->first();
        $component = $setting?->component;
        if (! $setting
            || ! $component
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

    private function strategy(Store $store, mixed $uuid): PersonalizationRecommendationStrategy
    {
        $uuid = trim((string) $uuid);
        $strategy = PersonalizationRecommendationStrategy::query()
            ->where('organization_id', $store->organization_id)
            ->where('store_id', $store->id)
            ->where('uuid', $uuid)
            ->withoutTrashed()
            ->with(['productOverrides', 'rules', 'versions', 'publishedVersion'])
            ->first();
        if (! $strategy) {
            throw new PersonalizationException('CHECKOUT_STRATEGY_NOT_FOUND', '所选推荐策略不属于当前店铺或已被删除。', 404);
        }

        return $strategy;
    }

    private function bindStrategy(
        Store $store,
        User $actor,
        PersonalizationRecommendationStrategy $strategy,
    ): PersonalizationRecommendationComponent {
        $setting = PersonalizationCheckoutSetting::query()
            ->where('organization_id', $store->organization_id)
            ->where('store_id', $store->id)
            ->with('component.strategy')
            ->lockForUpdate()
            ->first();
        $component = $setting?->component;
        if (! $component || $component->placement !== PersonalizationPlacement::Checkout) {
            $component = PersonalizationRecommendationComponent::query()
                ->where('organization_id', $store->organization_id)
                ->where('store_id', $store->id)
                ->where('placement', PersonalizationPlacement::Checkout->value)
                ->orderBy('id')
                ->lockForUpdate()
                ->first();
        }
        $previousStrategy = $component?->strategy;
        $componentName = mb_substr('Checkout · '.$strategy->name, 0, 80);

        if (! $component) {
            $component = $this->configuration->createComponent($store, $strategy, $actor, [
                'name' => $componentName,
                'placement' => PersonalizationPlacement::Checkout->value,
                'heading' => 'Great Value Bundles for You',
                'button_label' => 'Add',
            ]);
        } else {
            $component = $this->configuration->updateComponent($store, $component, $strategy, $actor, [
                'name' => $componentName,
                'placement' => PersonalizationPlacement::Checkout->value,
                'heading' => $component->heading ?: 'Great Value Bundles for You',
                'button_label' => $component->button_label ?: 'Add',
            ]);
        }
        $component = $this->configuration->activateComponent($store, $component, $actor);
        $version = $strategy->versions->first(fn ($version): bool => $version->status->value === 'draft')
            ?: $strategy->publishedVersion;
        $component->forceFill([
            'strategy_version_id' => $version?->id,
            'configuration_status' => 'valid',
        ])->save();
        $strategy->forceFill([
            'enabled' => true,
            'status' => PersonalizationStrategyStatus::Enabled,
            'updated_by' => $actor->id,
        ])->save();

        PersonalizationRecommendationComponent::query()
            ->where('organization_id', $store->organization_id)
            ->where('store_id', $store->id)
            ->where('placement', PersonalizationPlacement::Checkout->value)
            ->where('id', '!=', $component->id)
            ->where('status', PersonalizationComponentStatus::Active->value)
            ->update([
                'status' => PersonalizationComponentStatus::Disabled->value,
                'published_at' => null,
                'updated_by' => $actor->id,
                'updated_at' => now(),
            ]);

        if ($previousStrategy && (int) $previousStrategy->id !== (int) $strategy->id) {
            $hasOtherActivePlacement = $previousStrategy->components()
                ->where('status', PersonalizationComponentStatus::Active->value)
                ->exists();
            $previousStrategy->forceFill([
                'enabled' => $hasOtherActivePlacement,
                'status' => $hasOtherActivePlacement
                    ? PersonalizationStrategyStatus::Enabled
                    : ($previousStrategy->published_version_id
                        ? PersonalizationStrategyStatus::Disabled
                        : PersonalizationStrategyStatus::Draft),
                'updated_by' => $actor->id,
            ])->save();
        }

        return $component->refresh()->load('strategy');
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

    /** @return array{enabled: false, trust_items: array{}} */
    private function disabledPayload(): array
    {
        return ['enabled' => false, 'trust_items' => []];
    }
}
