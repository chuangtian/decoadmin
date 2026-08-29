<?php

namespace Tests\Feature;

use App\Exceptions\PersonalizationException;
use App\Models\Organization;
use App\Models\PersonalizationEvent;
use App\Models\PersonalizationEventSource;
use App\Models\PersonalizationRecommendationComponent;
use App\Models\PersonalizationRecommendationStrategy;
use App\Models\PersonalizationStrategyVersion;
use App\Models\Product;
use App\Models\ProductCollection;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\Personalization\PersonalizationAnalyticsService;
use App\Services\Personalization\PersonalizationEventIngestionService;
use App\Services\Personalization\PersonalizationRecommendationService;
use App\Services\Personalization\PersonalizationStrategyWorkflowService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PersonalizationStrategyWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'personalization.environment' => 'test',
            'personalization.denied_shop_domains' => ['macfoxebike.myshopify.com'],
        ]);
    }

    public function test_compact_editor_autosave_is_idempotent_and_updates_strategy_without_creating_bindings(): void
    {
        [$actor, $organization, $store] = $this->context();
        $this->product($organization, $store, 101, 'Helmet');
        $service = app(PersonalizationStrategyWorkflowService::class);
        $createKey = (string) Str::uuid();
        $created = $service->createDraft($store, $actor, $createKey);
        $duplicateCreate = $service->createDraft($store, $actor, $createKey);

        $this->assertSame($created['strategy']['uuid'], $duplicateCreate['strategy']['uuid']);
        $draft = $created['draft'];
        $draft['name'] = 'Published name';
        $draft['configuration']['products']['manual'] = ['101'];
        $draft['configuration']['placements'] = [$this->placement('homepage')];
        $saveKey = (string) Str::uuid();
        $saved = $service->autosave($store, PersonalizationRecommendationStrategy::query()->sole(), $actor, [
            'idempotency_key' => $saveKey,
            'lock_version' => $draft['lock_version'],
            'draft' => $draft,
        ]);
        $sameSave = $service->autosave($store, PersonalizationRecommendationStrategy::query()->sole(), $actor, [
            'idempotency_key' => $saveKey,
            'lock_version' => $draft['lock_version'],
            'draft' => $draft,
        ]);
        $this->assertSame($saved['draft']['lock_version'], $sameSave['draft']['lock_version']);
        $selection = $saved['draft']['configuration']['products']['manual'][0];
        $this->assertSame('gid://shopify/Product/101', $selection['product_gid']);
        $this->assertSame('gid://shopify/ProductVariant/1010', $selection['variant_gid']);
        $this->assertSame(1, $selection['minimum_quantity']);
        $this->assertSame(1, $selection['position']);
        $this->assertNotEmpty($selection['selected_at']);

        $strategy = PersonalizationRecommendationStrategy::query()->sole();
        $published = $service->publish($store, $strategy, $actor, [
            'idempotency_key' => (string) Str::uuid(),
            'lock_version' => $saved['draft']['lock_version'],
            'confirm_replacements' => false,
        ]);
        $this->assertSame('Published name', $strategy->fresh()->name);
        $this->assertSame('enabled', $published['strategy']['status']);
        $this->assertDatabaseHas('personalization_recommendation_components', [
            'strategy_id' => $strategy->id,
            'status' => 'active',
            'placement' => 'homepage',
        ]);

        $editor = $service->editor($store, $strategy->fresh(), $actor);
        $this->assertSame(2, $editor['draft']['version_number']);
        $editor['draft']['name'] = 'Unpublished edit';
        $service->autosave($store, $strategy->fresh(), $actor, [
            'idempotency_key' => (string) Str::uuid(),
            'lock_version' => $editor['draft']['lock_version'],
            'draft' => $editor['draft'],
        ]);
        $this->assertSame('Unpublished edit', $strategy->fresh()->name);
        $this->assertSame('Unpublished edit', PersonalizationStrategyVersion::query()->where('status', 'draft')->sole()->name);
        $this->assertSame('active', $strategy->components()->sole()->fresh()->status->value);
    }

    public function test_publish_requires_explicit_replacement_and_restore_creates_new_version(): void
    {
        [$actor, $organization, $store] = $this->context();
        $this->product($organization, $store, 201, 'Light');
        $service = app(PersonalizationStrategyWorkflowService::class);
        $first = $this->publishedStrategy($service, $store, $actor, 'First', '201', 'homepage');
        $secondCreated = $service->createDraft($store, $actor, (string) Str::uuid());
        $second = PersonalizationRecommendationStrategy::query()->where('uuid', $secondCreated['strategy']['uuid'])->firstOrFail();
        $draft = $secondCreated['draft'];
        $draft['name'] = 'Second';
        $draft['configuration']['products']['manual'] = ['201'];
        $draft['configuration']['placements'] = [$this->placement('homepage')];
        $saved = $service->autosave($store, $second, $actor, [
            'idempotency_key' => (string) Str::uuid(), 'lock_version' => 1, 'draft' => $draft,
        ]);

        $this->assertExceptionCode('PLACEMENT_REPLACEMENT_CONFIRMATION_REQUIRED', fn () => $service->publish($store, $second, $actor, [
            'idempotency_key' => (string) Str::uuid(), 'lock_version' => $saved['draft']['lock_version'], 'confirm_replacements' => false,
        ]));
        $published = $service->publish($store, $second, $actor, [
            'idempotency_key' => (string) Str::uuid(), 'lock_version' => $saved['draft']['lock_version'], 'confirm_replacements' => true,
        ]);
        $this->assertSame('disabled', $first->components()->sole()->fresh()->status->value);
        $this->assertSame('active', $second->components()->sole()->status->value);

        $versionOne = PersonalizationStrategyVersion::query()->where('strategy_id', $second->id)->where('version_number', 1)->sole();
        $restored = $service->restoreVersion($store, $second->fresh(), $versionOne, $actor, (string) Str::uuid(), true);
        $this->assertGreaterThan(1, $restored['published_version']['version_number']);
        $this->assertSame(1, PersonalizationStrategyVersion::query()->where('strategy_id', $second->id)->where('status', 'published')->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'personalization_strategy_version_restored', 'store_id' => $store->id]);
        $this->assertNotSame($published['published_version']['uuid'], $restored['published_version']['uuid']);
    }

    public function test_permanent_delete_detaches_live_components_is_audited_and_idempotent(): void
    {
        [$actor, $organization, $store] = $this->context();
        $this->product($organization, $store, 301, 'Basket');
        $service = app(PersonalizationStrategyWorkflowService::class);
        $live = $this->publishedStrategy($service, $store, $actor, 'Live', '301', 'cart_page');
        $componentId = $live->components()->sole()->id;
        $key = (string) Str::uuid();

        $deleted = $service->deleteStrategy($store, $live->uuid, $actor, $key);
        $this->assertTrue($deleted['deleted']);
        $this->assertFalse($deleted['already_deleted']);
        $this->assertSame(1, $deleted['detached_component_count']);
        $this->assertDatabaseMissing('personalization_recommendation_strategies', ['id' => $live->id]);
        $this->assertDatabaseMissing('personalization_recommendation_components', ['id' => $componentId]);
        $this->assertDatabaseHas('personalization_strategy_deletions', [
            'store_id' => $store->id,
            'strategy_uuid' => $live->uuid,
            'strategy_name' => 'Live',
            'idempotency_key' => $key,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'store_id' => $store->id,
            'action' => 'personalization_strategy_deleted',
        ]);

        $again = $service->deleteStrategy($store, $live->uuid, $actor, $key);
        $this->assertTrue($again['already_deleted']);
        $this->assertSame(1, $again['detached_component_count']);
    }

    public function test_preview_reports_cart_purchased_and_explicit_exclusion_reasons(): void
    {
        [$actor, $organization, $store] = $this->context();
        foreach ([401 => 'One', 402 => 'Two', 403 => 'Three', 404 => 'Four'] as $id => $title) {
            $this->product($organization, $store, $id, $title);
        }
        $service = app(PersonalizationStrategyWorkflowService::class);
        $created = $service->createDraft($store, $actor, (string) Str::uuid());
        $strategy = PersonalizationRecommendationStrategy::query()->sole();
        $draft = $created['draft'];
        $draft['name'] = 'Preview';
        $draft['configuration']['products'] = [
            'manual' => [
                ['shopify_product_id' => '401', 'minimum_quantity' => 2],
                ['shopify_product_id' => '402', 'minimum_quantity' => 1],
                ['shopify_product_id' => '403', 'minimum_quantity' => 3],
            ],
            'pinned' => [['shopify_product_id' => '403', 'minimum_quantity' => 3]],
            'excluded' => ['404'],
        ];
        $draft['configuration']['rules']['exclude_purchased_products'] = true;
        $service->autosave($store, $strategy, $actor, [
            'idempotency_key' => (string) Str::uuid(), 'lock_version' => 1, 'draft' => $draft,
        ]);

        $preview = $service->preview($store, $strategy, $actor, [
            'cart_product_ids' => ['401'],
            'purchased_product_ids' => ['402'],
        ]);
        $this->assertSame('403', $preview['items'][0]['shopify_product_id']);
        $this->assertSame(3, $preview['items'][0]['minimum_purchase_quantity']);
        $this->assertSame('pinned', $preview['items'][0]['reason_code']);
        $this->assertSame([
            '401' => 'already_in_cart',
            '402' => 'already_purchased',
            '404' => 'excluded_by_strategy',
        ], collect($preview['skipped'])->pluck('reason', 'shopify_product_id')->all());
    }

    public function test_delete_endpoint_requires_store_scope_and_accepts_an_idempotency_key(): void
    {
        [$actor, $organization, $store] = $this->context();
        $created = app(PersonalizationStrategyWorkflowService::class)
            ->createDraft($store, $actor, (string) Str::uuid());

        $this->actingAs($actor)->deleteJson(route('personalization.strategy-workflow.destroy', [
            'organization' => $organization,
            'store' => $store,
            'strategyUuid' => $created['strategy']['uuid'],
        ]), ['idempotency_key' => (string) Str::uuid()])
            ->assertOk()
            ->assertJsonPath('data.deleted', true)
            ->assertJsonPath('data.already_deleted', false);
    }

    public function test_analytics_keeps_strategy_version_component_and_placement_dimensions(): void
    {
        [$actor, $organization, $store] = $this->context();
        $this->product($organization, $store, 501, 'Analytics Product');
        $strategy = $this->publishedStrategy(
            app(PersonalizationStrategyWorkflowService::class),
            $store,
            $actor,
            'Analytics strategy',
            '501',
            'product_page',
        );
        $component = PersonalizationRecommendationComponent::query()->where('strategy_id', $strategy->id)->sole();
        $source = PersonalizationEventSource::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'ingest_key' => (string) Str::uuid(),
            'status' => 'active',
            'activated_at' => now(),
            'last_event_at' => now(),
        ]);
        PersonalizationEvent::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'event_source_id' => $source->id,
            'event_id' => 'versioned-impression',
            'event_name' => PersonalizationEventIngestionService::IMPRESSION,
            'client_id_hash' => hash('sha256', 'client'),
            'session_id_hash' => hash('sha256', 'session'),
            'payload_hash' => hash('sha256', 'payload'),
            'component_id' => $component->id,
            'strategy_id' => $strategy->id,
            'strategy_version_id' => $strategy->published_version_id,
            'placement' => 'product_page',
            'occurred_at' => now(),
            'received_at' => now(),
        ]);

        $dashboard = app(PersonalizationAnalyticsService::class)->dashboard($store, $actor);
        $this->assertSame($strategy->uuid, $dashboard['dimensions'][0]['strategy_uuid']);
        $this->assertSame(1, $dashboard['dimensions'][0]['strategy_version']);
        $this->assertSame($component->uuid, $dashboard['dimensions'][0]['component_uuid']);
        $this->assertSame('product_page', $dashboard['dimensions'][0]['placement']);
        $this->assertSame(1, $dashboard['dimensions'][0]['impressions']);
    }

    public function test_manual_strategy_can_use_a_store_scoped_collection_source(): void
    {
        [$actor, $organization, $store] = $this->context();
        $one = $this->product($organization, $store, 601, 'Collection One');
        $two = $this->product($organization, $store, 602, 'Collection Two');
        $collection = ProductCollection::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'shopify_collection_id' => 901,
            'title' => 'Manual Collection',
            'handle' => 'manual-collection',
            'synced_at' => now(),
        ]);
        foreach ([$one, $two] as $product) {
            $collection->products()->attach($product->id, [
                'organization_id' => $organization->id,
                'store_id' => $store->id,
                'shopify_product_id' => $product->shopify_product_id,
                'sync_batch' => (string) Str::uuid(),
            ]);
        }
        $service = app(PersonalizationStrategyWorkflowService::class);
        $created = $service->createDraft($store, $actor, (string) Str::uuid());
        $strategy = PersonalizationRecommendationStrategy::query()->sole();
        $draft = $created['draft'];
        $draft['name'] = 'Collection strategy';
        $draft['configuration']['rules']['include_collection_ids'] = ['901'];
        $saved = $service->autosave($store, $strategy, $actor, [
            'idempotency_key' => (string) Str::uuid(),
            'lock_version' => 1,
            'draft' => $draft,
        ]);

        $preview = $service->preview($store, $strategy, $actor, []);
        $this->assertSame(['601', '602'], collect($preview['items'])->pluck('shopify_product_id')->all());
        $published = $service->publish($store, $strategy, $actor, [
            'idempotency_key' => (string) Str::uuid(),
            'lock_version' => $saved['draft']['lock_version'],
            'confirm_replacements' => false,
        ]);
        $this->assertSame('enabled', $published['strategy']['status']);
    }

    public function test_custom_rule_set_is_normalized_store_scoped_and_executed_in_priority_order(): void
    {
        [$actor, $organization, $store] = $this->context();
        $cart = $this->product($organization, $store, 701, 'Cart Bike');
        $first = $this->product($organization, $store, 702, 'First Accessory');
        $second = $this->product($organization, $store, 703, 'Second Accessory');
        $fallback = $this->product($organization, $store, 704, 'Fallback Accessory');
        $first->forceFill(['tags' => ['Upsell'], 'vendor' => 'Parts'])->save();
        $collection = ProductCollection::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'shopify_collection_id' => 9701,
            'title' => 'Bike accessories',
            'handle' => 'bike-accessories',
            'synced_at' => now(),
        ]);
        $collection->products()->attach($first->id, [
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'shopify_product_id' => $first->shopify_product_id,
            'sync_batch' => (string) Str::uuid(),
        ]);

        $service = app(PersonalizationStrategyWorkflowService::class);
        $created = $service->createDraft($store, $actor, (string) Str::uuid());
        $strategy = PersonalizationRecommendationStrategy::query()->sole();
        $draft = $created['draft'];
        $draft['configuration']['recommendation_rule'] = [
            'mode' => 'custom',
            'preset' => 'manual',
            'custom' => [
                'rules' => [
                    [
                        'id' => (string) Str::uuid(),
                        'name' => 'Cart product rule',
                        'priority' => 99,
                        'match' => 'all',
                        'conditions' => [[
                            'id' => (string) Str::uuid(),
                            'field' => 'cart_product_ids',
                            'operator' => 'contains_any',
                            'values' => ['701'],
                        ]],
                        'exit_on_match' => true,
                        'action' => [
                            'type' => 'manual',
                            'products' => [['shopify_product_id' => '702', 'minimum_quantity' => 2]],
                            'filters' => [[
                                'id' => (string) Str::uuid(),
                                'field' => 'product_collections',
                                'operator' => 'contains_any',
                                'values' => ['9701'],
                            ]],
                        ],
                    ],
                    [
                        'id' => (string) Str::uuid(),
                        'name' => 'Ignored lower priority rule',
                        'priority' => 1,
                        'match' => 'any',
                        'conditions' => [[
                            'id' => (string) Str::uuid(),
                            'field' => 'cart_vendors',
                            'operator' => 'contains_any',
                            'values' => ['Deco'],
                        ]],
                        'exit_on_match' => false,
                        'action' => [
                            'type' => 'manual',
                            'products' => [['shopify_product_id' => '703', 'minimum_quantity' => 1]],
                            'filters' => [],
                        ],
                    ],
                ],
                'fallback' => [
                    'enabled' => true,
                    'action' => [
                        'type' => 'manual',
                        'products' => [['shopify_product_id' => '704', 'minimum_quantity' => 3]],
                        'filters' => [],
                    ],
                ],
            ],
        ];
        $saved = $service->autosave($store, $strategy, $actor, [
            'idempotency_key' => (string) Str::uuid(),
            'lock_version' => $draft['lock_version'],
            'draft' => $draft,
        ]);

        $this->assertSame(1, $saved['draft']['configuration']['recommendation_rule']['custom']['rules'][0]['priority']);
        $this->assertSame('custom', $strategy->fresh()->settings['recommendation_rule']['mode']);
        $recommendations = app(PersonalizationRecommendationService::class)
            ->recommend($store, $strategy->fresh(), ['cart_product_ids' => [(string) $cart->shopify_product_id]]);
        $this->assertSame(['702', '704'], collect($recommendations['items'])->pluck('shopify_product_id')->all());
        $this->assertSame([2, 3], collect($recommendations['items'])->pluck('minimum_purchase_quantity')->all());
        $this->assertSame(['custom_rule', 'custom_fallback'], collect($recommendations['items'])->pluck('reason_code')->all());
        $this->assertNotContains((string) $second->shopify_product_id, collect($recommendations['items'])->pluck('shopify_product_id')->all());

        $mergedDraft = $saved['draft'];
        $firstRuleId = $mergedDraft['configuration']['recommendation_rule']['custom']['rules'][0]['id'];
        $secondRuleId = $mergedDraft['configuration']['recommendation_rule']['custom']['rules'][1]['id'];
        $mergedDraft['configuration']['recommendation_rule']['custom']['rules'][0]['match'] = 'all';
        $mergedDraft['configuration']['recommendation_rule']['custom']['rules'][0]['conditions'][] = [
            'id' => (string) Str::uuid(),
            'field' => 'cart_tags',
            'operator' => 'contains_any',
            'values' => ['Test'],
        ];
        $mergedDraft['configuration']['recommendation_rule']['custom']['rules'][0]['exit_on_match'] = false;
        $mergedDraft['configuration']['recommendation_rule']['custom']['rules'][0]['action']['products'][] = [
            'shopify_product_id' => '703',
            'minimum_quantity' => 1,
        ];
        $mergedDraft['configuration']['recommendation_rule']['custom']['rules'][0]['action']['filters'] = [];
        $mergedDraft['configuration']['recommendation_rule']['custom']['rules'][1]['conditions'] = [
            [
                'id' => (string) Str::uuid(),
                'field' => 'cart_vendors',
                'operator' => 'contains_any',
                'values' => ['Missing vendor'],
            ],
            [
                'id' => (string) Str::uuid(),
                'field' => 'cart_product_ids',
                'operator' => 'contains_any',
                'values' => ['701'],
            ],
        ];
        $mergedDraft['configuration']['recommendation_rule']['custom']['rules'][1]['match'] = 'any';
        $mergedDraft['configuration']['recommendation_rule']['custom']['rules'][1]['action']['products'] = [
            ['shopify_product_id' => '703', 'minimum_quantity' => 9],
            ['shopify_product_id' => '704', 'minimum_quantity' => 4],
        ];
        $mergedDraft['configuration']['recommendation_rule']['custom']['fallback']['enabled'] = false;
        $mergedSaved = $service->autosave($store, $strategy->fresh(), $actor, [
            'idempotency_key' => (string) Str::uuid(),
            'lock_version' => $mergedDraft['lock_version'],
            'draft' => $mergedDraft,
        ]);
        $mergedRecommendations = app(PersonalizationRecommendationService::class)
            ->recommend($store, $strategy->fresh(), ['cart_product_ids' => ['701']]);
        $this->assertSame(['702', '703', '704'], collect($mergedRecommendations['items'])->pluck('shopify_product_id')->all());
        $this->assertSame([2, 1, 4], collect($mergedRecommendations['items'])->pluck('minimum_purchase_quantity')->all());
        $this->assertSame([$firstRuleId, $firstRuleId, $secondRuleId], collect($mergedRecommendations['items'])->pluck('rule_id')->all());
        $this->assertSame(1, collect($mergedRecommendations['items'])->where('shopify_product_id', '703')->count());

        $missingContext = app(PersonalizationRecommendationService::class)
            ->recommend($store, $strategy->fresh(), ['surface' => 'email']);
        $this->assertSame([], $missingContext['items']);
        $this->assertSame(['missing_context', 'missing_context'], collect(data_get($missingContext, 'debug.diagnostics'))->pluck('code')->all());

        $otherStore = $organization->stores()->create([
            'name' => 'Other store',
            'shopify_domain' => Str::lower(Str::random(12)).'.myshopify.com',
            'status' => 'active',
            'currency' => 'USD',
        ]);
        $foreign = $this->product($organization, $otherStore, 799, 'Foreign product');
        $invalid = $mergedSaved['draft'];
        $invalid['configuration']['recommendation_rule']['custom']['rules'][0]['conditions'][0]['values'] = [(string) $foreign->shopify_product_id];
        $this->assertExceptionCode('CUSTOM_PRODUCT_NOT_FOUND', fn () => $service->autosave($store, $strategy->fresh(), $actor, [
            'idempotency_key' => (string) Str::uuid(),
            'lock_version' => $invalid['lock_version'],
            'draft' => $invalid,
        ]));

        $first->collections()->detach();
        $first->variants()->delete();
        $first->delete();
        $safeAfterStaleReference = app(PersonalizationRecommendationService::class)
            ->recommend($store, $strategy->fresh(), ['cart_product_ids' => ['701']]);
        $this->assertSame(['703', '704'], collect($safeAfterStaleReference['items'])->pluck('shopify_product_id')->all());
        $this->assertSame('custom_rule_configuration_error', data_get($safeAfterStaleReference, 'debug.diagnostics.0.code'));
    }

    public function test_legacy_live_strategy_status_is_backfilled_without_fabricating_a_version(): void
    {
        [$actor, $organization, $store] = $this->context();
        $strategy = PersonalizationRecommendationStrategy::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'name' => 'Legacy live strategy',
            'algorithm' => 'manual',
            'enabled' => true,
            'status' => 'draft',
            'item_limit' => 4,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        $migration = require database_path('migrations/2026_08_29_000210_backfill_personalization_strategy_status.php');
        $migration->up();

        $this->assertSame('enabled', $strategy->fresh()->status->value);
        $this->assertNull($strategy->fresh()->published_version_id);
        $this->assertDatabaseCount('personalization_strategy_versions', 0);

        $migration->down();
        $this->assertSame('draft', $strategy->fresh()->status->value);
    }

    private function publishedStrategy(PersonalizationStrategyWorkflowService $service, Store $store, User $actor, string $name, string $productId, string $placement): PersonalizationRecommendationStrategy
    {
        $created = $service->createDraft($store, $actor, (string) Str::uuid());
        $strategy = PersonalizationRecommendationStrategy::query()->where('uuid', $created['strategy']['uuid'])->firstOrFail();
        $draft = $created['draft'];
        $draft['name'] = $name;
        $draft['configuration']['products']['manual'] = [$productId];
        $draft['configuration']['placements'] = [$this->placement($placement)];
        $saved = $service->autosave($store, $strategy, $actor, [
            'idempotency_key' => (string) Str::uuid(), 'lock_version' => 1, 'draft' => $draft,
        ]);
        $service->publish($store, $strategy, $actor, [
            'idempotency_key' => (string) Str::uuid(), 'lock_version' => $saved['draft']['lock_version'], 'confirm_replacements' => true,
        ]);

        return $strategy->fresh();
    }

    /** @return array<string, mixed> */
    private function placement(string $placement): array
    {
        return [
            'placement' => $placement, 'enabled' => true, 'component_uuid' => null,
            'name' => ucfirst(str_replace('_', ' ', $placement)), 'heading' => 'Recommended', 'button_label' => 'Add',
            'style' => ['layout' => 'carousel', 'desktop_columns' => 4, 'mobile_columns' => 2, 'show_image' => true, 'show_vendor' => false, 'show_price' => true, 'show_compare_at_price' => true, 'show_add_to_cart' => true, 'tokens' => []],
        ];
    }

    /** @return array{User, Organization, Store} */
    private function context(): array
    {
        $this->seed(PermissionSeeder::class);
        $actor = User::factory()->create(['email_verified_at' => now()]);
        $organization = Organization::query()->create(['name' => 'Workflow', 'code' => 'workflow-'.Str::lower(Str::random(8)), 'status' => 'active']);
        $organization->users()->attach($actor, ['status' => 'active', 'joined_at' => now()]);
        $store = $organization->stores()->create(['name' => 'Workflow Store', 'shopify_domain' => Str::lower(Str::random(12)).'.myshopify.com', 'status' => 'active', 'currency' => 'USD']);
        $store->members()->attach($actor, ['status' => 'active', 'joined_at' => now()]);
        $this->seed(RoleSeeder::class);
        $role = Role::query()->whereBelongsTo($organization)->where('slug', 'store-admin')->sole();
        $actor->roles()->attach($role, ['organization_id' => $organization->id, 'store_id' => $store->id]);

        return [$actor, $organization, $store];
    }

    private function product(Organization $organization, Store $store, int $shopifyId, string $title): Product
    {
        $product = Product::query()->create([
            'organization_id' => $organization->id, 'store_id' => $store->id, 'shopify_product_id' => $shopifyId,
            'title' => $title, 'handle' => Str::slug($title), 'status' => 'active', 'vendor' => 'Deco', 'tags' => ['Test'], 'published_at_shopify' => now(), 'synced_at' => now(),
        ]);
        ProductVariant::query()->create(['product_id' => $product->id, 'shopify_variant_id' => $shopifyId * 10, 'title' => 'Default', 'price' => 10, 'available_for_sale' => true, 'selected_options' => []]);

        return $product;
    }

    private function assertExceptionCode(string $code, callable $action): void
    {
        try {
            $action();
            $this->fail("Expected {$code}.");
        } catch (PersonalizationException $exception) {
            $this->assertSame($code, $exception->errorCode);
        }
    }
}
