<?php

namespace App\Services\Personalization;

use App\Enums\PersonalizationAlgorithm;
use App\Enums\PersonalizationComponentStatus;
use App\Enums\PersonalizationPlacement;
use App\Enums\PersonalizationProductOverrideType;
use App\Enums\PersonalizationRuleType;
use App\Enums\PersonalizationStrategyStatus;
use App\Enums\PersonalizationStrategyVersionStatus;
use App\Exceptions\PersonalizationException;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\PersonalizationRecommendationComponent;
use App\Models\PersonalizationRecommendationStrategy;
use App\Models\PersonalizationStrategyProductOverride;
use App\Models\PersonalizationStrategyRule;
use App\Models\PersonalizationStrategyVersion;
use App\Models\Product;
use App\Models\ProductCollection;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PersonalizationStrategyWorkflowService
{
    public const RECYCLE_DAYS = 30;

    public function __construct(private PersonalizationShopGuard $shopGuard) {}

    /** @return list<array<string, mixed>> */
    public function listing(Store $store, User $actor, ?string $search = null, bool $recycled = false): array
    {
        $this->authorize($store, $actor, 'personalization.view');
        $search = trim((string) $search);
        $query = PersonalizationRecommendationStrategy::query()
            ->where('organization_id', $store->organization_id)
            ->where('store_id', $store->id)
            ->with(['publishedVersion', 'versions' => fn ($query) => $query->limit(2), 'components'])
            ->when($recycled, fn ($query) => $query->onlyTrashed(), fn ($query) => $query->withoutTrashed())
            ->when($search !== '', fn ($query) => $query->where('name', 'like', '%'.addcslashes($search, '%_\\').'%'))
            ->orderByDesc('updated_at')
            ->limit(200)
            ->get();

        return $query->map(fn (PersonalizationRecommendationStrategy $strategy): array => $this->summary($strategy))->all();
    }

    /** @return array<string, mixed> */
    public function createDraft(Store $store, User $actor, string $idempotencyKey): array
    {
        $this->authorize($store, $actor, 'personalization.manage');
        $this->uuid($idempotencyKey, 'INVALID_IDEMPOTENCY_KEY');
        $payloadHash = hash('sha256', 'create-draft');

        $strategy = DB::transaction(function () use ($store, $actor, $idempotencyKey, $payloadHash): PersonalizationRecommendationStrategy {
            if ($existing = $this->idempotentResult($store, $actor, 'create_draft', $idempotencyKey, $payloadHash)) {
                return PersonalizationRecommendationStrategy::query()->findOrFail($existing->strategy_id);
            }

            $strategy = PersonalizationRecommendationStrategy::query()->create([
                'organization_id' => $store->organization_id,
                'store_id' => $store->id,
                'name' => '未命名策略',
                'algorithm' => PersonalizationAlgorithm::Manual,
                'enabled' => false,
                'status' => PersonalizationStrategyStatus::Draft,
                'item_limit' => 8,
                'settings' => null,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
            $configuration = $this->defaultConfiguration();
            $version = $this->createVersion($store, $strategy, $actor, 1, PersonalizationStrategyVersionStatus::Draft, [
                'name' => $strategy->name,
                'algorithm' => $strategy->algorithm->value,
                'item_limit' => $strategy->item_limit,
                'configuration' => $configuration,
            ]);
            $this->rememberIdempotency($store, $actor, 'create_draft', $idempotencyKey, $payloadHash, $strategy, $version);
            $this->audit($store, $actor, $strategy, 'personalization_strategy_draft_created', [
                'strategy_uuid' => $strategy->uuid,
                'version_uuid' => $version->uuid,
            ]);

            return $strategy;
        });

        return $this->editor($store, $strategy, $actor);
    }

    /** @return array<string, mixed> */
    public function editor(Store $store, PersonalizationRecommendationStrategy $strategy, User $actor): array
    {
        $this->authorize($store, $actor, 'personalization.view');
        $this->assertStrategy($store, $strategy);
        $draft = $strategy->versions()->where('status', PersonalizationStrategyVersionStatus::Draft->value)->first();
        if (! $draft && $strategy->publishedVersion
            && $actor->hasPermission('personalization.manage', $store->organization, $store)) {
            $draft = DB::transaction(function () use ($store, $strategy, $actor): PersonalizationStrategyVersion {
                $existing = PersonalizationStrategyVersion::query()
                    ->where('strategy_id', $strategy->id)
                    ->where('status', PersonalizationStrategyVersionStatus::Draft->value)
                    ->lockForUpdate()
                    ->first();
                if ($existing) {
                    return $existing;
                }

                return $this->createVersion(
                    $store,
                    $strategy,
                    $actor,
                    (int) PersonalizationStrategyVersion::query()->where('strategy_id', $strategy->id)->max('version_number') + 1,
                    PersonalizationStrategyVersionStatus::Draft,
                    $this->versionPayload($strategy->publishedVersion),
                );
            });
        }
        $version = $draft ?: $strategy->publishedVersion;
        if (! $version) {
            $this->authorize($store, $actor, 'personalization.manage');
            $version = DB::transaction(fn (): PersonalizationStrategyVersion => $this->createVersion(
                $store,
                $strategy,
                $actor,
                max(1, (int) $strategy->versions()->max('version_number') + 1),
                PersonalizationStrategyVersionStatus::Draft,
                $this->snapshotFromLive($strategy),
            ));
        }

        return [
            'strategy' => $this->summary($strategy->fresh(['publishedVersion', 'components', 'versions'])),
            'draft' => $this->versionPayload($version),
            'versions' => $this->versions($store, $strategy, $actor),
        ];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function autosave(
        Store $store,
        PersonalizationRecommendationStrategy $strategy,
        User $actor,
        array $input,
    ): array {
        $this->authorize($store, $actor, 'personalization.manage');
        $this->assertStrategy($store, $strategy);
        $idempotencyKey = $this->uuid($input['idempotency_key'] ?? null, 'INVALID_IDEMPOTENCY_KEY');
        $expectedLock = $this->integer($input['lock_version'] ?? null, 1, PHP_INT_MAX, 'INVALID_DRAFT_LOCK');
        $normalized = $this->normalizeDraft($store, $input['draft'] ?? null);
        $payloadHash = $this->checksum($normalized);

        $version = DB::transaction(function () use ($store, $strategy, $actor, $idempotencyKey, $expectedLock, $normalized, $payloadHash): PersonalizationStrategyVersion {
            if ($existing = $this->idempotentResult($store, $actor, 'autosave_draft', $idempotencyKey, $payloadHash)) {
                return PersonalizationStrategyVersion::query()->findOrFail($existing->strategy_version_id);
            }
            $lockedStrategy = PersonalizationRecommendationStrategy::query()->whereKey($strategy->id)->lockForUpdate()->firstOrFail();
            $draft = PersonalizationStrategyVersion::query()
                ->where('strategy_id', $lockedStrategy->id)
                ->where('status', PersonalizationStrategyVersionStatus::Draft->value)
                ->lockForUpdate()
                ->first();
            if (! $draft) {
                $source = $lockedStrategy->publishedVersion ?: null;
                $draft = $this->createVersion(
                    $store,
                    $lockedStrategy,
                    $actor,
                    (int) PersonalizationStrategyVersion::query()->where('strategy_id', $lockedStrategy->id)->max('version_number') + 1,
                    PersonalizationStrategyVersionStatus::Draft,
                    $source ? $this->versionPayload($source) : $this->snapshotFromLive($lockedStrategy),
                );
            }
            if ($draft->lock_version !== $expectedLock) {
                throw new PersonalizationException('DRAFT_VERSION_CONFLICT', '草稿已在其他窗口更新，请刷新后再试。', 409);
            }
            $draft->forceFill([
                'name' => $normalized['name'],
                'algorithm' => $normalized['algorithm'],
                'item_limit' => $normalized['item_limit'],
                'configuration' => $normalized['configuration'],
                'checksum' => $payloadHash,
                'lock_version' => $draft->lock_version + 1,
            ])->save();
            if (! $lockedStrategy->published_version_id) {
                $lockedStrategy->forceFill([
                    'name' => $normalized['name'],
                    'algorithm' => $normalized['algorithm'],
                    'item_limit' => $normalized['item_limit'],
                    'status' => PersonalizationStrategyStatus::Draft,
                    'updated_by' => $actor->id,
                ])->save();
            } else {
                $lockedStrategy->forceFill(['updated_by' => $actor->id])->save();
            }
            $this->rememberIdempotency($store, $actor, 'autosave_draft', $idempotencyKey, $payloadHash, $lockedStrategy, $draft);
            $this->audit($store, $actor, $lockedStrategy, 'personalization_strategy_draft_autosaved', [
                'version_uuid' => $draft->uuid,
                'lock_version' => $draft->lock_version,
            ]);

            return $draft;
        });

        return ['draft' => $this->versionPayload($version->refresh()), 'saved_at' => now()->toIso8601String()];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function publish(
        Store $store,
        PersonalizationRecommendationStrategy $strategy,
        User $actor,
        array $input,
    ): array {
        $this->authorize($store, $actor, 'personalization.manage');
        $this->assertStrategy($store, $strategy);
        $idempotencyKey = $this->uuid($input['idempotency_key'] ?? null, 'INVALID_IDEMPOTENCY_KEY');
        $expectedLock = $this->integer($input['lock_version'] ?? null, 1, PHP_INT_MAX, 'INVALID_DRAFT_LOCK');
        $confirmReplacements = (bool) ($input['confirm_replacements'] ?? false);

        $published = DB::transaction(function () use ($store, $strategy, $actor, $idempotencyKey, $expectedLock, $confirmReplacements): PersonalizationStrategyVersion {
            $lockedStrategy = PersonalizationRecommendationStrategy::query()->whereKey($strategy->id)->lockForUpdate()->firstOrFail();
            $draft = PersonalizationStrategyVersion::query()
                ->where('strategy_id', $lockedStrategy->id)
                ->where('status', PersonalizationStrategyVersionStatus::Draft->value)
                ->lockForUpdate()
                ->first();
            if (! $draft) {
                throw new PersonalizationException('STRATEGY_DRAFT_REQUIRED', '没有可发布的策略草稿。', 409);
            }
            if ($draft->lock_version !== $expectedLock) {
                throw new PersonalizationException('DRAFT_VERSION_CONFLICT', '草稿已更新，请刷新后再发布。', 409);
            }
            $payloadHash = hash('sha256', $draft->checksum.'|publish|'.($confirmReplacements ? '1' : '0'));
            if ($existing = $this->idempotentResult($store, $actor, 'publish_strategy', $idempotencyKey, $payloadHash)) {
                return PersonalizationStrategyVersion::query()->findOrFail($existing->strategy_version_id);
            }
            $this->validatePublishable($store, $draft);
            $configuration = $draft->configuration;
            $conflicts = $this->placementConflicts($store, $lockedStrategy, $configuration['placements'] ?? []);
            if ($conflicts !== [] && ! $confirmReplacements) {
                $names = collect($conflicts)->pluck('name')->filter()->unique()->take(3)->implode('、');
                throw new PersonalizationException(
                    'PLACEMENT_REPLACEMENT_CONFIRMATION_REQUIRED',
                    $names !== '' ? "目标场景当前由 {$names} 使用，请确认替换后再发布。" : '部分页面组件已使用其他策略，请确认替换后再发布。',
                    409,
                );
            }

            $previous = $lockedStrategy->publishedVersion;
            if ($previous) {
                $previous->forceFill(['status' => PersonalizationStrategyVersionStatus::Superseded])->save();
            }
            $this->applySnapshot($store, $lockedStrategy, $draft, $actor, $confirmReplacements);
            $draft->forceFill([
                'status' => PersonalizationStrategyVersionStatus::Published,
                'published_by' => $actor->id,
                'published_at' => now(),
            ])->save();
            $lockedStrategy->forceFill([
                'name' => $draft->name,
                'algorithm' => $draft->algorithm,
                'item_limit' => $draft->item_limit,
                'enabled' => true,
                'status' => PersonalizationStrategyStatus::Enabled,
                'published_version_id' => $draft->id,
                'updated_by' => $actor->id,
            ])->save();
            $this->rememberIdempotency($store, $actor, 'publish_strategy', $idempotencyKey, $payloadHash, $lockedStrategy, $draft);
            $this->audit($store, $actor, $lockedStrategy, 'personalization_strategy_published', [
                'version_uuid' => $draft->uuid,
                'version_number' => $draft->version_number,
                'replaced_components' => $conflicts,
            ]);

            return $draft;
        });

        return ['strategy' => $this->summary($strategy->fresh(['publishedVersion', 'components', 'versions'])), 'published_version' => $this->versionPayload($published)];
    }

    /** @return array<string, mixed> */
    public function duplicate(Store $store, PersonalizationRecommendationStrategy $strategy, User $actor, string $idempotencyKey): array
    {
        $this->authorize($store, $actor, 'personalization.manage');
        $this->assertStrategy($store, $strategy);
        $this->uuid($idempotencyKey, 'INVALID_IDEMPOTENCY_KEY');
        $source = $strategy->versions()->where('status', PersonalizationStrategyVersionStatus::Draft->value)->first()
            ?: $strategy->publishedVersion;
        $snapshot = $source ? $this->versionPayload($source) : $this->snapshotFromLive($strategy);
        $payloadHash = hash('sha256', $strategy->uuid.'|duplicate|'.($source?->checksum ?? 'live'));

        $copy = DB::transaction(function () use ($store, $actor, $strategy, $idempotencyKey, $payloadHash, $snapshot): PersonalizationRecommendationStrategy {
            if ($existing = $this->idempotentResult($store, $actor, 'duplicate_strategy', $idempotencyKey, $payloadHash)) {
                return PersonalizationRecommendationStrategy::query()->findOrFail($existing->strategy_id);
            }
            $copy = PersonalizationRecommendationStrategy::query()->create([
                'organization_id' => $store->organization_id,
                'store_id' => $store->id,
                'name' => Str::limit($snapshot['name'].' - 副本', 80, ''),
                'algorithm' => $snapshot['algorithm'],
                'enabled' => false,
                'status' => PersonalizationStrategyStatus::Draft,
                'item_limit' => $snapshot['item_limit'],
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
            $snapshot['name'] = $copy->name;
            $version = $this->createVersion($store, $copy, $actor, 1, PersonalizationStrategyVersionStatus::Draft, $snapshot);
            $this->rememberIdempotency($store, $actor, 'duplicate_strategy', $idempotencyKey, $payloadHash, $copy, $version);
            $this->audit($store, $actor, $copy, 'personalization_strategy_duplicated', ['source_strategy_uuid' => $strategy->uuid]);

            return $copy;
        });

        return $this->editor($store, $copy, $actor);
    }

    public function disable(Store $store, PersonalizationRecommendationStrategy $strategy, User $actor): array
    {
        $this->authorize($store, $actor, 'personalization.manage');
        $this->assertStrategy($store, $strategy);
        DB::transaction(function () use ($strategy, $actor): void {
            $strategy->components()->where('status', PersonalizationComponentStatus::Active->value)->update([
                'status' => PersonalizationComponentStatus::Disabled->value,
                'published_at' => null,
                'updated_by' => $actor->id,
                'updated_at' => now(),
            ]);
            $strategy->forceFill([
                'enabled' => false,
                'status' => PersonalizationStrategyStatus::Disabled,
                'updated_by' => $actor->id,
            ])->save();
        });
        $this->audit($store, $actor, $strategy, 'personalization_strategy_disabled', []);

        return $this->summary($strategy->fresh(['publishedVersion', 'components', 'versions']));
    }

    public function recycle(Store $store, PersonalizationRecommendationStrategy $strategy, User $actor): void
    {
        $this->authorize($store, $actor, 'personalization.manage');
        $this->assertStrategy($store, $strategy);
        if ($strategy->components()->exists()) {
            throw new PersonalizationException('STRATEGY_IN_USE', '该策略正在被页面或组件使用，请先解除关联或替换。', 409);
        }
        $strategy->forceFill([
            'enabled' => false,
            'status' => PersonalizationStrategyStatus::Archived,
            'archived_at' => now(),
            'purge_after' => now()->addDays(self::RECYCLE_DAYS),
            'updated_by' => $actor->id,
        ])->save();
        $strategy->delete();
        $this->audit($store, $actor, $strategy, 'personalization_strategy_recycled', ['purge_after' => $strategy->purge_after?->toIso8601String()]);
    }

    /** @return array<string, mixed> */
    public function restore(Store $store, string $strategyUuid, User $actor): array
    {
        $this->authorize($store, $actor, 'personalization.manage');
        $strategy = PersonalizationRecommendationStrategy::onlyTrashed()
            ->where('organization_id', $store->organization_id)
            ->where('store_id', $store->id)
            ->where('uuid', $strategyUuid)
            ->first();
        if (! $strategy) {
            throw new PersonalizationException('STRATEGY_NOT_FOUND', '回收站中找不到该策略。', 404);
        }
        $strategy->restore();
        $strategy->forceFill([
            'status' => $strategy->published_version_id ? PersonalizationStrategyStatus::Disabled : PersonalizationStrategyStatus::Draft,
            'archived_at' => null,
            'purge_after' => null,
            'updated_by' => $actor->id,
        ])->save();
        $this->audit($store, $actor, $strategy, 'personalization_strategy_restored', []);

        return $this->summary($strategy->fresh(['publishedVersion', 'components', 'versions']));
    }

    /** @return list<array<string, mixed>> */
    public function versions(Store $store, PersonalizationRecommendationStrategy $strategy, User $actor): array
    {
        $this->authorize($store, $actor, 'personalization.view');
        $this->assertStrategy($store, $strategy);

        return $strategy->versions()->limit(100)->get()->map(fn (PersonalizationStrategyVersion $version): array => [
            'uuid' => $version->uuid,
            'version_number' => $version->version_number,
            'status' => $version->status->value,
            'name' => $version->name,
            'created_at' => $version->created_at?->toIso8601String(),
            'published_at' => $version->published_at?->toIso8601String(),
            'is_current' => (int) $strategy->published_version_id === (int) $version->id,
        ])->all();
    }

    /** @return array<string, mixed> */
    public function restoreVersion(
        Store $store,
        PersonalizationRecommendationStrategy $strategy,
        PersonalizationStrategyVersion $source,
        User $actor,
        string $idempotencyKey,
        bool $confirmReplacements,
    ): array {
        $this->authorize($store, $actor, 'personalization.manage');
        $this->assertStrategy($store, $strategy);
        $this->assertVersion($strategy, $source);
        $this->uuid($idempotencyKey, 'INVALID_IDEMPOTENCY_KEY');

        $draft = DB::transaction(function () use ($store, $strategy, $source, $actor): PersonalizationStrategyVersion {
            PersonalizationStrategyVersion::query()
                ->where('strategy_id', $strategy->id)
                ->where('status', PersonalizationStrategyVersionStatus::Draft->value)
                ->delete();

            return $this->createVersion(
                $store,
                $strategy,
                $actor,
                (int) PersonalizationStrategyVersion::query()->where('strategy_id', $strategy->id)->max('version_number') + 1,
                PersonalizationStrategyVersionStatus::Draft,
                $this->versionPayload($source),
            );
        });
        $result = $this->publish($store, $strategy, $actor, [
            'idempotency_key' => $idempotencyKey,
            'lock_version' => $draft->lock_version,
            'confirm_replacements' => $confirmReplacements,
        ]);
        $this->audit($store, $actor, $strategy, 'personalization_strategy_version_restored', [
            'source_version_uuid' => $source->uuid,
            'restored_as_version_uuid' => data_get($result, 'published_version.uuid'),
        ]);

        return $result;
    }

    /** @return array<string, mixed> */
    public function preview(Store $store, PersonalizationRecommendationStrategy $strategy, User $actor, array $context): array
    {
        $this->authorize($store, $actor, 'personalization.view');
        $this->assertStrategy($store, $strategy);
        $version = $strategy->versions()->where('status', PersonalizationStrategyVersionStatus::Draft->value)->first()
            ?: $strategy->publishedVersion;
        if (! $version) {
            throw new PersonalizationException('STRATEGY_VERSION_NOT_FOUND', '该策略没有可预览版本。', 409);
        }
        $configuration = $version->configuration;
        $cartIds = $this->numericIds($context['cart_product_ids'] ?? [], 20, 'INVALID_PREVIEW_CONTEXT');
        $purchasedIds = $this->numericIds($context['purchased_product_ids'] ?? [], 50, 'INVALID_PREVIEW_CONTEXT');
        $seed = filled($context['seed_product_id'] ?? null) ? $this->numericProductId($context['seed_product_id']) : null;
        $strategyModel = $this->strategyModelFromVersion($strategy, $version);
        $recommendations = app(PersonalizationRecommendationService::class)->recommend($store, $strategyModel, [
            'seed_product_id' => $seed,
            'cart_product_ids' => $cartIds,
            'purchased_product_ids' => $purchasedIds,
            'recently_viewed_product_ids' => [],
        ]);
        $skipped = $this->skippedPreviewProducts($store, $configuration, $cartIds, $purchasedIds, collect($recommendations['items']));
        $checkoutMaximum = data_get($configuration, 'checkout.maximum_recommendations');

        return [
            'version_uuid' => $version->uuid,
            'version_number' => $version->version_number,
            'items' => $recommendations['items'],
            'skipped' => $skipped,
            'checkout_sequence' => [
                'maximum_recommendations' => $checkoutMaximum,
                'items' => $checkoutMaximum === null
                    ? $recommendations['items']
                    : array_slice($recommendations['items'], 0, (int) $checkoutMaximum),
                'hide_when_empty' => true,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function summary(PersonalizationRecommendationStrategy $strategy): array
    {
        $components = $strategy->relationLoaded('components') ? $strategy->components : $strategy->components()->get();
        $usages = $components->map(fn (PersonalizationRecommendationComponent $component): array => [
            'component_uuid' => $component->uuid,
            'name' => $component->name,
            'placement' => $component->placement->value,
            'status' => $component->configuration_status !== 'valid' ? 'configuration_error' : match ($component->status) {
                PersonalizationComponentStatus::Active => 'live',
                PersonalizationComponentStatus::Draft => 'configured_not_enabled',
                default => 'disabled',
            },
        ])->values()->all();
        $status = $strategy->deleted_at
            ? PersonalizationStrategyStatus::Archived->value
            : ($components->contains(fn (PersonalizationRecommendationComponent $component): bool => $component->configuration_status !== 'valid')
                ? PersonalizationStrategyStatus::ConfigurationError->value
                : $strategy->status->value);
        $draft = $strategy->versions->first(fn (PersonalizationStrategyVersion $version): bool => $version->status === PersonalizationStrategyVersionStatus::Draft);

        return [
            'uuid' => $strategy->uuid,
            'name' => $draft?->name ?? $strategy->name,
            'technical_id' => $strategy->uuid,
            'status' => $status,
            'algorithm' => ($draft?->algorithm ?? $strategy->algorithm)->value,
            'item_limit' => $draft?->item_limit ?? $strategy->item_limit,
            'used_in' => $usages,
            'created_at' => $strategy->created_at?->toIso8601String(),
            'updated_at' => ($draft?->updated_at ?? $strategy->updated_at)?->toIso8601String(),
            'published_version' => $strategy->publishedVersion ? [
                'uuid' => $strategy->publishedVersion->uuid,
                'version_number' => $strategy->publishedVersion->version_number,
                'published_at' => $strategy->publishedVersion->published_at?->toIso8601String(),
            ] : null,
            'has_draft' => (bool) $draft,
            'recycle_until' => $strategy->purge_after?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function versionPayload(PersonalizationStrategyVersion $version): array
    {
        return [
            'uuid' => $version->uuid,
            'version_number' => $version->version_number,
            'status' => $version->status->value,
            'name' => $version->name,
            'algorithm' => $version->algorithm->value,
            'item_limit' => $version->item_limit,
            'configuration' => $version->configuration,
            'lock_version' => $version->lock_version,
            'updated_at' => $version->updated_at?->toIso8601String(),
            'published_at' => $version->published_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function defaultConfiguration(): array
    {
        return [
            'rules' => [
                'include_tags' => [],
                'exclude_tags' => [],
                'include_collection_ids' => [],
                'exclude_collection_ids' => [],
                'exclude_vendors' => [],
                'minimum_price' => null,
                'maximum_price' => null,
                'minimum_inventory' => null,
                'in_stock_only' => true,
                'exclude_cart_products' => true,
                'exclude_purchased_products' => false,
            ],
            'products' => ['manual' => [], 'pinned' => [], 'excluded' => []],
            'discount' => ['enabled' => false, 'reference' => null],
            'placements' => [],
            'checkout' => ['maximum_recommendations' => null, 'collection_id' => null],
        ];
    }

    /** @return array<string, mixed> */
    private function snapshotFromLive(PersonalizationRecommendationStrategy $strategy): array
    {
        $strategy->loadMissing(['rules', 'productOverrides', 'components.style']);
        $configuration = $this->defaultConfiguration();
        foreach ($strategy->rules as $rule) {
            $configuration['rules'][$rule->type->value] = match ($rule->type) {
                PersonalizationRuleType::IncludeTags, PersonalizationRuleType::ExcludeTags => $rule->value['tags'] ?? [],
                PersonalizationRuleType::IncludeCollections => $rule->value['collection_ids'] ?? [],
                PersonalizationRuleType::ExcludeCollections => $rule->value['collection_ids'] ?? [],
                PersonalizationRuleType::ExcludeVendors => $rule->value['vendors'] ?? [],
                PersonalizationRuleType::MinimumPrice, PersonalizationRuleType::MaximumPrice => $rule->value['amount'] ?? null,
                PersonalizationRuleType::MinimumInventory => $rule->value['quantity'] ?? null,
                PersonalizationRuleType::InStockOnly,
                PersonalizationRuleType::ExcludeCartProducts,
                PersonalizationRuleType::ExcludePurchasedProducts => (bool) ($rule->value['enabled'] ?? false),
            };
        }
        foreach (PersonalizationProductOverrideType::cases() as $type) {
            $configuration['products'][$type->value] = $strategy->productOverrides
                ->where('type', $type)
                ->sortBy('position')
                ->pluck('shopify_product_id')
                ->map(fn ($id): string => (string) $id)
                ->values()->all();
        }
        $configuration['placements'] = $strategy->components->map(fn (PersonalizationRecommendationComponent $component): array => [
            'placement' => $component->placement->value,
            'enabled' => $component->status === PersonalizationComponentStatus::Active,
            'component_uuid' => $component->uuid,
            'name' => $component->name,
            'heading' => $component->heading,
            'button_label' => $component->button_label,
            'style' => $component->style ? $component->style->only([
                'layout', 'desktop_columns', 'mobile_columns', 'show_image', 'show_vendor',
                'show_price', 'show_compare_at_price', 'show_add_to_cart', 'tokens',
            ]) : [],
        ])->values()->all();

        return [
            'name' => $strategy->name,
            'algorithm' => $strategy->algorithm->value,
            'item_limit' => $strategy->item_limit,
            'configuration' => $configuration,
        ];
    }

    /** @return array<string, mixed> */
    private function normalizeDraft(Store $store, mixed $draft): array
    {
        if (! is_array($draft) || array_is_list($draft)) {
            throw new PersonalizationException('INVALID_STRATEGY_DRAFT', '策略草稿格式无效。');
        }
        if (array_diff(array_keys($draft), [
            'uuid', 'version_number', 'status', 'name', 'algorithm', 'item_limit', 'configuration',
            'lock_version', 'updated_at', 'published_at',
        ]) !== []) {
            throw new PersonalizationException('INVALID_STRATEGY_DRAFT', '策略草稿包含不支持的字段。');
        }
        $name = $this->text($draft['name'] ?? null, 80, 'STRATEGY_NAME_REQUIRED');
        $algorithm = PersonalizationAlgorithm::tryFrom((string) ($draft['algorithm'] ?? ''));
        if (! $algorithm) {
            throw new PersonalizationException('INVALID_RECOMMENDATION_ALGORITHM', '推荐规则类型无效。');
        }
        $itemLimit = $this->integer($draft['item_limit'] ?? null, 1, 50, 'INVALID_ITEM_LIMIT');
        $configuration = $draft['configuration'] ?? null;
        if (! is_array($configuration) || array_is_list($configuration)
            || array_diff(array_keys($configuration), ['rules', 'products', 'discount', 'placements', 'checkout']) !== []) {
            throw new PersonalizationException('INVALID_STRATEGY_CONFIGURATION', '策略配置格式无效。');
        }

        return [
            'name' => $name,
            'algorithm' => $algorithm->value,
            'item_limit' => $itemLimit,
            'configuration' => [
                'rules' => $this->normalizeRules($configuration['rules'] ?? []),
                'products' => $this->normalizeProducts($store, $configuration['products'] ?? []),
                'discount' => $this->normalizeDiscount($configuration['discount'] ?? []),
                'placements' => $this->normalizePlacements($configuration['placements'] ?? []),
                'checkout' => $this->normalizeCheckout($configuration['checkout'] ?? []),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function normalizeRules(mixed $rules): array
    {
        if (! is_array($rules) || array_is_list($rules)) {
            throw new PersonalizationException('INVALID_STRATEGY_RULE', '推荐规则格式无效。');
        }
        $defaults = $this->defaultConfiguration()['rules'];
        if (array_diff(array_keys($rules), array_keys($defaults)) !== []) {
            throw new PersonalizationException('INVALID_STRATEGY_RULE', '策略包含不支持的规则字段。');
        }

        return [
            'include_tags' => $this->strings($rules['include_tags'] ?? [], 50, 100, 'INVALID_TAG_RULE'),
            'exclude_tags' => $this->strings($rules['exclude_tags'] ?? [], 50, 100, 'INVALID_TAG_RULE'),
            'include_collection_ids' => $this->numericIds($rules['include_collection_ids'] ?? [], 100, 'INVALID_COLLECTION_RULE'),
            'exclude_collection_ids' => $this->numericIds($rules['exclude_collection_ids'] ?? [], 100, 'INVALID_COLLECTION_RULE'),
            'exclude_vendors' => $this->strings($rules['exclude_vendors'] ?? [], 100, 120, 'INVALID_VENDOR_RULE'),
            'minimum_price' => $this->nullableDecimal($rules['minimum_price'] ?? null),
            'maximum_price' => $this->nullableDecimal($rules['maximum_price'] ?? null),
            'minimum_inventory' => ($rules['minimum_inventory'] ?? null) === null || $rules['minimum_inventory'] === ''
                ? null : $this->integer($rules['minimum_inventory'], 0, 1_000_000, 'INVALID_INVENTORY_RULE'),
            'in_stock_only' => (bool) ($rules['in_stock_only'] ?? true),
            'exclude_cart_products' => (bool) ($rules['exclude_cart_products'] ?? true),
            'exclude_purchased_products' => (bool) ($rules['exclude_purchased_products'] ?? false),
        ];
    }

    /** @return array<string, list<string>> */
    private function normalizeProducts(Store $store, mixed $products): array
    {
        if (! is_array($products) || array_is_list($products)
            || array_diff(array_keys($products), ['manual', 'pinned', 'excluded']) !== []) {
            throw new PersonalizationException('INVALID_PRODUCT_OVERRIDE', '推荐商品配置格式无效。');
        }
        $normalized = [];
        $seen = [];
        foreach (['manual', 'pinned', 'excluded'] as $type) {
            $normalized[$type] = $this->numericIds($products[$type] ?? [], 500, 'INVALID_PRODUCT_OVERRIDE');
            foreach ($normalized[$type] as $productId) {
                if (isset($seen[$productId])) {
                    throw new PersonalizationException('CONFLICTING_PRODUCT_OVERRIDE', '同一商品不能同时出现在多个推荐操作中。');
                }
                $seen[$productId] = true;
            }
        }
        if ($seen !== []) {
            $owned = Product::query()->where('organization_id', $store->organization_id)->where('store_id', $store->id)
                ->whereIn('shopify_product_id', array_keys($seen))->count();
            if ($owned !== count($seen)) {
                throw new PersonalizationException('PRODUCT_OVERRIDE_NOT_FOUND', '部分商品不属于当前店铺或尚未同步。', 404);
            }
        }

        return $normalized;
    }

    /** @return array{enabled: bool, reference: ?string} */
    private function normalizeDiscount(mixed $discount): array
    {
        if (! is_array($discount) || array_is_list($discount)
            || array_diff(array_keys($discount), ['enabled', 'reference']) !== []) {
            throw new PersonalizationException('INVALID_DISCOUNT_REFERENCE', '优惠关联配置无效。');
        }
        $enabled = (bool) ($discount['enabled'] ?? false);
        $reference = trim((string) ($discount['reference'] ?? ''));
        if (mb_strlen($reference) > 255 || ($enabled && $reference === '')) {
            throw new PersonalizationException('INVALID_DISCOUNT_REFERENCE', '启用优惠时必须选择或填写已有折扣引用。');
        }

        return ['enabled' => $enabled, 'reference' => $reference ?: null];
    }

    /** @return list<array<string, mixed>> */
    private function normalizePlacements(mixed $placements): array
    {
        if (! is_array($placements) || count($placements) > count(PersonalizationPlacement::cases())) {
            throw new PersonalizationException('INVALID_COMPONENT_PLACEMENT', '使用场景配置无效。');
        }
        $normalized = [];
        $seen = [];
        foreach ($placements as $placement) {
            if (! is_array($placement) || array_is_list($placement)) {
                throw new PersonalizationException('INVALID_COMPONENT_PLACEMENT', '使用场景配置无效。');
            }
            $type = PersonalizationPlacement::tryFrom((string) ($placement['placement'] ?? ''));
            if (! $type || isset($seen[$type->value])) {
                throw new PersonalizationException('INVALID_COMPONENT_PLACEMENT', '使用场景无效或重复。');
            }
            $seen[$type->value] = true;
            $style = is_array($placement['style'] ?? null) ? $placement['style'] : [];
            $normalized[] = [
                'placement' => $type->value,
                'enabled' => (bool) ($placement['enabled'] ?? false),
                'component_uuid' => filled($placement['component_uuid'] ?? null)
                    ? $this->uuid($placement['component_uuid'], 'INVALID_COMPONENT_UUID') : null,
                'name' => $this->text($placement['name'] ?? $this->placementLabel($type), 80, 'COMPONENT_NAME_REQUIRED'),
                'heading' => $this->optionalText($placement['heading'] ?? null, 120),
                'button_label' => $this->optionalText($placement['button_label'] ?? null, 60),
                'style' => [
                    'layout' => in_array(($style['layout'] ?? 'carousel'), ['carousel', 'grid'], true) ? $style['layout'] : 'carousel',
                    'desktop_columns' => $this->integer($style['desktop_columns'] ?? 4, 1, 6, 'INVALID_DESKTOP_COLUMNS'),
                    'mobile_columns' => $this->integer($style['mobile_columns'] ?? 2, 1, 3, 'INVALID_MOBILE_COLUMNS'),
                    'show_image' => (bool) ($style['show_image'] ?? true),
                    'show_vendor' => (bool) ($style['show_vendor'] ?? false),
                    'show_price' => (bool) ($style['show_price'] ?? true),
                    'show_compare_at_price' => (bool) ($style['show_compare_at_price'] ?? true),
                    'show_add_to_cart' => (bool) ($style['show_add_to_cart'] ?? true),
                    'tokens' => [],
                ],
            ];
        }

        return $normalized;
    }

    /** @return array{maximum_recommendations: ?int, collection_id: ?string} */
    private function normalizeCheckout(mixed $checkout): array
    {
        if (! is_array($checkout) || array_is_list($checkout)
            || array_diff(array_keys($checkout), ['maximum_recommendations', 'collection_id']) !== []) {
            throw new PersonalizationException('INVALID_CHECKOUT_STRATEGY', 'Checkout 推荐设置无效。');
        }
        $maximum = $checkout['maximum_recommendations'] ?? null;

        return [
            'maximum_recommendations' => $maximum === null || $maximum === ''
                ? null : $this->integer($maximum, 1, 1000, 'INVALID_CHECKOUT_MAXIMUM'),
            'collection_id' => filled($checkout['collection_id'] ?? null)
                ? $this->numericProductId($checkout['collection_id']) : null,
        ];
    }

    private function validatePublishable(Store $store, PersonalizationStrategyVersion $version): void
    {
        $configuration = $version->configuration;
        if ($version->algorithm === PersonalizationAlgorithm::Manual
            && ($configuration['products']['manual'] ?? []) === []
            && ($configuration['rules']['include_collection_ids'] ?? []) === []) {
            throw new PersonalizationException('MANUAL_PRODUCTS_REQUIRED', '手动推荐策略至少需要选择一个商品或集合。', 409);
        }
        if (($configuration['rules']['minimum_price'] ?? null) !== null
            && ($configuration['rules']['maximum_price'] ?? null) !== null
            && (float) $configuration['rules']['minimum_price'] > (float) $configuration['rules']['maximum_price']) {
            throw new PersonalizationException('INVALID_PRICE_RULE', '最低价格不能高于最高价格。', 409);
        }
        $this->normalizeProducts($store, $configuration['products'] ?? []);
        $collectionIds = collect([
            ...($configuration['rules']['include_collection_ids'] ?? []),
            ...($configuration['rules']['exclude_collection_ids'] ?? []),
        ])->unique()->values()->all();
        if ($collectionIds !== [] && ProductCollection::query()
            ->where('organization_id', $store->organization_id)
            ->where('store_id', $store->id)
            ->whereIn('shopify_collection_id', $collectionIds)
            ->count() !== count($collectionIds)) {
            throw new PersonalizationException('COLLECTION_RULE_NOT_FOUND', '部分集合不属于当前店铺或尚未同步。', 404);
        }
    }

    /** @param list<array<string, mixed>> $placements @return list<array<string, string>> */
    private function placementConflicts(Store $store, PersonalizationRecommendationStrategy $strategy, array $placements): array
    {
        $enabled = collect($placements)->where('enabled', true)->pluck('placement')->all();
        if ($enabled === []) {
            return [];
        }

        return PersonalizationRecommendationComponent::query()
            ->where('organization_id', $store->organization_id)->where('store_id', $store->id)
            ->where('strategy_id', '!=', $strategy->id)
            ->where('status', PersonalizationComponentStatus::Active->value)
            ->whereIn('placement', $enabled)
            ->get()->map(fn (PersonalizationRecommendationComponent $component): array => [
                'component_uuid' => $component->uuid,
                'name' => $component->name,
                'placement' => $component->placement->value,
            ])->all();
    }

    private function applySnapshot(
        Store $store,
        PersonalizationRecommendationStrategy $strategy,
        PersonalizationStrategyVersion $version,
        User $actor,
        bool $replaceConflicts,
    ): void {
        $configuration = $version->configuration;
        $strategy->rules()->delete();
        foreach ($this->ruleRows($configuration['rules'] ?? []) as $position => $row) {
            PersonalizationStrategyRule::query()->create([
                'organization_id' => $store->organization_id,
                'store_id' => $store->id,
                'strategy_id' => $strategy->id,
                'type' => $row['type'],
                'value' => $row['value'],
                'enabled' => true,
                'position' => $position + 1,
            ]);
        }
        $strategy->productOverrides()->delete();
        $productIds = collect($configuration['products'] ?? [])->flatten()->unique()->values()->all();
        $owned = Product::query()->where('organization_id', $store->organization_id)->where('store_id', $store->id)
            ->whereIn('shopify_product_id', $productIds)->pluck('id', 'shopify_product_id');
        foreach (['manual', 'pinned', 'excluded'] as $type) {
            foreach (($configuration['products'][$type] ?? []) as $position => $productId) {
                PersonalizationStrategyProductOverride::query()->create([
                    'organization_id' => $store->organization_id,
                    'store_id' => $store->id,
                    'strategy_id' => $strategy->id,
                    'product_id' => $owned->get($productId),
                    'shopify_product_id' => $productId,
                    'type' => $type,
                    'position' => $position + 1,
                ]);
            }
        }

        $configuredPlacements = [];
        foreach ($configuration['placements'] ?? [] as $placement) {
            $type = PersonalizationPlacement::from($placement['placement']);
            $configuredPlacements[] = $type->value;
            $component = filled($placement['component_uuid'] ?? null)
                ? PersonalizationRecommendationComponent::query()
                    ->where('organization_id', $store->organization_id)->where('store_id', $store->id)
                    ->where('strategy_id', $strategy->id)->where('uuid', $placement['component_uuid'])->first()
                : null;
            $component ??= PersonalizationRecommendationComponent::query()
                ->where('organization_id', $store->organization_id)->where('store_id', $store->id)
                ->where('strategy_id', $strategy->id)->where('placement', $type->value)->first();
            if (! $component) {
                $component = PersonalizationRecommendationComponent::query()->create([
                    'organization_id' => $store->organization_id,
                    'store_id' => $store->id,
                    'strategy_id' => $strategy->id,
                    'strategy_version_id' => $version->id,
                    'name' => $placement['name'],
                    'placement' => $type,
                    'status' => PersonalizationComponentStatus::Draft,
                    'configuration_status' => 'valid',
                    'heading' => $placement['heading'],
                    'button_label' => $placement['button_label'],
                    'position' => 1,
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ]);
            }
            if ($replaceConflicts && $placement['enabled']) {
                PersonalizationRecommendationComponent::query()
                    ->where('organization_id', $store->organization_id)->where('store_id', $store->id)
                    ->where('placement', $type->value)->where('strategy_id', '!=', $strategy->id)
                    ->where('status', PersonalizationComponentStatus::Active->value)
                    ->update(['status' => PersonalizationComponentStatus::Disabled->value, 'published_at' => null, 'updated_at' => now()]);
            }
            $component->forceFill([
                'strategy_version_id' => $version->id,
                'name' => $placement['name'],
                'placement' => $type,
                'status' => $placement['enabled'] ? PersonalizationComponentStatus::Active : PersonalizationComponentStatus::Disabled,
                'configuration_status' => 'valid',
                'heading' => $placement['heading'],
                'button_label' => $placement['button_label'],
                'published_at' => $placement['enabled'] ? now() : null,
                'updated_by' => $actor->id,
            ])->save();
            $style = $component->style()->firstOrNew([
                'organization_id' => $store->organization_id,
                'store_id' => $store->id,
            ]);
            $style->fill($placement['style'])->save();
        }
        $obsolete = $strategy->components()->when(
            $configuredPlacements !== [],
            fn ($query) => $query->whereNotIn('placement', $configuredPlacements),
        )->get();
        foreach ($obsolete as $component) {
            $component->forceFill([
                'status' => PersonalizationComponentStatus::Disabled,
                'published_at' => null,
                'updated_by' => $actor->id,
            ])->save();
            $component->delete();
        }
    }

    /** @return list<array{type: string, value: array<string, mixed>}> */
    private function ruleRows(array $rules): array
    {
        $rows = [];
        $map = [
            'include_tags' => ['type' => PersonalizationRuleType::IncludeTags->value, 'key' => 'tags'],
            'exclude_tags' => ['type' => PersonalizationRuleType::ExcludeTags->value, 'key' => 'tags'],
            'include_collection_ids' => ['type' => PersonalizationRuleType::IncludeCollections->value, 'key' => 'collection_ids'],
            'exclude_collection_ids' => ['type' => PersonalizationRuleType::ExcludeCollections->value, 'key' => 'collection_ids'],
            'exclude_vendors' => ['type' => PersonalizationRuleType::ExcludeVendors->value, 'key' => 'vendors'],
        ];
        foreach ($map as $key => $definition) {
            if (($rules[$key] ?? []) !== []) {
                $rows[] = ['type' => $definition['type'], 'value' => [$definition['key'] => $rules[$key]]];
            }
        }
        foreach (['minimum_price' => 'amount', 'maximum_price' => 'amount', 'minimum_inventory' => 'quantity'] as $key => $valueKey) {
            if (($rules[$key] ?? null) !== null) {
                $rows[] = ['type' => $key, 'value' => [$valueKey => $rules[$key]]];
            }
        }
        foreach (['in_stock_only', 'exclude_cart_products', 'exclude_purchased_products'] as $key) {
            $rows[] = ['type' => $key, 'value' => ['enabled' => (bool) ($rules[$key] ?? false)]];
        }

        return $rows;
    }

    private function strategyModelFromVersion(
        PersonalizationRecommendationStrategy $strategy,
        PersonalizationStrategyVersion $version,
    ): PersonalizationRecommendationStrategy {
        $model = new PersonalizationRecommendationStrategy;
        $model->forceFill([
            'id' => $strategy->id,
            'uuid' => $strategy->uuid,
            'organization_id' => $strategy->organization_id,
            'store_id' => $strategy->store_id,
            'name' => $version->name,
            'algorithm' => $version->algorithm,
            'item_limit' => $version->item_limit,
            'enabled' => true,
            'status' => PersonalizationStrategyStatus::Draft,
        ]);
        $model->exists = true;
        $rules = collect($this->ruleRows($version->configuration['rules'] ?? []))->map(function (array $row, int $position) use ($strategy): PersonalizationStrategyRule {
            $rule = new PersonalizationStrategyRule([
                'strategy_id' => $strategy->id,
                'type' => $row['type'],
                'value' => $row['value'],
                'enabled' => true,
                'position' => $position + 1,
            ]);
            $rule->exists = true;

            return $rule;
        });
        $overrides = collect();
        foreach (['manual', 'pinned', 'excluded'] as $type) {
            foreach (($version->configuration['products'][$type] ?? []) as $position => $productId) {
                $override = new PersonalizationStrategyProductOverride([
                    'strategy_id' => $strategy->id,
                    'shopify_product_id' => $productId,
                    'type' => $type,
                    'position' => $position + 1,
                ]);
                $override->exists = true;
                $overrides->push($override);
            }
        }
        $model->setRelation('rules', $rules);
        $model->setRelation('productOverrides', $overrides);

        return $model;
    }

    /** @return list<array<string, mixed>> */
    private function skippedPreviewProducts(Store $store, array $configuration, array $cartIds, array $purchasedIds, Collection $recommended): array
    {
        $recommendedIds = $recommended->pluck('shopify_product_id')->map(fn ($id): string => (string) $id)->all();
        $configured = collect($configuration['products'] ?? [])->flatten()->map(fn ($id): string => (string) $id)->unique()->values();
        $ids = collect([...$cartIds, ...$purchasedIds, ...$configured])->unique()->values()->all();
        $products = Product::query()->where('organization_id', $store->organization_id)->where('store_id', $store->id)
            ->whereIn('shopify_product_id', $ids)->with('variants')->get()->keyBy(fn (Product $product): string => (string) $product->shopify_product_id);
        $rows = [];
        foreach ($ids as $id) {
            if (in_array($id, $recommendedIds, true)) {
                continue;
            }
            $reason = in_array($id, $cartIds, true) ? 'already_in_cart'
                : (in_array($id, $purchasedIds, true) ? 'already_purchased'
                    : (in_array($id, $configuration['products']['excluded'] ?? [], true) ? 'excluded_by_strategy'
                        : 'out_of_stock_or_market_unavailable'));
            $rows[] = [
                'shopify_product_id' => $id,
                'title' => $products->get($id)?->title ?? '已移除或未同步商品',
                'reason' => $reason,
            ];
        }

        return $rows;
    }

    /** @param array<string, mixed> $snapshot */
    private function createVersion(
        Store $store,
        PersonalizationRecommendationStrategy $strategy,
        User $actor,
        int $number,
        PersonalizationStrategyVersionStatus $status,
        array $snapshot,
    ): PersonalizationStrategyVersion {
        $configuration = $snapshot['configuration'] ?? $this->defaultConfiguration();

        return PersonalizationStrategyVersion::query()->create([
            'organization_id' => $store->organization_id,
            'store_id' => $store->id,
            'strategy_id' => $strategy->id,
            'version_number' => max(1, $number),
            'status' => $status,
            'name' => $snapshot['name'] ?? $strategy->name,
            'algorithm' => $snapshot['algorithm'] ?? $strategy->algorithm->value,
            'item_limit' => $snapshot['item_limit'] ?? $strategy->item_limit,
            'configuration' => $configuration,
            'checksum' => $this->checksum([
                'name' => $snapshot['name'] ?? $strategy->name,
                'algorithm' => $snapshot['algorithm'] ?? $strategy->algorithm->value,
                'item_limit' => $snapshot['item_limit'] ?? $strategy->item_limit,
                'configuration' => $configuration,
            ]),
            'lock_version' => 1,
            'created_by' => $actor->id,
        ]);
    }

    private function idempotentResult(Store $store, User $actor, string $operation, string $key, string $payloadHash): ?object
    {
        $row = DB::table('personalization_strategy_idempotencies')
            ->where('store_id', $store->id)->where('user_id', $actor->id)
            ->where('operation', $operation)->where('idempotency_key', $key)->lockForUpdate()->first();
        if ($row && ! hash_equals((string) $row->payload_hash, $payloadHash)) {
            throw new PersonalizationException('IDEMPOTENCY_KEY_CONFLICT', '重复请求标识已用于其他内容。', 409);
        }

        return $row;
    }

    private function rememberIdempotency(
        Store $store,
        User $actor,
        string $operation,
        string $key,
        string $payloadHash,
        PersonalizationRecommendationStrategy $strategy,
        PersonalizationStrategyVersion $version,
    ): void {
        DB::table('personalization_strategy_idempotencies')->insert([
            'organization_id' => $store->organization_id,
            'store_id' => $store->id,
            'user_id' => $actor->id,
            'operation' => $operation,
            'idempotency_key' => $key,
            'payload_hash' => $payloadHash,
            'strategy_id' => $strategy->id,
            'strategy_version_id' => $version->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function authorize(Store $store, User $actor, string $permission): void
    {
        $this->shopGuard->assertAllowed((string) $store->shopify_domain);
        $organization = $store->organization;
        if (! $organization instanceof Organization || $store->status !== 'active' || $organization->status !== 'active'
            || ! $actor->canAccessStore($store) || ! $actor->hasPermission($permission, $organization, $store)) {
            throw new PersonalizationException('PERSONALIZATION_ACCESS_DENIED', '无权访问当前店铺的个性化推荐策略。', 403);
        }
    }

    private function assertStrategy(Store $store, PersonalizationRecommendationStrategy $strategy): void
    {
        if ((int) $strategy->organization_id !== (int) $store->organization_id || (int) $strategy->store_id !== (int) $store->id || $strategy->trashed()) {
            throw new PersonalizationException('STRATEGY_NOT_FOUND', '找不到该推荐策略。', 404);
        }
    }

    private function assertVersion(PersonalizationRecommendationStrategy $strategy, PersonalizationStrategyVersion $version): void
    {
        if ((int) $version->strategy_id !== (int) $strategy->id || (int) $version->store_id !== (int) $strategy->store_id) {
            throw new PersonalizationException('STRATEGY_VERSION_NOT_FOUND', '找不到该策略版本。', 404);
        }
    }

    private function checksum(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function uuid(mixed $value, string $code): string
    {
        $value = is_string($value) ? trim($value) : '';
        if (! Str::isUuid($value)) {
            throw new PersonalizationException($code, '请求标识格式无效。');
        }

        return $value;
    }

    private function integer(mixed $value, int $minimum, int $maximum, string $code): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < $minimum || (int) $value > $maximum) {
            throw new PersonalizationException($code, '数值超出允许范围。');
        }

        return (int) $value;
    }

    private function text(mixed $value, int $maximum, string $code): string
    {
        $value = is_string($value) ? trim($value) : '';
        if ($value === '' || mb_strlen($value) > $maximum) {
            throw new PersonalizationException($code, '名称不能为空或超过允许长度。');
        }

        return $value;
    }

    private function optionalText(mixed $value, int $maximum): ?string
    {
        $value = trim((string) ($value ?? ''));
        if (mb_strlen($value) > $maximum) {
            throw new PersonalizationException('INVALID_COMPONENT_COPY', '展示文案超过允许长度。');
        }

        return $value === '' ? null : $value;
    }

    /** @return list<string> */
    private function strings(mixed $values, int $maximum, int $length, string $code): array
    {
        if (! is_array($values) || count($values) > $maximum) {
            throw new PersonalizationException($code, '规则列表超过允许数量。');
        }
        $result = [];
        foreach ($values as $value) {
            $value = trim((string) $value);
            if ($value === '' || mb_strlen($value) > $length) {
                throw new PersonalizationException($code, '规则列表包含无效内容。');
            }
            $result[] = $value;
        }

        return array_values(array_unique($result));
    }

    /** @return list<string> */
    private function numericIds(mixed $values, int $maximum, string $code): array
    {
        if (! is_array($values) || count($values) > $maximum) {
            throw new PersonalizationException($code, 'Shopify ID 列表无效。');
        }

        return array_values(array_unique(array_map(fn ($value): string => $this->numericProductId($value), $values)));
    }

    private function numericProductId(mixed $value): string
    {
        $value = trim((string) ($value ?? ''));
        if (preg_match('#^gid://shopify/(?:Product|Collection)/(\d+)$#', $value, $matches) === 1) {
            return $matches[1];
        }
        if (preg_match('/^\d+$/', $value) !== 1) {
            throw new PersonalizationException('INVALID_SHOPIFY_RESOURCE_ID', 'Shopify 资源 ID 格式无效。');
        }

        return $value;
    }

    private function nullableDecimal(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_numeric($value) || (float) $value < 0 || (float) $value > 999_999_999) {
            throw new PersonalizationException('INVALID_PRICE_RULE', '价格规则数值无效。');
        }

        return number_format((float) $value, 2, '.', '');
    }

    private function placementLabel(PersonalizationPlacement $placement): string
    {
        return match ($placement) {
            PersonalizationPlacement::Homepage => '首页推荐',
            PersonalizationPlacement::ProductPage => '商品页推荐',
            PersonalizationPlacement::CartPage => '购物车推荐',
            PersonalizationPlacement::SmartCart => 'Smart Cart 推荐',
            PersonalizationPlacement::Checkout => 'Checkout 推荐',
        };
    }

    private function audit(Store $store, User $actor, object $subject, string $action, array $metadata): void
    {
        AuditLog::query()->create([
            'organization_id' => $store->organization_id,
            'store_id' => $store->id,
            'user_id' => $actor->id,
            'action' => $action,
            'subject_type' => $subject::class,
            'subject_id' => $subject->id,
            'metadata' => ['scope' => 'store', 'environment' => (string) config('personalization.environment'), ...$metadata],
        ]);
    }
}
