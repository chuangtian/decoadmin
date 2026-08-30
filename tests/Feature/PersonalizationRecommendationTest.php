<?php

namespace Tests\Feature;

use App\Enums\PersonalizationComponentStatus;
use App\Exceptions\PersonalizationException;
use App\Models\InventoryItem;
use App\Models\InventoryLevel;
use App\Models\Location;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductCollection;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\Personalization\PersonalizationConfigurationService;
use App\Services\Personalization\PersonalizationRecommendationService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PersonalizationRecommendationTest extends TestCase
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

    public function test_six_algorithms_return_deterministic_storefront_safe_results(): void
    {
        [$actor, $organization, $store] = $this->context();
        $seed = $this->product($organization, $store, 101, 'Trail Bike', 'Bike', 'Deco', ['Bike', 'Trail'], 100, now()->subDays(20));
        $helmet = $this->product($organization, $store, 102, 'Trail Helmet', 'Accessory', 'Deco', ['Trail'], 60, now()->subDays(10));
        $similar = $this->product($organization, $store, 103, 'City Bike', 'Bike', 'Deco', ['Bike', 'Trail'], 110, now()->subDays(8));
        $newest = $this->product($organization, $store, 104, 'Newest Bike', 'Bike', 'Other', ['Bike'], 120, now());
        $bestSeller = $this->product($organization, $store, 105, 'Best Seller', 'Accessory', 'Other', ['Popular'], 40, now()->subDays(5));
        $collection = ProductCollection::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'shopify_collection_id' => 501,
            'title' => 'Bikes',
            'handle' => 'bikes',
            'sort_order' => 'manual',
            'sync_batch' => (string) Str::uuid(),
            'synced_at' => now(),
        ]);
        foreach ([$seed, $similar] as $product) {
            $product->collections()->attach($collection->id, [
                'organization_id' => $organization->id,
                'store_id' => $store->id,
                'shopify_product_id' => $product->shopify_product_id,
                'sync_batch' => (string) Str::uuid(),
            ]);
        }
        $this->order($organization, $store, 701, [[$seed, 1], [$similar, 1], [$bestSeller, 1]]);
        $this->order($organization, $store, 702, [[$seed, 1], [$similar, 1], [$bestSeller, 5]]);

        $config = app(PersonalizationConfigurationService::class);
        $engine = app(PersonalizationRecommendationService::class);
        $manual = $config->createStrategy($store, $actor, ['name' => 'Manual', 'algorithm' => 'manual']);
        $config->replaceProductOverrides($store, $manual, $actor, [
            ['shopify_product_id' => 'gid://shopify/Product/102', 'type' => 'manual'],
            ['shopify_product_id' => 'gid://shopify/Product/104', 'type' => 'manual'],
        ]);
        $best = $config->createStrategy($store, $actor, ['name' => 'Best', 'algorithm' => 'best_seller']);
        $new = $config->createStrategy($store, $actor, ['name' => 'New', 'algorithm' => 'new_arrivals']);
        $fbt = $config->createStrategy($store, $actor, ['name' => 'FBT', 'algorithm' => 'frequently_bought_together']);
        $recent = $config->createStrategy($store, $actor, ['name' => 'Recent', 'algorithm' => 'recently_viewed']);
        $similarStrategy = $config->createStrategy($store, $actor, ['name' => 'Similar', 'algorithm' => 'similar_products']);

        $this->assertSame(['102', '104'], $this->ids($engine->recommend($store, $manual)));
        $this->assertSame('105', $this->ids($engine->recommend($store, $best))[0]);
        $this->assertSame('104', $this->ids($engine->recommend($store, $new))[0]);
        $this->assertSame(['105', '103'], array_slice($this->ids($engine->recommend($store, $fbt, [
            'seed_product_id' => 'gid://shopify/Product/101',
        ])), 0, 2));
        $this->assertSame(['104', '102'], $this->ids($engine->recommend($store, $recent, [
            'recently_viewed_product_ids' => [104, 'gid://shopify/Product/102'],
        ])));
        $this->assertSame('103', $this->ids($engine->recommend($store, $similarStrategy, [
            'seed_product_id' => 101,
        ]))[0]);

        $payload = $engine->recommend($store, $fbt, ['cart_product_ids' => [101]]);
        $serialized = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('customer@example.com', $serialized);
        $this->assertStringNotContainsString('ORDER-', $serialized);
        $this->assertArrayNotHasKey('description', $payload['items'][0]);
        $this->assertSame('frequently_bought_together', $payload['items'][0]['reason_code']);
    }

    public function test_rules_pins_exclusions_and_inventory_are_applied_after_ranking(): void
    {
        [$actor, $organization, $store] = $this->context();
        $pinned = $this->product($organization, $store, 201, 'Pinned', 'Bike', 'Deco', ['Bike', 'Clearance'], 100, now()->subMonth());
        $excluded = $this->product($organization, $store, 202, 'Excluded', 'Bike', 'Deco', ['Bike'], 100, now());
        $lowInventory = $this->product($organization, $store, 203, 'Low Inventory', 'Bike', 'Deco', ['Bike'], 100, now()->subDay());
        $excludedTag = $this->product($organization, $store, 204, 'Clearance', 'Bike', 'Deco', ['Bike', 'Clearance'], 100, now()->subDays(2));
        $tooExpensive = $this->product($organization, $store, 205, 'Expensive', 'Bike', 'Deco', ['Bike'], 999, now()->subDays(3));
        $this->inventory($organization, $store, $pinned, 5);
        $this->inventory($organization, $store, $excluded, 5);
        $this->inventory($organization, $store, $lowInventory, 1);
        $this->inventory($organization, $store, $excludedTag, 5);
        $this->inventory($organization, $store, $tooExpensive, 5);

        $config = app(PersonalizationConfigurationService::class);
        $strategy = $config->createStrategy($store, $actor, [
            'name' => 'Rules',
            'algorithm' => 'new_arrivals',
            'item_limit' => 5,
        ]);
        $config->replaceRules($store, $strategy, $actor, [
            ['type' => 'include_tags', 'value' => ['Bike']],
            ['type' => 'exclude_tags', 'value' => ['Clearance']],
            ['type' => 'minimum_price', 'value' => 50],
            ['type' => 'maximum_price', 'value' => 200],
            ['type' => 'minimum_inventory', 'value' => 2],
            ['type' => 'in_stock_only', 'value' => true],
        ]);
        $config->replaceProductOverrides($store, $strategy, $actor, [
            ['shopify_product_id' => 'gid://shopify/Product/201', 'type' => 'pinned'],
            ['shopify_product_id' => 'gid://shopify/Product/202', 'type' => 'excluded'],
        ]);

        $result = app(PersonalizationRecommendationService::class)->recommend($store, $strategy);

        $this->assertSame(['201'], $this->ids($result));
        $this->assertSame('pinned', $result['items'][0]['reason_code']);
        $this->assertNull($result['items'][0]['score']);
        $this->assertStringNotContainsString('Excluded', json_encode($result, JSON_THROW_ON_ERROR));
        $this->assertNotContains('204', $this->ids($result));

        $pinned->variants()->update(['available_for_sale' => false]);
        $this->assertSame([], $this->ids(app(PersonalizationRecommendationService::class)->recommend($store, $strategy->fresh())));
    }

    public function test_active_percentage_discount_returns_original_rate_and_discounted_price(): void
    {
        [$actor, $organization, $store] = $this->context();
        $product = $this->product($organization, $store, 250, 'Discounted Mirror', 'Accessory', 'Deco', ['Mirror'], 99, now());
        $config = app(PersonalizationConfigurationService::class);
        $strategy = $config->createStrategy($store, $actor, ['name' => 'Discounted', 'algorithm' => 'manual']);
        $config->replaceProductOverrides($store, $strategy, $actor, [[
            'shopify_product_id' => 'gid://shopify/Product/'.$product->shopify_product_id,
            'type' => 'manual',
        ]]);
        $strategy->forceFill(['settings' => [
            'discount' => [
                'enabled' => true,
                'reference' => 'gid://shopify/DiscountCodeNode/123',
                'title' => 'Ten off',
                'summary' => '10% off',
                'code' => 'DECO10',
                'status' => 'active',
                'percentage' => 10,
                'validated_at' => now()->toIso8601String(),
            ],
        ]])->save();

        $result = app(PersonalizationRecommendationService::class)->recommend($store, $strategy->fresh());

        $this->assertSame(10.0, $result['discount']['percentage']);
        $this->assertSame('99.00', data_get($result, 'items.0.pricing.original_amount'));
        $this->assertSame(10.0, data_get($result, 'items.0.pricing.discount_percentage'));
        $this->assertSame('89.10', data_get($result, 'items.0.pricing.discounted_amount'));
        $this->assertSame('USD', data_get($result, 'items.0.pricing.currency'));
    }

    public function test_component_requires_activation_but_preview_uses_the_same_result_contract(): void
    {
        [$actor, $organization, $store] = $this->context();
        $this->product($organization, $store, 301, 'Preview Product', 'Bike', 'Deco', ['Bike'], 100, now());
        $config = app(PersonalizationConfigurationService::class);
        $strategy = $config->createStrategy($store, $actor, [
            'name' => 'Preview',
            'algorithm' => 'manual',
        ]);
        $config->replaceProductOverrides($store, $strategy, $actor, [[
            'shopify_product_id' => 'gid://shopify/Product/301',
            'type' => 'manual',
        ]]);
        $component = $config->createComponent($store, $strategy, $actor, [
            'name' => 'Homepage preview',
            'placement' => 'homepage',
            'heading' => 'Recommended for you',
        ]);
        $engine = app(PersonalizationRecommendationService::class);

        $this->assertExceptionCode('COMPONENT_NOT_ACTIVE', fn () => $engine->forComponent($store, $component));
        $preview = $engine->forComponent($store, $component, preview: true);
        $this->assertSame('Homepage preview', $preview['component']['name']);
        $this->assertSame('carousel', $preview['component']['style']['layout']);
        $this->assertSame(['301'], $this->ids($preview));

        $strategy->forceFill(['enabled' => true])->save();
        $component->forceFill([
            'status' => PersonalizationComponentStatus::Active,
            'published_at' => now(),
        ])->save();
        $active = $engine->forComponent($store, $component->refresh());
        $this->assertSame(['301'], $this->ids($active));
    }

    public function test_recommendation_context_and_store_boundaries_are_strict(): void
    {
        [$actor, $organization, $store] = $this->context();
        $this->product($organization, $store, 401, 'Store Product', 'Bike', 'Deco', ['Bike'], 100, now());
        $strategy = app(PersonalizationConfigurationService::class)->createStrategy($store, $actor, [
            'name' => 'Recent',
            'algorithm' => 'recently_viewed',
        ]);
        $engine = app(PersonalizationRecommendationService::class);

        $this->assertExceptionCode('INVALID_RECOMMENDATION_CONTEXT', fn () => $engine->recommend($store, $strategy, [
            'customer_email' => 'should-not-be-accepted@example.com',
        ]));
        $this->assertExceptionCode('INVALID_RECOMMENDATION_CONTEXT', fn () => $engine->recommend($store, $strategy, [
            'recently_viewed_product_ids' => ['not-a-product-id'],
        ]));

        $otherStore = $organization->stores()->create([
            'name' => 'Other',
            'shopify_domain' => 'other-recommendation.myshopify.com',
            'status' => 'active',
            'currency' => 'USD',
        ]);
        $this->assertExceptionCode('STRATEGY_NOT_FOUND', fn () => $engine->recommend($otherStore, $strategy));

        $store->forceFill(['shopify_domain' => 'macfoxebike.myshopify.com'])->save();
        $this->assertExceptionCode('SHOP_WRITE_DENIED', fn () => $engine->recommend($store, $strategy));
    }

    /** @return array{User, Organization, Store} */
    private function context(): array
    {
        $this->seed(PermissionSeeder::class);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $organization = Organization::query()->create([
            'name' => 'Recommendation Test',
            'code' => 'recommendation-'.Str::lower(Str::random(8)),
            'status' => 'active',
        ]);
        $organization->users()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $store = $organization->stores()->create([
            'name' => 'Recommendation Store',
            'shopify_domain' => Str::lower(Str::random(12)).'.myshopify.com',
            'status' => 'active',
            'currency' => 'USD',
        ]);
        $store->members()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $this->seed(RoleSeeder::class);
        $role = Role::query()->whereBelongsTo($organization)->where('slug', 'store-admin')->firstOrFail();
        $user->roles()->attach($role, [
            'organization_id' => $organization->id,
            'store_id' => $store->id,
        ]);

        return [$user, $organization, $store];
    }

    /** @param list<string> $tags */
    private function product(
        Organization $organization,
        Store $store,
        int $shopifyId,
        string $title,
        string $type,
        string $vendor,
        array $tags,
        int $price,
        mixed $publishedAt,
    ): Product {
        $product = Product::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'shopify_product_id' => $shopifyId,
            'title' => $title,
            'handle' => Str::slug($title),
            'status' => 'active',
            'vendor' => $vendor,
            'product_type' => $type,
            'tags' => $tags,
            'created_at_shopify' => $publishedAt,
            'published_at_shopify' => $publishedAt,
            'online_store_url' => 'https://example.invalid/products/'.Str::slug($title),
            'featured_image_url' => 'https://cdn.shopify.com/'.$shopifyId.'.jpg',
            'synced_at' => now(),
        ]);
        ProductVariant::query()->create([
            'product_id' => $product->id,
            'shopify_variant_id' => $shopifyId * 10,
            'title' => 'Default',
            'sku' => 'SKU-'.$shopifyId,
            'price' => $price,
            'available_for_sale' => true,
            'selected_options' => [],
        ]);

        return $product;
    }

    /** @param list<array{Product, int}> $lines */
    private function order(Organization $organization, Store $store, int $shopifyId, array $lines): Order
    {
        $order = Order::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'shopify_order_id' => $shopifyId,
            'order_number' => 'ORDER-'.$shopifyId,
            'email' => 'customer@example.com',
            'currency' => 'USD',
            'total_price' => 100,
            'subtotal_price' => 100,
            'net_sales' => 100,
            'discount_total' => 0,
            'refund_total' => 0,
            'shipping_total' => 0,
            'total_tax' => 0,
            'is_test' => false,
            'processed_at' => now()->subDay(),
            'created_at_shopify' => now()->subDay(),
            'updated_at_shopify' => now()->subDay(),
            'synced_at' => now(),
        ]);
        foreach ($lines as $position => [$product, $quantity]) {
            $variant = $product->variants()->sole();
            $order->items()->create([
                'shopify_line_item_id' => $shopifyId * 100 + $position,
                'product_id' => $product->id,
                'variant_id' => $variant->id,
                'shopify_product_id' => $product->shopify_product_id,
                'shopify_variant_id' => $variant->shopify_variant_id,
                'title' => $product->title,
                'quantity' => $quantity,
                'current_quantity' => $quantity,
                'price' => $variant->price,
                'attributed_sales' => (float) $variant->price * $quantity,
            ]);
        }

        return $order;
    }

    private function inventory(Organization $organization, Store $store, Product $product, int $available): void
    {
        $variant = $product->variants()->sole();
        $location = Location::query()->firstOrCreate(
            ['store_id' => $store->id, 'shopify_location_id' => 1],
            [
                'organization_id' => $organization->id,
                'name' => 'Main',
                'active' => true,
                'synced_at' => now(),
            ],
        );
        $item = InventoryItem::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'shopify_inventory_item_id' => (int) $product->shopify_product_id * 100,
            'variant_id' => $variant->id,
            'shopify_variant_id' => $variant->shopify_variant_id,
            'tracked' => true,
            'synced_at' => now(),
        ]);
        InventoryLevel::query()->create([
            'inventory_item_id' => $item->id,
            'location_id' => $location->id,
            'shopify_location_id' => $location->shopify_location_id,
            'available' => $available,
            'synced_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $result @return list<string> */
    private function ids(array $result): array
    {
        return array_column($result['items'], 'shopify_product_id');
    }

    private function assertExceptionCode(string $code, callable $action): void
    {
        try {
            $action();
            $this->fail("Expected Personalization exception {$code}.");
        } catch (PersonalizationException $exception) {
            $this->assertSame($code, $exception->errorCode);
        }
    }
}
