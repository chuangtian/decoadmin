<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Organization;
use App\Models\PersonalizationAttribution;
use App\Models\PersonalizationEvent;
use App\Models\PersonalizationEventProduct;
use App\Models\PersonalizationEventSource;
use App\Models\PersonalizationRecommendationComponent;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\Personalization\PersonalizationAnalyticsService;
use App\Services\Personalization\PersonalizationAttributionService;
use App\Services\Personalization\PersonalizationConfigurationService;
use App\Services\Personalization\PersonalizationEventIngestionService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PersonalizationAttributionTest extends TestCase
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

    public function test_last_recommendation_click_wins_and_refunds_and_cancellations_reverse_revenue(): void
    {
        [$admin, $organization, $store] = $this->context('Attribution');
        $product = $this->product($organization, $store, 1101, 'Attributed Bike');
        [$firstComponent, $lastComponent] = $this->components($store, $admin);
        $source = $this->source($organization, $store);
        $clientHash = hash('sha256', 'anonymous-client-a');
        $this->event($source, PersonalizationEventIngestionService::IMPRESSION, 'impression-a', $clientHash, now()->subDays(2), $firstComponent, $product);
        $this->event($source, PersonalizationEventIngestionService::CLICK, 'click-old', $clientHash, now()->subDay(), $firstComponent, $product);
        $this->event($source, PersonalizationEventIngestionService::CLICK, 'click-last', $clientHash, now()->subHour(), $lastComponent, $product);
        $this->event($source, PersonalizationEventIngestionService::ADD_TO_CART, 'add-a', $clientHash, now()->subMinutes(30), $lastComponent, $product, 11010);
        $order = $this->order($organization, $store, 7001, 200, 25);
        $checkout = $this->checkout($source, 'checkout-a', $clientHash, now(), $order->shopify_order_id);

        $result = app(PersonalizationAttributionService::class)->reconcileStore($store);
        $this->assertSame(['examined' => 1, 'attributed' => 1, 'updated' => 0, 'skipped' => 0], $result);
        $attribution = PersonalizationAttribution::query()->sole();
        $this->assertSame($order->id, $attribution->order_id);
        $this->assertSame($checkout->id, $attribution->checkout_event_id);
        $this->assertSame('click-last', $attribution->clickEvent->event_id);
        $this->assertSame($lastComponent->id, $attribution->component_id);
        $this->assertSame($lastComponent->strategy_id, $attribution->strategy_id);
        $this->assertSame('cart_page', $attribution->placement);
        $this->assertSame('last_recommendation_click', $attribution->model);
        $this->assertSame(7, $attribution->window_days);
        $this->assertSame('partially_refunded', $attribution->status);
        $this->assertSame('200.0000', $attribution->gross_revenue);
        $this->assertSame('25.0000', $attribution->refund_amount);
        $this->assertSame('175.0000', $attribution->attributed_revenue);

        $analytics = app(PersonalizationAnalyticsService::class)->dashboard($store, $admin);
        $this->assertSame(1, $analytics['impressions']);
        $this->assertSame(2, $analytics['clicks']);
        $this->assertSame(1, $analytics['add_to_carts']);
        $this->assertSame(1, $analytics['orders']);
        $this->assertSame('175.00', $analytics['attributed_revenue']);
        $this->assertSame('175.00', $analytics['aov']);
        $this->assertSame(7, $analytics['attribution']['window_days']);
        $this->assertTrue($analytics['attribution']['click_only']);
        $this->assertSame('175.00', collect($analytics['placements'])->firstWhere('placement', 'cart_page')['attributed_revenue']);

        $order->forceFill(['refund_total' => 200, 'financial_status' => 'refunded'])->save();
        $updated = app(PersonalizationAttributionService::class)->reconcileStore($store);
        $this->assertSame(1, $updated['updated']);
        $this->assertSame('refunded', $attribution->fresh()->status);
        $this->assertSame('0.0000', $attribution->fresh()->attributed_revenue);

        $cancelledOrder = $this->order($organization, $store, 7002, 300, 0, now());
        $cancelledClient = hash('sha256', 'anonymous-client-b');
        $this->event($source, PersonalizationEventIngestionService::CLICK, 'click-cancelled', $cancelledClient, now()->subMinutes(10), $firstComponent, $product);
        $this->checkout($source, 'checkout-cancelled', $cancelledClient, now(), $cancelledOrder->shopify_order_id);
        app(PersonalizationAttributionService::class)->reconcileStore($store);
        $cancelled = PersonalizationAttribution::query()->where('order_id', $cancelledOrder->id)->sole();
        $this->assertSame('cancelled', $cancelled->status);
        $this->assertSame('0.0000', $cancelled->attributed_revenue);

        $analytics = app(PersonalizationAnalyticsService::class)->dashboard($store, $admin);
        $this->assertSame(0, $analytics['orders']);
        $this->assertSame('0.00', $analytics['attributed_revenue']);
        $this->assertSame(2, $analytics['reversed_orders']);
    }

    public function test_impressions_and_clicks_outside_seven_days_never_attribute_orders(): void
    {
        [$admin, $organization, $store] = $this->context('Click Only');
        $product = $this->product($organization, $store, 1201, 'Window Bike');
        [$component] = $this->components($store, $admin);
        $source = $this->source($organization, $store);
        $clientHash = hash('sha256', 'anonymous-window-client');
        $this->event($source, PersonalizationEventIngestionService::IMPRESSION, 'impression-window', $clientHash, now()->subDay(), $component, $product);
        $this->event($source, PersonalizationEventIngestionService::CLICK, 'click-too-old', $clientHash, now()->subDays(8), $component, $product);
        $order = $this->order($organization, $store, 8001, 100, 0);
        $this->checkout($source, 'checkout-window', $clientHash, now(), $order->shopify_order_id);

        $result = app(PersonalizationAttributionService::class)->reconcileStore($store);
        $this->assertSame(1, $result['skipped']);
        $this->assertDatabaseCount('personalization_attributions', 0);

        $order->forceFill(['is_test' => true])->save();
        $this->event($source, PersonalizationEventIngestionService::CLICK, 'click-test-order', $clientHash, now()->subMinute(), $component, $product);
        app(PersonalizationAttributionService::class)->reconcileStore($store);
        $this->assertDatabaseCount('personalization_attributions', 0);
    }

    public function test_command_is_store_scoped_and_reconciliation_is_scheduled(): void
    {
        [$admin, $organization, $store] = $this->context('Command');
        $product = $this->product($organization, $store, 1301, 'Command Bike');
        [$component] = $this->components($store, $admin);
        $source = $this->source($organization, $store);
        $clientHash = hash('sha256', 'anonymous-command-client');
        $this->event($source, PersonalizationEventIngestionService::CLICK, 'click-command', $clientHash, now()->subMinute(), $component, $product);
        $order = $this->order($organization, $store, 9001, 99, 0);
        $this->checkout($source, 'checkout-command', $clientHash, now(), $order->shopify_order_id);

        $this->artisan("personalization:reconcile-attribution --store={$store->id}")
            ->expectsOutputToContain('Stores: 1; examined: 1; attributed: 1;')
            ->assertSuccessful();
        $this->assertDatabaseHas('personalization_attributions', [
            'store_id' => $store->id,
            'order_id' => $order->id,
            'status' => 'attributed',
        ]);
        $this->artisan('schedule:list')
            ->expectsOutputToContain('personalization:reconcile-attribution')
            ->assertSuccessful();
    }

    /** @return array{PersonalizationRecommendationComponent, PersonalizationRecommendationComponent} */
    private function components(Store $store, User $admin): array
    {
        $service = app(PersonalizationConfigurationService::class);
        $firstStrategy = $service->createStrategy($store, $admin, ['name' => 'First', 'algorithm' => 'new_arrivals']);
        $first = $service->createComponent($store, $firstStrategy, $admin, [
            'name' => 'Homepage',
            'placement' => 'homepage',
        ]);
        $lastStrategy = $service->createStrategy($store, $admin, ['name' => 'Last', 'algorithm' => 'best_seller']);
        $last = $service->createComponent($store, $lastStrategy, $admin, [
            'name' => 'Cart page',
            'placement' => 'cart_page',
        ]);

        return [
            $service->activateComponent($store, $first, $admin),
            $service->activateComponent($store, $last, $admin),
        ];
    }

    private function source(Organization $organization, Store $store): PersonalizationEventSource
    {
        return PersonalizationEventSource::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'status' => 'active',
            'activated_at' => now(),
            'last_event_at' => now(),
        ]);
    }

    private function event(
        PersonalizationEventSource $source,
        string $name,
        string $id,
        string $clientHash,
        mixed $occurredAt,
        PersonalizationRecommendationComponent $component,
        Product $product,
        ?int $variantId = null,
    ): PersonalizationEvent {
        $event = PersonalizationEvent::query()->create([
            'organization_id' => $source->organization_id,
            'store_id' => $source->store_id,
            'event_source_id' => $source->id,
            'event_id' => $id,
            'event_name' => $name,
            'client_id_hash' => $clientHash,
            'session_id_hash' => hash('sha256', $id.'-session'),
            'payload_hash' => hash('sha256', $id.'-payload'),
            'component_id' => $component->id,
            'strategy_id' => $component->strategy_id,
            'placement' => $component->placement->value,
            'occurred_at' => $occurredAt,
            'received_at' => now(),
        ]);
        PersonalizationEventProduct::query()->create([
            'event_id' => $event->id,
            'product_id' => $product->id,
            'shopify_product_id' => (string) $product->shopify_product_id,
            'shopify_variant_id' => $variantId === null ? null : (string) $variantId,
            'rank' => 1,
        ]);

        return $event;
    }

    private function checkout(
        PersonalizationEventSource $source,
        string $id,
        string $clientHash,
        mixed $occurredAt,
        string $shopifyOrderId,
    ): PersonalizationEvent {
        return PersonalizationEvent::query()->create([
            'organization_id' => $source->organization_id,
            'store_id' => $source->store_id,
            'event_source_id' => $source->id,
            'event_id' => $id,
            'event_name' => PersonalizationEventIngestionService::CHECKOUT_COMPLETED,
            'client_id_hash' => $clientHash,
            'session_id_hash' => hash('sha256', $id.'-session'),
            'payload_hash' => hash('sha256', $id.'-payload'),
            'shopify_order_id' => $shopifyOrderId,
            'occurred_at' => $occurredAt,
            'received_at' => now(),
        ]);
    }

    private function order(
        Organization $organization,
        Store $store,
        int $shopifyId,
        float $total,
        float $refund,
        mixed $cancelledAt = null,
    ): Order {
        return Order::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'shopify_order_id' => $shopifyId,
            'order_number' => '#'.$shopifyId,
            'financial_status' => $refund > 0 ? 'partially_refunded' : 'paid',
            'fulfillment_status' => 'unfulfilled',
            'currency' => $store->currency,
            'total_price' => $total,
            'subtotal_price' => $total,
            'net_sales' => max(0, $total - $refund),
            'discount_total' => 0,
            'refund_total' => $refund,
            'shipping_total' => 0,
            'total_tax' => 0,
            'is_test' => false,
            'processed_at' => now(),
            'cancelled_at' => $cancelledAt,
            'created_at_shopify' => now(),
            'synced_at' => now(),
        ]);
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
            'timezone' => 'Asia/Shanghai',
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
        return Product::query()->create([
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
    }
}
