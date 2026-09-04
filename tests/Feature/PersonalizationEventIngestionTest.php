<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\PersonalizationEvent;
use App\Models\PersonalizationEventSource;
use App\Models\PersonalizationRecommendationComponent;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\Personalization\PersonalizationConfigurationService;
use App\Services\Personalization\PersonalizationEventIngestionService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PersonalizationEventIngestionTest extends TestCase
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

    public function test_active_source_accepts_idempotent_store_scoped_anonymous_recommendation_events(): void
    {
        [$admin, $organization, $store] = $this->context('Events A');
        $product = $this->product($organization, $store, 801, 'Tracked Bike');
        $component = $this->activeComponent($store, $admin, $product);
        $source = $this->source($organization, $store);
        $ruleId = (string) Str::uuid();
        $payload = [
            'event_id' => 'pixel-event-801',
            'event_name' => PersonalizationEventIngestionService::IMPRESSION,
            'client_id' => 'shopify-client-raw',
            'session_id' => 'browser-session-raw',
            'occurred_at' => now()->toIso8601String(),
            'component_uuid' => $component->uuid,
            'strategy_uuid' => $component->strategy->uuid,
            'placement' => 'homepage',
            'products' => [[
                'product_id' => 'gid://shopify/Product/801',
                'variant_id' => null,
                'rank' => 1,
                'rule_id' => $ruleId,
            ]],
            'email' => 'must-not-be-stored@example.com',
            'raw_context' => ['customer' => ['phone' => '555-0100']],
        ];

        $this->event($source, $payload)
            ->assertAccepted()
            ->assertJsonPath('data.accepted', true)
            ->assertJsonPath('data.duplicate', false)
            ->assertHeader('Cache-Control', 'no-store, private');

        $event = PersonalizationEvent::query()->with('products')->sole();
        $this->assertSame($organization->id, $event->organization_id);
        $this->assertSame($store->id, $event->store_id);
        $this->assertSame($component->id, $event->component_id);
        $this->assertSame($component->strategy_id, $event->strategy_id);
        $this->assertSame('homepage', $event->placement);
        $this->assertSame(hash_hmac('sha256', 'shopify-client-raw', (string) config('app.key')), $event->client_id_hash);
        $this->assertSame(hash_hmac('sha256', 'browser-session-raw', (string) config('app.key')), $event->session_id_hash);
        $this->assertSame($product->id, $event->products->sole()->product_id);
        $this->assertSame('801', $event->products->sole()->shopify_product_id);
        $this->assertSame($ruleId, $event->products->sole()->rule_id);
        $this->assertStringNotContainsString('shopify-client-raw', json_encode($event->toArray(), JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('must-not-be-stored', json_encode($event->toArray(), JSON_THROW_ON_ERROR));
        foreach (['ip', 'email', 'phone', 'customer_id', 'payload', 'raw_context', 'url', 'user_agent'] as $column) {
            $this->assertFalse(Schema::hasColumn('personalization_events', $column));
        }

        $this->event($source, $payload)
            ->assertOk()
            ->assertJsonPath('data.duplicate', true);
        $this->assertDatabaseCount('personalization_events', 1);
        $this->assertDatabaseCount('personalization_event_products', 1);

        $this->event($source, [...$payload, 'client_id' => 'conflicting-client'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'PERSONALIZATION_EVENT_ID_CONFLICT');
    }

    public function test_ingestion_rejects_untrusted_context_and_accepts_only_reconcilable_checkout_order_ids(): void
    {
        [$admin, $organization, $store] = $this->context('Events Guard');
        $product = $this->product($organization, $store, 901, 'Scoped Bike');
        $component = $this->activeComponent($store, $admin, $product);
        $source = $this->source($organization, $store);
        [, $otherOrganization, $otherStore] = $this->context('Events Other');
        $otherProduct = $this->product($otherOrganization, $otherStore, 902, 'Other Bike');

        $base = [
            'event_id' => 'pixel-event-cross-store',
            'event_name' => PersonalizationEventIngestionService::CLICK,
            'client_id' => 'client-guard',
            'session_id' => 'session-guard',
            'occurred_at' => now()->toIso8601String(),
            'component_uuid' => $component->uuid,
            'strategy_uuid' => $component->strategy->uuid,
            'placement' => 'homepage',
            'products' => [[
                'product_id' => (string) $otherProduct->shopify_product_id,
                'variant_id' => null,
                'rank' => 1,
            ]],
        ];
        $this->event($source, $base)
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'INVALID_PERSONALIZATION_EVENT_PRODUCTS');
        $this->event($source, [
            ...$base,
            'event_id' => 'pixel-event-stale',
            'products' => [['product_id' => '901', 'variant_id' => null, 'rank' => 1]],
            'occurred_at' => now()->subDays(8)->toIso8601String(),
        ])->assertUnprocessable()->assertJsonPath('error.code', 'PERSONALIZATION_EVENT_TIME_INVALID');

        $this->event($source, [
            'event_id' => 'checkout-event-1',
            'event_name' => PersonalizationEventIngestionService::CHECKOUT_COMPLETED,
            'client_id' => 'client-guard',
            'session_id' => 'session-guard',
            'occurred_at' => now()->toIso8601String(),
            'shopify_order_id' => 'gid://shopify/Order/123456789',
        ])->assertAccepted();
        $checkout = PersonalizationEvent::query()->where('event_name', 'checkout_completed')->sole();
        $this->assertSame('123456789', $checkout->shopify_order_id);
        $this->assertNull($checkout->component_id);
        $this->assertDatabaseCount('personalization_event_products', 0);

        $source->forceFill(['status' => 'inactive'])->save();
        $this->event($source, [...$base, 'event_id' => 'inactive-event'])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'PERSONALIZATION_EVENT_SOURCE_INACTIVE');

        $source->forceFill(['status' => 'active'])->save();
        $store->forceFill(['shopify_domain' => 'macfoxebike.myshopify.com'])->save();
        $this->event($source, [...$base, 'event_id' => 'denied-event'])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'SHOP_WRITE_DENIED');
    }

    public function test_product_view_event_is_anonymous_store_scoped_and_does_not_require_a_strategy(): void
    {
        [, $organization, $store] = $this->context('Product Views');
        $product = $this->product($organization, $store, 951, 'Viewed Bike');
        $source = $this->source($organization, $store);

        $this->event($source, [
            'event_id' => 'product-view-951',
            'event_name' => PersonalizationEventIngestionService::PRODUCT_VIEWED,
            'client_id' => 'anonymous-client',
            'session_id' => 'anonymous-session',
            'occurred_at' => now()->toIso8601String(),
            'products' => [[
                'product_id' => 'gid://shopify/Product/951',
                'variant_id' => 'gid://shopify/ProductVariant/9510',
                'rank' => 1,
            ]],
        ])->assertAccepted();

        $event = PersonalizationEvent::query()->with('products')->sole();
        $this->assertSame(PersonalizationEventIngestionService::PRODUCT_VIEWED, $event->event_name);
        $this->assertNull($event->component_id);
        $this->assertNull($event->strategy_id);
        $this->assertNull($event->placement);
        $this->assertSame($product->id, $event->products->sole()->product_id);
        $this->assertStringNotContainsString('anonymous-client', json_encode($event->toArray(), JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('anonymous-session', json_encode($event->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_enabled_smart_cart_events_use_the_store_scoped_active_strategy(): void
    {
        [$admin, $organization, $store] = $this->context('Smart Cart Events');
        $product = $this->product($organization, $store, 1001, 'Smart Cart Tracked Bike');
        $service = app(PersonalizationConfigurationService::class);
        $strategy = $service->createStrategy($store, $admin, ['name' => 'Smart Cart events', 'algorithm' => 'manual']);
        $service->replaceProductOverrides($store, $strategy, $admin, [[
            'shopify_product_id' => 'gid://shopify/Product/1001',
            'type' => 'manual',
        ]]);
        $setting = $service->saveSmartCartDraft($store, $admin, $strategy, ['heading' => 'Recommended']);
        $this->assertTrue($setting->enabled);
        $this->assertSame('native_cart', $setting->fallback_mode);
        $source = $this->source($organization, $store);

        $this->event($source, [
            'event_id' => 'smart-cart-click-1',
            'event_name' => PersonalizationEventIngestionService::CLICK,
            'client_id' => 'smart-cart-client',
            'session_id' => 'smart-cart-session',
            'occurred_at' => now()->toIso8601String(),
            'component_uuid' => '',
            'strategy_uuid' => $strategy->uuid,
            'placement' => 'smart_cart',
            'products' => [[
                'product_id' => '1001',
                'variant_id' => null,
                'rank' => 1,
            ]],
        ])->assertAccepted();

        $event = PersonalizationEvent::query()->sole();
        $this->assertNull($event->component_id);
        $this->assertSame($strategy->id, $event->strategy_id);
        $this->assertSame('smart_cart', $event->placement);
        $this->assertSame($product->id, $event->products()->sole()->product_id);
    }

    public function test_checkout_sequence_events_are_distinct_bounded_and_store_scoped(): void
    {
        [$admin, $organization, $store] = $this->context('Checkout Events');
        $product = $this->product($organization, $store, 1101, 'Checkout Tracked Bike');
        $component = $this->activeComponent($store, $admin, $product, 'checkout');
        $source = $this->source($organization, $store);
        $events = [
            PersonalizationEventIngestionService::CHECKOUT_RECOMMENDATION_IMPRESSION,
            PersonalizationEventIngestionService::CHECKOUT_RECOMMENDATION_CLICK,
            PersonalizationEventIngestionService::CHECKOUT_RECOMMENDATION_ADD_SUCCESS,
            PersonalizationEventIngestionService::CHECKOUT_RECOMMENDATION_ADD_FAILED,
            PersonalizationEventIngestionService::CHECKOUT_RECOMMENDATION_SEQUENCE_COMPLETED,
        ];

        foreach ($events as $index => $eventName) {
            $this->event($source, [
                'event_id' => 'checkout-sequence-'.$index,
                'event_name' => $eventName,
                'client_id' => 'checkout-client',
                'session_id' => 'checkout-session',
                'occurred_at' => now()->addSeconds($index)->toIso8601String(),
                'component_uuid' => $component->uuid,
                'strategy_uuid' => $component->strategy->uuid,
                'placement' => 'checkout',
                'products' => [[
                    'product_id' => '1101',
                    'variant_id' => '11010',
                    'rank' => 1,
                ]],
            ])->assertAccepted();
        }

        $this->assertDatabaseCount('personalization_events', 5);
        $this->assertSame($events, PersonalizationEvent::query()->orderBy('id')->pluck('event_name')->all());
        $this->assertSame(['checkout'], PersonalizationEvent::query()->distinct()->pluck('placement')->all());
        $this->assertDatabaseCount('personalization_event_products', 5);
    }

    private function source(Organization $organization, Store $store): PersonalizationEventSource
    {
        return PersonalizationEventSource::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'status' => 'active',
            'activated_at' => now(),
        ]);
    }

    private function activeComponent(
        Store $store,
        User $admin,
        Product $product,
        string $placement = 'homepage',
    ): PersonalizationRecommendationComponent {
        $service = app(PersonalizationConfigurationService::class);
        $strategy = $service->createStrategy($store, $admin, ['name' => 'Tracked manual', 'algorithm' => 'manual']);
        $service->replaceProductOverrides($store, $strategy, $admin, [[
            'shopify_product_id' => 'gid://shopify/Product/'.$product->shopify_product_id,
            'type' => 'manual',
        ]]);
        $component = $service->createComponent($store, $strategy, $admin, [
            'name' => 'Tracked homepage',
            'placement' => $placement,
        ]);

        return $service->activateComponent($store, $component, $admin);
    }

    /** @return array{User, Organization, Store} */
    private function context(string $name): array
    {
        $this->seed(PermissionSeeder::class);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $organization = Organization::query()->create([
            'name' => $name,
            'code' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'status' => 'active',
        ]);
        $organization->users()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $store = $organization->stores()->create([
            'name' => $name,
            'shopify_domain' => Str::lower(Str::random(12)).'.myshopify.com',
            'status' => 'active',
            'currency' => 'USD',
        ]);
        $store->members()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $this->seed(RoleSeeder::class);
        $role = Role::query()->whereBelongsTo($organization)->where('slug', 'store-admin')->sole();
        $user->roles()->attach($role, ['organization_id' => $organization->id, 'store_id' => $store->id]);

        return [$user, $organization, $store];
    }

    private function product(Organization $organization, Store $store, int $shopifyId, string $title): Product
    {
        $product = Product::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'shopify_product_id' => $shopifyId,
            'title' => $title,
            'handle' => Str::slug($title),
            'status' => 'active',
            'vendor' => 'Deco',
            'product_type' => 'Bike',
            'tags' => ['Bike'],
            'published_at_shopify' => now(),
            'featured_image_url' => 'https://cdn.shopify.com/'.$shopifyId.'.jpg',
            'synced_at' => now(),
        ]);
        ProductVariant::query()->create([
            'product_id' => $product->id,
            'shopify_variant_id' => $shopifyId * 10,
            'title' => 'Default',
            'price' => 100,
            'available_for_sale' => true,
            'selected_options' => [],
        ]);

        return $product;
    }

    /** @param array<string, mixed> $payload */
    private function event(PersonalizationEventSource $source, array $payload): TestResponse
    {
        return $this->call('POST', route('personalization.events.receive', ['source' => $source->ingest_key]), [], [], [], [
            'CONTENT_TYPE' => 'text/plain;charset=UTF-8',
            'HTTP_ACCEPT' => 'application/json',
        ], json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
