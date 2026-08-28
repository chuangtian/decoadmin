<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductCollection;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Services\Personalization\PersonalizationCatalogService;
use App\Services\Personalization\PersonalizationOrderSignalService;
use App\Services\Shopify\Products\ShopifyCollectionDataService;
use App\Services\Shopify\Products\ShopifyProductDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PersonalizationDataFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_candidates_apply_store_tags_collection_price_inventory_and_privacy_boundaries(): void
    {
        [$organization, $store] = $this->store('Catalog A');
        [, $otherStore] = $this->store('Catalog B');
        $product = $this->product($organization, $store, 101, 'Featured Bike', ['Featured', 'Bike']);
        $this->variant($product, 201, '1299.00', true);
        $collection = ProductCollection::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'shopify_collection_id' => 501,
            'title' => 'Bikes',
            'handle' => 'bikes',
            'sort_order' => 'manual',
            'updated_at_shopify' => now(),
            'sync_batch' => (string) Str::uuid(),
            'synced_at' => now(),
        ]);
        $product->collections()->attach($collection->id, [
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'shopify_product_id' => $product->shopify_product_id,
            'sync_batch' => (string) Str::uuid(),
        ]);
        $outOfStock = $this->product($organization, $store, 102, 'Sold Out Bike', ['Featured', 'Bike']);
        $this->variant($outOfStock, 202, '999.00', false);
        $otherProduct = $this->product($otherStore->organization, $otherStore, 101, 'Other Store Bike', ['Featured', 'Bike']);
        $this->variant($otherProduct, 201, '1099.00', true);

        $candidates = app(PersonalizationCatalogService::class)->candidates($store, [
            'tags' => ['Featured'],
            'collection_ids' => [501],
            'min_price' => '1000',
            'max_price' => '1500',
        ]);

        $this->assertCount(1, $candidates);
        $candidate = $candidates->sole();
        $this->assertSame('101', $candidate['shopify_product_id']);
        $this->assertSame('501', $candidate['collections'][0]['shopify_collection_id']);
        $this->assertSame('1299.0000', $candidate['price']['minimum']);
        $this->assertTrue($candidate['variants'][0]['available_for_sale']);
        $this->assertArrayNotHasKey('email', $candidate);
        $this->assertArrayNotHasKey('customer_id', $candidate);
        $this->assertStringNotContainsString('Other Store Bike', json_encode($candidate, JSON_THROW_ON_ERROR));
    }

    public function test_order_signals_are_aggregate_store_scoped_and_exclude_cancelled_test_and_personal_data(): void
    {
        [$organization, $store] = $this->store('Signals A');
        [$otherOrganization, $otherStore] = $this->store('Signals B');
        $target = $this->product($organization, $store, 101, 'Target', ['Bike']);
        $companion = $this->product($organization, $store, 102, 'Companion', ['Accessory']);
        $this->variant($target, 201, '1000.00', true);
        $this->variant($companion, 202, '50.00', true);
        $this->order($organization, $store, 701, false, null, [
            [$target, 801, 1],
            [$companion, 802, 2],
        ]);
        $this->order($organization, $store, 702, false, now(), [
            [$target, 803, 1],
            [$companion, 804, 5],
        ]);
        $this->order($organization, $store, 703, true, null, [
            [$target, 805, 1],
            [$companion, 806, 7],
        ]);
        $otherTarget = $this->product($otherOrganization, $otherStore, 101, 'Other Target', ['Bike']);
        $otherCompanion = $this->product($otherOrganization, $otherStore, 102, 'Other Companion', ['Accessory']);
        $this->variant($otherTarget, 301, '1000.00', true);
        $this->variant($otherCompanion, 302, '50.00', true);
        $this->order($otherOrganization, $otherStore, 704, false, null, [
            [$otherTarget, 807, 1],
            [$otherCompanion, 808, 9],
        ]);

        $signals = app(PersonalizationOrderSignalService::class)
            ->frequentlyBoughtTogether($store, 101);

        $this->assertSame([[
            'shopify_product_id' => '102',
            'support_orders' => 1,
            'units' => 2,
        ]], $signals->all());
        $serialized = json_encode($signals->all(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('customer@example.com', $serialized);
        $this->assertStringNotContainsString('ORDER-', $serialized);
    }

    public function test_unresolved_collection_membership_is_resolved_only_by_the_matching_store_product_sync(): void
    {
        [$organization, $store] = $this->store('Resolution A');
        [$otherOrganization, $otherStore] = $this->store('Resolution B');
        $collections = app(ShopifyCollectionDataService::class);
        $batch = (string) Str::uuid();
        $collection = $collections->upsert($store, $this->collectionNode(501), $batch)['collection'];
        $otherCollection = $collections->upsert($otherStore, $this->collectionNode(501), $batch)['collection'];
        $collections->syncMembershipPage($store, $collection, ['gid://shopify/Product/101'], $batch);
        $collections->syncMembershipPage($otherStore, $otherCollection, ['gid://shopify/Product/101'], $batch);

        $this->assertDatabaseHas('product_collection_memberships', [
            'store_id' => $store->id,
            'shopify_product_id' => 101,
            'product_id' => null,
        ]);

        app(ShopifyProductDataService::class)->upsert($store, [
            'id' => 'gid://shopify/Product/101',
            'title' => 'Resolved Product',
            'handle' => 'resolved-product',
            'status' => 'ACTIVE',
            'vendor' => 'Deco',
            'productType' => 'Bike',
            'description' => null,
        ], [[
            'id' => 'gid://shopify/ProductVariant/201',
            'title' => 'Default',
            'sku' => 'RESOLVED',
            'price' => '100.00',
            'inventoryItem' => null,
        ]]);

        $resolvedProduct = Product::query()->forStore($store)->sole();
        $this->assertDatabaseHas('product_collection_memberships', [
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'shopify_product_id' => 101,
            'product_id' => $resolvedProduct->id,
        ]);
        $this->assertDatabaseHas('product_collection_memberships', [
            'organization_id' => $otherOrganization->id,
            'store_id' => $otherStore->id,
            'shopify_product_id' => 101,
            'product_id' => null,
        ]);
    }

    /** @return array{Organization, Store} */
    private function store(string $name): array
    {
        $organization = Organization::query()->create([
            'name' => $name,
            'code' => Str::slug($name).'-'.Str::lower(Str::random(6)),
        ]);
        $store = $organization->stores()->create([
            'name' => $name,
            'shopify_domain' => Str::lower(Str::random(12)).'.myshopify.com',
            'status' => 'active',
            'currency' => 'USD',
        ]);

        return [$organization, $store];
    }

    /** @param list<string> $tags */
    private function product(
        Organization $organization,
        Store $store,
        int $shopifyProductId,
        string $title,
        array $tags,
    ): Product {
        return Product::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'shopify_product_id' => $shopifyProductId,
            'title' => $title,
            'handle' => Str::slug($title),
            'status' => 'active',
            'vendor' => 'Deco',
            'product_type' => 'Bike',
            'description' => null,
            'tags' => $tags,
            'created_at_shopify' => now()->subMonth(),
            'published_at_shopify' => now()->subWeek(),
            'online_store_url' => 'https://example.myshopify.com/products/'.Str::slug($title),
            'featured_image_url' => 'https://cdn.shopify.com/'.Str::slug($title).'.jpg',
            'featured_image_alt' => $title,
            'featured_image_width' => 1200,
            'featured_image_height' => 800,
            'synced_at' => now(),
        ]);
    }

    private function variant(Product $product, int $shopifyVariantId, string $price, bool $available): ProductVariant
    {
        return ProductVariant::query()->create([
            'product_id' => $product->id,
            'shopify_variant_id' => $shopifyVariantId,
            'title' => 'Default',
            'sku' => 'SKU-'.$shopifyVariantId,
            'price' => $price,
            'compare_at_price' => null,
            'available_for_sale' => $available,
            'selected_options' => [],
        ]);
    }

    /** @param list<array{Product, int, int}> $lines */
    private function order(
        Organization $organization,
        Store $store,
        int $shopifyOrderId,
        bool $test,
        mixed $cancelledAt,
        array $lines,
    ): Order {
        $order = Order::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'shopify_order_id' => $shopifyOrderId,
            'shopify_customer_id' => 999,
            'order_number' => 'ORDER-'.$shopifyOrderId,
            'email' => 'customer@example.com',
            'financial_status' => 'paid',
            'currency' => 'USD',
            'total_price' => '1100.00',
            'subtotal_price' => '1050.00',
            'net_sales' => '1050.00',
            'discount_total' => '0',
            'refund_total' => '0',
            'shipping_total' => '50.00',
            'total_tax' => '0',
            'is_test' => $test,
            'processed_at' => now()->subDay(),
            'cancelled_at' => $cancelledAt,
            'created_at_shopify' => now()->subDay(),
            'updated_at_shopify' => now()->subDay(),
            'synced_at' => now(),
        ]);

        foreach ($lines as [$product, $lineItemId, $quantity]) {
            $variant = $product->variants()->firstOrFail();
            $order->items()->create([
                'shopify_line_item_id' => $lineItemId,
                'product_id' => $product->id,
                'variant_id' => $variant->id,
                'shopify_product_id' => $product->shopify_product_id,
                'shopify_variant_id' => $variant->shopify_variant_id,
                'title' => $product->title,
                'quantity' => $quantity,
                'current_quantity' => $quantity,
                'price' => $variant->price,
                'attributed_sales' => (string) ((float) $variant->price * $quantity),
            ]);
        }

        return $order;
    }

    /** @return array<string, mixed> */
    private function collectionNode(int $id): array
    {
        return [
            'id' => "gid://shopify/Collection/{$id}",
            'title' => 'Bikes',
            'handle' => 'bikes',
            'sortOrder' => 'MANUAL',
            'updatedAt' => now()->toIso8601String(),
        ];
    }
}
