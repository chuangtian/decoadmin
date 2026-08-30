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
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PersonalizationStrategyWorkflowService
{
    public function __construct(private PersonalizationShopGuard $shopGuard) {}

    /** @return list<array<string, mixed>> */
    public function listing(Store $store, User $actor, ?string $search = null): array
    {
        $this->authorize($store, $actor, 'personalization.view');
        $search = trim((string) $search);
        $query = PersonalizationRecommendationStrategy::query()
            ->where('organization_id', $store->organization_id)
            ->where('store_id', $store->id)
            ->with(['publishedVersion', 'versions' => fn ($query) => $query->limit(2), 'components'])
            ->withoutTrashed()
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
                'item_limit' => 24,
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
            // Saving this compact form updates the strategy configuration. It
            // never creates a page binding; components remain the sole control
            // over whether a placement is visible to shoppers.
            $this->applySnapshot($store, $lockedStrategy, $draft, $actor, false);
            $hasLiveComponent = $lockedStrategy->components()
                ->where('status', PersonalizationComponentStatus::Active->value)
                ->exists();
            $settings = is_array($lockedStrategy->settings) ? $lockedStrategy->settings : [];
            $settings['discount'] = $normalized['configuration']['discount'];
            $settings['recommendation_rule'] = $normalized['configuration']['recommendation_rule'];
            $lockedStrategy->forceFill([
                'name' => $normalized['name'],
                'algorithm' => $normalized['algorithm'],
                'item_limit' => 24,
                'enabled' => $hasLiveComponent,
                'status' => $hasLiveComponent
                    ? PersonalizationStrategyStatus::Enabled
                    : ($lockedStrategy->published_version_id ? PersonalizationStrategyStatus::Disabled : PersonalizationStrategyStatus::Draft),
                'settings' => $settings,
                'updated_by' => $actor->id,
            ])->save();
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

    /** @return array{deleted: bool, already_deleted: bool, detached_component_count: int} */
    public function deleteStrategy(Store $store, string $strategyUuid, User $actor, string $idempotencyKey): array
    {
        $this->authorize($store, $actor, 'personalization.manage');
        $strategyUuid = $this->uuid($strategyUuid, 'INVALID_STRATEGY_UUID');
        $idempotencyKey = $this->uuid($idempotencyKey, 'INVALID_IDEMPOTENCY_KEY');

        return DB::transaction(function () use ($store, $strategyUuid, $actor, $idempotencyKey): array {
            $existingDeletion = DB::table('personalization_strategy_deletions')
                ->where('organization_id', $store->organization_id)
                ->where('store_id', $store->id)
                ->where('strategy_uuid', $strategyUuid)
                ->lockForUpdate()
                ->first();
            if ($existingDeletion) {
                return [
                    'deleted' => true,
                    'already_deleted' => true,
                    'detached_component_count' => (int) $existingDeletion->detached_component_count,
                ];
            }

            $strategy = PersonalizationRecommendationStrategy::query()
                ->where('organization_id', $store->organization_id)
                ->where('store_id', $store->id)
                ->where('uuid', $strategyUuid)
                ->lockForUpdate()
                ->first();
            if (! $strategy) {
                throw new PersonalizationException('STRATEGY_NOT_FOUND', '找不到该推荐策略。', 404);
            }

            $components = PersonalizationRecommendationComponent::withTrashed()
                ->where('organization_id', $store->organization_id)
                ->where('store_id', $store->id)
                ->where('strategy_id', $strategy->id)
                ->lockForUpdate()
                ->get();
            $componentCount = $components->count();

            $this->audit($store, $actor, $strategy, 'personalization_strategy_deleted', [
                'strategy_uuid' => $strategy->uuid,
                'strategy_name' => $strategy->name,
                'detached_component_count' => $componentCount,
                'irreversible' => true,
            ]);
            DB::table('personalization_strategy_deletions')->insert([
                'organization_id' => $store->organization_id,
                'store_id' => $store->id,
                'strategy_uuid' => $strategy->uuid,
                'strategy_name' => $strategy->name,
                'idempotency_key' => $idempotencyKey,
                'detached_component_count' => $componentCount,
                'deleted_by' => $actor->id,
                'deleted_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Foreign keys preserve historical event and attribution rows by
            // nulling live references. Components are removed so storefront and
            // Checkout endpoints safely return no recommendation instead of a
            // broken configuration.
            foreach ($components as $component) {
                $component->style()->delete();
                $component->forceDelete();
            }
            $strategy->forceFill([
                'enabled' => false,
                'published_version_id' => null,
                'updated_by' => $actor->id,
            ])->save();
            $strategy->forceDelete();

            return ['deleted' => true, 'already_deleted' => false, 'detached_component_count' => $componentCount];
        });
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
        $recommendationRule = data_get($draft?->configuration, 'recommendation_rule')
            ?? data_get($strategy->settings, 'recommendation_rule')
            ?? [
                'mode' => 'preset',
                'preset' => ($draft?->algorithm ?? $strategy->algorithm)->value,
                'custom' => ['rules' => []],
            ];

        return [
            'uuid' => $strategy->uuid,
            'name' => $draft?->name ?? $strategy->name,
            'technical_id' => $strategy->uuid,
            'status' => $status,
            'algorithm' => ($draft?->algorithm ?? $strategy->algorithm)->value,
            'recommendation_mode' => $recommendationRule['mode'] ?? 'preset',
            'custom_rule_count' => count($recommendationRule['custom']['rules'] ?? []),
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
            'recommendation_rule' => [
                'mode' => 'preset',
                'preset' => PersonalizationAlgorithm::Manual->value,
                'custom' => [
                    'rules' => [],
                    'fallback' => [
                        'enabled' => false,
                        'action' => ['type' => 'manual', 'products' => [], 'filters' => []],
                    ],
                ],
            ],
            'rules' => [
                'include_tags' => [],
                'exclude_tags' => [],
                'include_collection_ids' => [],
                'exclude_collection_ids' => [],
                'exclude_vendors' => [],
                'exclude_purchase_options' => [],
                'minimum_price' => null,
                'maximum_price' => null,
                'minimum_inventory' => null,
                'in_stock_only' => true,
                'exclude_cart_products' => true,
                'exclude_purchased_products' => true,
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
        $storedRecommendationRule = is_array($strategy->settings)
            ? ($strategy->settings['recommendation_rule'] ?? null)
            : null;
        $configuration['recommendation_rule'] = is_array($storedRecommendationRule)
            ? $storedRecommendationRule
            : [
                ...$configuration['recommendation_rule'],
                'preset' => $strategy->algorithm->value,
            ];
        foreach ($strategy->rules as $rule) {
            $configuration['rules'][$rule->type->value] = match ($rule->type) {
                PersonalizationRuleType::IncludeTags, PersonalizationRuleType::ExcludeTags => $rule->value['tags'] ?? [],
                PersonalizationRuleType::IncludeCollections => $rule->value['collection_ids'] ?? [],
                PersonalizationRuleType::ExcludeCollections => $rule->value['collection_ids'] ?? [],
                PersonalizationRuleType::ExcludeVendors => $rule->value['vendors'] ?? [],
                PersonalizationRuleType::ExcludePurchaseOptions => $rule->value['purchase_options'] ?? [],
                PersonalizationRuleType::MinimumPrice, PersonalizationRuleType::MaximumPrice => $rule->value['amount'] ?? null,
                PersonalizationRuleType::MinimumInventory => $rule->value['quantity'] ?? null,
                PersonalizationRuleType::InStockOnly,
                PersonalizationRuleType::ExcludeCartProducts,
                PersonalizationRuleType::ExcludePurchasedProducts => (bool) ($rule->value['enabled'] ?? false),
            };
        }
        foreach (PersonalizationProductOverrideType::cases() as $type) {
            $overrides = $strategy->productOverrides->where('type', $type)->sortBy('position')->values();
            $configuration['products'][$type->value] = $type === PersonalizationProductOverrideType::Excluded
                ? $overrides->pluck('shopify_product_id')->map(fn ($id): string => (string) $id)->values()->all()
                : $overrides->map(fn (PersonalizationStrategyProductOverride $override): array => [
                    'shopify_product_id' => (string) $override->shopify_product_id,
                    'product_gid' => $override->shopify_product_gid
                        ?: 'gid://shopify/Product/'.$override->shopify_product_id,
                    'variant_gid' => $override->shopify_variant_gid ?: '',
                    'minimum_quantity' => max(1, (int) $override->minimum_quantity),
                    'selected_at' => $override->selected_at?->toIso8601String()
                        ?? $override->created_at?->toIso8601String()
                        ?? now()->toIso8601String(),
                    'position' => max(1, (int) $override->position),
                ])->all();
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
        $itemLimit = 24;
        $configuration = $draft['configuration'] ?? null;
        if (! is_array($configuration) || array_is_list($configuration)
            || array_diff(array_keys($configuration), ['recommendation_rule', 'rules', 'products', 'discount', 'placements', 'checkout']) !== []) {
            throw new PersonalizationException('INVALID_STRATEGY_CONFIGURATION', '策略配置格式无效。');
        }
        $recommendationRule = $this->normalizeRecommendationRule(
            $store,
            $configuration['recommendation_rule'] ?? $this->defaultConfiguration()['recommendation_rule'],
        );
        $algorithm = $recommendationRule['mode'] === 'preset'
            ? PersonalizationAlgorithm::from($recommendationRule['preset'])
            : PersonalizationAlgorithm::Manual;
        $products = $this->normalizeProducts($store, $configuration['products'] ?? []);
        if ($recommendationRule['mode'] === 'custom') {
            $candidateIds = collect($recommendationRule['custom']['rules'])
                ->flatMap(fn (array $rule): array => array_column($rule['action']['products'], 'shopify_product_id'))
                ->merge(array_column($recommendationRule['custom']['fallback']['action']['products'], 'shopify_product_id'))
                ->merge(array_column($products['pinned'], 'shopify_product_id'))
                ->unique();
            if ($candidateIds->count() > 24) {
                throw new PersonalizationException('TOO_MANY_CUSTOM_RULE_PRODUCTS', '自定义规则与置顶产品合计最多 24 种。');
            }
        }

        return [
            'name' => $name,
            'algorithm' => $algorithm->value,
            'item_limit' => $itemLimit,
            'configuration' => [
                'recommendation_rule' => $recommendationRule,
                'rules' => $this->normalizeRules($configuration['rules'] ?? []),
                'products' => $products,
                'discount' => $this->normalizeDiscount($configuration['discount'] ?? []),
                'placements' => $this->normalizePlacements($configuration['placements'] ?? []),
                'checkout' => $this->normalizeCheckout($configuration['checkout'] ?? []),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function normalizeRecommendationRule(Store $store, mixed $recommendationRule): array
    {
        if (! is_array($recommendationRule) || array_is_list($recommendationRule)
            || array_diff(array_keys($recommendationRule), ['mode', 'preset', 'custom']) !== []) {
            throw new PersonalizationException('INVALID_RECOMMENDATION_RULE_SET', '推荐规则集格式无效。');
        }
        $mode = (string) ($recommendationRule['mode'] ?? 'preset');
        if (! in_array($mode, ['preset', 'custom'], true)) {
            throw new PersonalizationException('INVALID_RECOMMENDATION_RULE_MODE', '推荐规则模式无效。');
        }
        $preset = PersonalizationAlgorithm::tryFrom((string) ($recommendationRule['preset'] ?? 'manual'));
        if (! $preset) {
            throw new PersonalizationException('INVALID_PRESET_RECOMMENDATION_RULE', '预设推荐规则无效。');
        }
        $custom = $recommendationRule['custom'] ?? [];
        if ($custom === []) {
            $custom = $this->defaultConfiguration()['recommendation_rule']['custom'];
        }
        if (! is_array($custom) || array_is_list($custom)
            || array_diff(array_keys($custom), ['rules', 'fallback']) !== []) {
            throw new PersonalizationException('INVALID_CUSTOM_RULE_SET', '自定义规则集格式无效。');
        }
        $rules = $custom['rules'] ?? [];
        if (! is_array($rules) || count($rules) > 20) {
            throw new PersonalizationException('INVALID_CUSTOM_RULE_SET', '自定义规则最多可以添加 20 条。');
        }
        $normalizedRules = [];
        $seenRuleIds = [];
        foreach (array_values($rules) as $position => $rule) {
            if (! is_array($rule) || array_is_list($rule)
                || array_diff(array_keys($rule), ['id', 'name', 'priority', 'match', 'conditions', 'exit_on_match', 'action']) !== []) {
                throw new PersonalizationException('INVALID_CUSTOM_RULE', '自定义规则格式无效。');
            }
            $id = $this->uuid($rule['id'] ?? null, 'INVALID_CUSTOM_RULE_ID');
            if (isset($seenRuleIds[$id])) {
                throw new PersonalizationException('DUPLICATE_CUSTOM_RULE_ID', '自定义规则 ID 不能重复。');
            }
            $seenRuleIds[$id] = true;
            $match = (string) ($rule['match'] ?? 'all');
            if (! in_array($match, ['all', 'any'], true)) {
                throw new PersonalizationException('INVALID_CUSTOM_RULE_MATCH', '条件组合只能选择“和”或“或”。');
            }
            $normalizedRules[] = [
                'id' => $id,
                'name' => $this->text($rule['name'] ?? null, 50, 'CUSTOM_RULE_NAME_REQUIRED'),
                'priority' => $position + 1,
                'match' => $match,
                'conditions' => $this->normalizeCustomConditions($store, $rule['conditions'] ?? []),
                'exit_on_match' => (bool) ($rule['exit_on_match'] ?? false),
                'action' => $this->normalizeCustomAction($store, $rule['action'] ?? []),
            ];
        }
        $fallback = $custom['fallback'] ?? [];
        if ($fallback === []) {
            $fallback = $this->defaultConfiguration()['recommendation_rule']['custom']['fallback'];
        }
        if (! is_array($fallback) || array_is_list($fallback)
            || array_diff(array_keys($fallback), ['enabled', 'action']) !== []) {
            throw new PersonalizationException('INVALID_CUSTOM_FALLBACK', '备用规则格式无效。');
        }
        $normalizedFallbackAction = $this->normalizeCustomAction($store, $fallback['action'] ?? []);
        $candidateIds = collect($normalizedRules)
            ->flatMap(fn (array $rule): array => array_column($rule['action']['products'], 'shopify_product_id'))
            ->merge(array_column($normalizedFallbackAction['products'], 'shopify_product_id'))
            ->unique()->values();
        if ($candidateIds->count() > 24) {
            throw new PersonalizationException('TOO_MANY_CUSTOM_RULE_PRODUCTS', '一份自定义策略最多可以配置 24 种推荐商品。');
        }

        return [
            'mode' => $mode,
            'preset' => $preset->value,
            'custom' => [
                'rules' => $normalizedRules,
                'fallback' => [
                    'enabled' => (bool) ($fallback['enabled'] ?? false),
                    'action' => $normalizedFallbackAction,
                ],
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function normalizeCustomConditions(Store $store, mixed $conditions): array
    {
        if (! is_array($conditions) || count($conditions) > 10) {
            throw new PersonalizationException('INVALID_CUSTOM_RULE_CONDITIONS', '每条规则最多可以添加 10 个条件。');
        }
        $allowedFields = ['cart_product_ids', 'cart_collection_ids', 'cart_tags', 'cart_vendors'];
        $result = [];
        $seen = [];
        foreach (array_values($conditions) as $condition) {
            if (! is_array($condition) || array_is_list($condition)
                || array_diff(array_keys($condition), ['id', 'field', 'operator', 'values']) !== []) {
                throw new PersonalizationException('INVALID_CUSTOM_RULE_CONDITION', '自定义规则条件格式无效。');
            }
            $id = $this->uuid($condition['id'] ?? null, 'INVALID_CUSTOM_CONDITION_ID');
            if (isset($seen[$id])) {
                throw new PersonalizationException('DUPLICATE_CUSTOM_CONDITION_ID', '条件 ID 不能重复。');
            }
            $seen[$id] = true;
            $field = (string) ($condition['field'] ?? 'cart_product_ids');
            $operator = (string) ($condition['operator'] ?? 'contains_any');
            if (! in_array($field, $allowedFields, true)
                || ! in_array($operator, ['contains_any', 'contains_all', 'contains_none'], true)) {
                throw new PersonalizationException('INVALID_CUSTOM_RULE_CONDITION', '自定义规则字段或运算符无效。');
            }
            $values = match ($field) {
                'cart_product_ids' => $this->numericIds($condition['values'] ?? [], 24, 'INVALID_CUSTOM_CONDITION_PRODUCTS'),
                'cart_collection_ids' => $this->numericIds($condition['values'] ?? [], 100, 'INVALID_CUSTOM_CONDITION_COLLECTIONS'),
                default => $this->strings($condition['values'] ?? [], 100, 120, 'INVALID_CUSTOM_CONDITION_VALUES'),
            };
            $this->assertCustomValuesOwned($store, $field, $values);
            $result[] = ['id' => $id, 'field' => $field, 'operator' => $operator, 'values' => $values];
        }

        return $result;
    }

    /** @return array{type: string, products: list<array{shopify_product_id: string, minimum_quantity: int}>, filters: list<array<string, mixed>>} */
    private function normalizeCustomAction(Store $store, mixed $action): array
    {
        if ($action === []) {
            $action = ['type' => 'manual', 'products' => [], 'filters' => []];
        }
        if (! is_array($action) || array_is_list($action)
            || array_diff(array_keys($action), ['type', 'products', 'filters']) !== []) {
            throw new PersonalizationException('INVALID_CUSTOM_RULE_ACTION', '自定义规则行动格式无效。');
        }
        if (($action['type'] ?? 'manual') !== 'manual') {
            throw new PersonalizationException('INVALID_CUSTOM_RULE_ACTION', '当前仅支持手动选择行动。');
        }
        $products = $this->productSelections($store, $action['products'] ?? [], 24);
        $filters = $action['filters'] ?? [];
        if (! is_array($filters) || count($filters) > 10) {
            throw new PersonalizationException('INVALID_CUSTOM_ACTION_FILTERS', '每个行动最多可以添加 10 个筛选条件。');
        }
        $normalizedFilters = [];
        foreach (array_values($filters) as $filter) {
            if (! is_array($filter) || array_is_list($filter)
                || array_diff(array_keys($filter), ['id', 'field', 'operator', 'values']) !== []) {
                throw new PersonalizationException('INVALID_CUSTOM_ACTION_FILTER', '行动筛选条件格式无效。');
            }
            $field = (string) ($filter['field'] ?? 'product_tags');
            $operator = (string) ($filter['operator'] ?? 'contains_any');
            if (! in_array($field, ['product_tags', 'product_collections', 'product_vendors'], true)
                || ! in_array($operator, ['contains_any', 'contains_all', 'contains_none'], true)) {
                throw new PersonalizationException('INVALID_CUSTOM_ACTION_FILTER', '行动筛选字段或运算符无效。');
            }
            $values = $field === 'product_collections'
                ? $this->numericIds($filter['values'] ?? [], 100, 'INVALID_CUSTOM_ACTION_FILTER')
                : $this->strings($filter['values'] ?? [], 100, 120, 'INVALID_CUSTOM_ACTION_FILTER');
            if ($field === 'product_collections') {
                $this->assertCustomValuesOwned($store, 'cart_collection_ids', $values);
            }
            $normalizedFilters[] = [
                'id' => $this->uuid($filter['id'] ?? null, 'INVALID_CUSTOM_FILTER_ID'),
                'field' => $field,
                'operator' => $operator,
                'values' => $values,
            ];
        }

        return ['type' => 'manual', 'products' => $products, 'filters' => $normalizedFilters];
    }

    /** @param list<string> $values */
    private function assertCustomValuesOwned(Store $store, string $field, array $values): void
    {
        if ($values === []) {
            return;
        }
        if ($field === 'cart_product_ids') {
            $this->assertProductIdsOwned($store, $values);
        }
        if ($field === 'cart_collection_ids' && ProductCollection::query()
            ->where('organization_id', $store->organization_id)
            ->where('store_id', $store->id)
            ->whereIn('shopify_collection_id', $values)
            ->count() !== count(array_unique($values))) {
            throw new PersonalizationException('CUSTOM_COLLECTION_NOT_FOUND', '部分自定义规则集合不属于当前店铺或尚未同步。', 404);
        }
    }

    /** @param list<string> $productIds */
    private function assertProductIdsOwned(Store $store, array $productIds): void
    {
        $productIds = array_values(array_unique($productIds));
        if ($productIds !== [] && Product::query()
            ->where('organization_id', $store->organization_id)
            ->where('store_id', $store->id)
            ->whereIn('shopify_product_id', $productIds)
            ->count() !== count($productIds)) {
            throw new PersonalizationException('CUSTOM_PRODUCT_NOT_FOUND', '部分自定义规则商品不属于当前店铺或尚未同步。', 404);
        }
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
            'exclude_purchase_options' => $this->strings($rules['exclude_purchase_options'] ?? [], 100, 160, 'INVALID_PURCHASE_OPTION_RULE'),
            'minimum_price' => $this->nullableDecimal($rules['minimum_price'] ?? null),
            'maximum_price' => $this->nullableDecimal($rules['maximum_price'] ?? null),
            'minimum_inventory' => ($rules['minimum_inventory'] ?? null) === null || $rules['minimum_inventory'] === ''
                ? null : $this->integer($rules['minimum_inventory'], 0, 1_000_000, 'INVALID_INVENTORY_RULE'),
            'in_stock_only' => (bool) ($rules['in_stock_only'] ?? true),
            'exclude_cart_products' => (bool) ($rules['exclude_cart_products'] ?? true),
            'exclude_purchased_products' => (bool) ($rules['exclude_purchased_products'] ?? true),
        ];
    }

    /**
     * @return array{
     *   manual: list<array{shopify_product_id: string, minimum_quantity: int}>,
     *   pinned: list<array{shopify_product_id: string, minimum_quantity: int}>,
     *   excluded: list<string>
     * }
     */
    private function normalizeProducts(Store $store, mixed $products): array
    {
        if (! is_array($products) || array_is_list($products)
            || array_diff(array_keys($products), ['manual', 'pinned', 'excluded']) !== []) {
            throw new PersonalizationException('INVALID_PRODUCT_OVERRIDE', '推荐商品配置格式无效。');
        }
        $manual = $this->productSelections($store, $products['manual'] ?? [], 24);
        $pinned = $this->productSelections($store, $products['pinned'] ?? [], 24);
        $excluded = $this->numericIds($products['excluded'] ?? [], 100, 'INVALID_PRODUCT_OVERRIDE');

        // A pinned product is also a candidate. Add missing pinned products to
        // the end of the manual list so the merchant never has to select twice.
        $manualIds = array_column($manual, 'shopify_product_id');
        foreach ($pinned as $selection) {
            if (! in_array($selection['shopify_product_id'], $manualIds, true)) {
                if (count($manual) >= 24) {
                    throw new PersonalizationException('TOO_MANY_MANUAL_PRODUCTS', '手动推荐商品最多可以选择 24 种。');
                }
                $manual[] = $selection;
                $manualIds[] = $selection['shopify_product_id'];
            }
        }
        $recommendedIds = array_values(array_unique([...$manualIds, ...array_column($pinned, 'shopify_product_id')]));
        if (array_intersect($recommendedIds, $excluded) !== []) {
            throw new PersonalizationException('CONFLICTING_PRODUCT_OVERRIDE', '同一商品不能同时被推荐和排除。');
        }
        $seen = array_values(array_unique([...$recommendedIds, ...$excluded]));
        if ($seen !== []) {
            $owned = Product::query()->where('organization_id', $store->organization_id)->where('store_id', $store->id)
                ->whereIn('shopify_product_id', $seen)->count();
            if ($owned !== count($seen)) {
                throw new PersonalizationException('PRODUCT_OVERRIDE_NOT_FOUND', '部分商品不属于当前店铺或尚未同步。', 404);
            }
        }

        foreach ($manual as $position => &$selection) {
            $selection['position'] = $position + 1;
        }
        unset($selection);
        foreach ($pinned as $position => &$selection) {
            $selection['position'] = $position + 1;
        }
        unset($selection);

        return ['manual' => $manual, 'pinned' => $pinned, 'excluded' => $excluded];
    }

    /** @return list<array{shopify_product_id: string, product_gid: string, variant_gid: string, minimum_quantity: int, selected_at: string, position: int}> */
    private function productSelections(Store $store, mixed $values, int $maximum): array
    {
        if (! is_array($values) || count($values) > $maximum) {
            throw new PersonalizationException('INVALID_PRODUCT_OVERRIDE', "手动推荐商品最多可以选择 {$maximum} 种。");
        }
        $rows = [];
        $seen = [];
        foreach ($values as $value) {
            $row = is_array($value) && ! array_is_list($value)
                ? $value
                : ['shopify_product_id' => $value, 'minimum_quantity' => 1];
            if (array_diff(array_keys($row), [
                'shopify_product_id', 'product_gid', 'variant_gid', 'minimum_quantity', 'selected_at', 'position',
            ]) !== []) {
                throw new PersonalizationException('INVALID_PRODUCT_OVERRIDE', '推荐商品配置包含不支持的字段。');
            }
            $productId = $this->numericProductId($row['shopify_product_id'] ?? null);
            if (isset($seen[$productId])) {
                continue;
            }
            $seen[$productId] = true;
            $rows[] = [...$row, 'shopify_product_id' => $productId];
        }

        $products = Product::query()
            ->where('organization_id', $store->organization_id)
            ->where('store_id', $store->id)
            ->whereIn('shopify_product_id', array_keys($seen))
            ->with(['variants' => fn ($query) => $query->orderByDesc('available_for_sale')->orderBy('id')])
            ->get()->keyBy(fn (Product $product): string => (string) $product->shopify_product_id);
        if ($products->count() !== count($seen)) {
            throw new PersonalizationException('CUSTOM_PRODUCT_NOT_FOUND', '部分推荐商品不属于当前店铺或尚未同步。', 404);
        }

        $result = [];
        foreach ($rows as $position => $row) {
            $productId = (string) $row['shopify_product_id'];
            $product = $products->get($productId);
            if (! $product instanceof Product || $product->variants->isEmpty()) {
                throw new PersonalizationException('PRODUCT_VARIANT_NOT_FOUND', '推荐商品缺少可用的 Shopify 变体。', 409);
            }
            $variantGid = trim((string) ($row['variant_gid'] ?? ''));
            $variantId = preg_match('#^gid://shopify/ProductVariant/(\d+)$#', $variantGid, $matches) === 1
                ? $matches[1]
                : '';
            $variant = $variantId !== ''
                ? $product->variants->firstWhere('shopify_variant_id', $variantId)
                : $product->variants->first();
            if (! $variant) {
                throw new PersonalizationException('PRODUCT_VARIANT_NOT_FOUND', '所选变体不属于当前店铺商品。', 404);
            }
            $selectedAt = trim((string) ($row['selected_at'] ?? ''));
            try {
                $selectedAt = $selectedAt === ''
                    ? now()->toIso8601String()
                    : CarbonImmutable::parse($selectedAt)->utc()->toIso8601String();
            } catch (\Throwable) {
                throw new PersonalizationException('INVALID_PRODUCT_SELECTED_AT', '商品选择时间格式无效。');
            }
            $result[] = [
                'shopify_product_id' => $productId,
                'product_gid' => 'gid://shopify/Product/'.$productId,
                'variant_gid' => 'gid://shopify/ProductVariant/'.$variant->shopify_variant_id,
                'minimum_quantity' => $this->integer($row['minimum_quantity'] ?? 1, 1, 999, 'INVALID_MINIMUM_PURCHASE_QUANTITY'),
                'selected_at' => $selectedAt,
                'position' => $position + 1,
            ];
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function normalizeDiscount(mixed $discount): array
    {
        if (! is_array($discount) || array_is_list($discount)
            || array_diff(array_keys($discount), ['enabled', 'reference', 'title', 'summary', 'code', 'status', 'percentage', 'validated_at']) !== []) {
            throw new PersonalizationException('INVALID_DISCOUNT_REFERENCE', '优惠关联配置无效。');
        }
        $enabled = (bool) ($discount['enabled'] ?? false);
        $reference = trim((string) ($discount['reference'] ?? ''));
        if (mb_strlen($reference) > 255 || ($enabled && $reference === '')) {
            throw new PersonalizationException('INVALID_DISCOUNT_REFERENCE', '启用优惠时必须选择或填写已有折扣引用。');
        }
        $status = strtolower(trim((string) ($discount['status'] ?? 'unknown')));
        if (! in_array($status, ['active', 'scheduled', 'expired', 'unknown'], true)) {
            $status = 'unknown';
        }
        $validatedAt = trim((string) ($discount['validated_at'] ?? ''));
        try {
            $validatedAt = $validatedAt === '' ? null : CarbonImmutable::parse($validatedAt)->utc()->toIso8601String();
        } catch (\Throwable) {
            throw new PersonalizationException('INVALID_DISCOUNT_REFERENCE', '折扣校验时间格式无效。');
        }
        $percentage = $discount['percentage'] ?? null;
        if ($percentage !== null && (! is_numeric($percentage) || (float) $percentage <= 0 || (float) $percentage >= 100)) {
            throw new PersonalizationException('INVALID_DISCOUNT_REFERENCE', '折扣百分比必须大于 0 且小于 100。');
        }

        return [
            'enabled' => $enabled,
            'reference' => $reference ?: null,
            'title' => $this->optionalText($discount['title'] ?? null, 80),
            'summary' => $this->optionalText($discount['summary'] ?? null, 160),
            'code' => $this->optionalText($discount['code'] ?? null, 80),
            'status' => $status,
            'percentage' => $percentage === null ? null : round((float) $percentage, 4),
            'validated_at' => $validatedAt,
        ];
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
        $recommendationRule = $configuration['recommendation_rule'] ?? $this->defaultConfiguration()['recommendation_rule'];
        $customProducts = collect($recommendationRule['custom']['rules'] ?? [])
            ->flatMap(fn (array $rule): array => $rule['action']['products'] ?? [])
            ->merge($recommendationRule['custom']['fallback']['action']['products'] ?? [])
            ->filter(fn ($selection): bool => is_array($selection) && filled($selection['shopify_product_id'] ?? null));
        if (($recommendationRule['mode'] ?? 'preset') === 'custom' && $customProducts->isEmpty()) {
            throw new PersonalizationException('CUSTOM_RULE_PRODUCTS_REQUIRED', '自定义规则至少需要一个行动商品或备用商品。', 409);
        }
        if (($recommendationRule['mode'] ?? 'preset') === 'preset'
            && $version->algorithm === PersonalizationAlgorithm::Manual
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
        $productIds = collect([
            ...array_column($configuration['products']['manual'] ?? [], 'shopify_product_id'),
            ...array_column($configuration['products']['pinned'] ?? [], 'shopify_product_id'),
            ...($configuration['products']['excluded'] ?? []),
        ])->map(fn ($id): string => (string) $id)->unique()->values()->all();
        $owned = Product::query()->where('organization_id', $store->organization_id)->where('store_id', $store->id)
            ->whereIn('shopify_product_id', $productIds)->pluck('id', 'shopify_product_id');
        foreach (['manual', 'pinned', 'excluded'] as $type) {
            foreach (($configuration['products'][$type] ?? []) as $position => $selection) {
                $productId = is_array($selection) ? (string) ($selection['shopify_product_id'] ?? '') : (string) $selection;
                PersonalizationStrategyProductOverride::query()->create([
                    'organization_id' => $store->organization_id,
                    'store_id' => $store->id,
                    'strategy_id' => $strategy->id,
                    'product_id' => $owned->get($productId),
                    'shopify_product_id' => $productId,
                    'shopify_product_gid' => is_array($selection)
                        ? ($selection['product_gid'] ?? 'gid://shopify/Product/'.$productId)
                        : 'gid://shopify/Product/'.$productId,
                    'shopify_variant_gid' => is_array($selection) ? ($selection['variant_gid'] ?? null) : null,
                    'type' => $type,
                    'position' => $position + 1,
                    'minimum_quantity' => is_array($selection) ? max(1, (int) ($selection['minimum_quantity'] ?? 1)) : 1,
                    'selected_at' => is_array($selection) ? ($selection['selected_at'] ?? now()) : now(),
                ]);
            }
        }
        $settings = is_array($strategy->settings) ? $strategy->settings : [];
        $settings['recommendation_rule'] = $configuration['recommendation_rule']
            ?? $this->defaultConfiguration()['recommendation_rule'];
        $settings['discount'] = $configuration['discount'] ?? ['enabled' => false, 'reference' => null];
        $strategy->forceFill(['settings' => $settings])->save();

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
            'exclude_purchase_options' => ['type' => PersonalizationRuleType::ExcludePurchaseOptions->value, 'key' => 'purchase_options'],
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
            'settings' => [
                'recommendation_rule' => $version->configuration['recommendation_rule']
                    ?? $this->defaultConfiguration()['recommendation_rule'],
            ],
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
            foreach (($version->configuration['products'][$type] ?? []) as $position => $selection) {
                $productId = is_array($selection) ? (string) ($selection['shopify_product_id'] ?? '') : (string) $selection;
                $override = new PersonalizationStrategyProductOverride([
                    'strategy_id' => $strategy->id,
                    'shopify_product_id' => $productId,
                    'shopify_product_gid' => is_array($selection)
                        ? ($selection['product_gid'] ?? 'gid://shopify/Product/'.$productId)
                        : 'gid://shopify/Product/'.$productId,
                    'shopify_variant_gid' => is_array($selection) ? ($selection['variant_gid'] ?? null) : null,
                    'type' => $type,
                    'position' => $position + 1,
                    'minimum_quantity' => is_array($selection) ? max(1, (int) ($selection['minimum_quantity'] ?? 1)) : 1,
                    'selected_at' => is_array($selection) ? ($selection['selected_at'] ?? now()) : now(),
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
        $configured = collect([
            ...array_column($configuration['products']['manual'] ?? [], 'shopify_product_id'),
            ...array_column($configuration['products']['pinned'] ?? [], 'shopify_product_id'),
            ...($configuration['products']['excluded'] ?? []),
        ])->map(fn ($id): string => (string) $id)->unique()->values();
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
