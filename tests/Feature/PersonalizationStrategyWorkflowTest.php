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

    public function test_draft_autosave_is_idempotent_and_never_changes_published_configuration(): void
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
        $this->assertSame('Published name', $strategy->fresh()->name);
        $this->assertSame('Unpublished edit', PersonalizationStrategyVersion::query()->where('status', 'draft')->sole()->name);
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

    public function test_recycle_bin_blocks_live_strategy_and_restores_unused_draft(): void
    {
        [$actor, $organization, $store] = $this->context();
        $this->product($organization, $store, 301, 'Basket');
        $service = app(PersonalizationStrategyWorkflowService::class);
        $live = $this->publishedStrategy($service, $store, $actor, 'Live', '301', 'cart_page');
        $this->assertExceptionCode('STRATEGY_IN_USE', fn () => $service->recycle($store, $live, $actor));

        $draft = $service->createDraft($store, $actor, (string) Str::uuid());
        $strategy = PersonalizationRecommendationStrategy::query()->where('uuid', $draft['strategy']['uuid'])->sole();
        $service->recycle($store, $strategy, $actor);
        $this->assertSoftDeleted('personalization_recommendation_strategies', ['id' => $strategy->id]);
        $this->assertNotNull($strategy->fresh()->purge_after);
        $restored = $service->restore($store, $strategy->uuid, $actor);
        $this->assertSame('draft', $restored['status']);
        $this->assertNull($strategy->fresh()->deleted_at);
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
        $draft['configuration']['products'] = ['manual' => ['401', '402', '403'], 'pinned' => [], 'excluded' => ['404']];
        $draft['configuration']['rules']['exclude_purchased_products'] = true;
        $service->autosave($store, $strategy, $actor, [
            'idempotency_key' => (string) Str::uuid(), 'lock_version' => 1, 'draft' => $draft,
        ]);

        $preview = $service->preview($store, $strategy, $actor, [
            'cart_product_ids' => ['401'],
            'purchased_product_ids' => ['402'],
        ]);
        $this->assertSame('403', $preview['items'][0]['shopify_product_id']);
        $this->assertSame([
            '401' => 'already_in_cart',
            '402' => 'already_purchased',
            '404' => 'excluded_by_strategy',
        ], collect($preview['skipped'])->pluck('reason', 'shopify_product_id')->all());
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
