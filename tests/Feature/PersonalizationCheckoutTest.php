<?php

namespace Tests\Feature;

use App\Exceptions\PersonalizationException;
use App\Models\Organization;
use App\Models\PersonalizationRecommendationComponent;
use App\Models\Product;
use App\Models\ProductCollection;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\Personalization\PersonalizationCheckoutService;
use App\Services\Personalization\PersonalizationConfigurationService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PersonalizationCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'personalization.environment' => 'test',
            'personalization.active.client_id' => 'personalization-test-client-id',
            'personalization.active.client_secret' => 'personalization-test-secret',
            'personalization.denied_shop_domains' => ['macfoxebike.myshopify.com'],
        ]);
    }

    public function test_checkout_configuration_is_off_by_default_and_supports_optional_collection_sequence_maximum(): void
    {
        [$admin, $organization, $store] = $this->context('Checkout A');
        $products = collect([101, 102, 103, 104])->map(fn (int $id): Product => $this->product($organization, $store, $id));
        $collection = $this->collection($organization, $store, 501, $products->all());
        $component = $this->activeCheckoutComponent($store, $admin, $products->first());
        $service = app(PersonalizationCheckoutService::class);

        $this->assertNull($service->configuration($store, $admin));
        $this->assertSame(['enabled' => false, 'trust_items' => [], 'collection' => null], $service->storefront($store));

        $setting = $service->save($store, $admin, [
            'enabled' => true,
            'component_uuid' => $component->uuid,
            'trust_items' => $service->defaultTrustItems(),
            'shopify_collection_id' => (string) $collection->shopify_collection_id,
            'maximum_recommendations' => 7,
        ]);
        $payload = $service->storefront($store);

        $this->assertTrue($setting->enabled);
        $this->assertSame('501', $setting->shopify_collection_id);
        $this->assertTrue($payload['enabled']);
        $this->assertSame('checkout', data_get($payload, 'component.placement'));
        $this->assertSame('ORDER_SUMMARY2', data_get($setting->settings, 'recommendation_placement'));
        $this->assertSame('gid://shopify/Collection/501', data_get($payload, 'collection.id'));
        $this->assertSame(7, $payload['sequence']['maximum_recommendations']);
        $this->assertSame(PersonalizationCheckoutService::COLLECTION_PAGE_SIZE, data_get($payload, 'sequence.page_size'));
        $this->assertSame('collection', data_get($payload, 'sequence.exhaustion'));
        $this->assertSame('collection_default', data_get($payload, 'sequence.order'));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'personalization_checkout_configuration_saved',
            'store_id' => $store->id,
            'user_id' => $admin->id,
        ]);
    }

    public function test_checkout_rejects_cross_store_collection_and_permanent_denied_store(): void
    {
        [$admin, $organization, $store] = $this->context('Checkout Scope A');
        $product = $this->product($organization, $store, 201);
        $component = $this->activeCheckoutComponent($store, $admin, $product);
        [, $otherOrganization, $otherStore] = $this->context('Checkout Scope B');
        $otherProduct = $this->product($otherOrganization, $otherStore, 202);
        $otherCollection = $this->collection($otherOrganization, $otherStore, 502, [$otherProduct]);
        $service = app(PersonalizationCheckoutService::class);

        try {
            $service->save($store, $admin, [
                'enabled' => true,
                'component_uuid' => $component->uuid,
                'trust_items' => [],
                'shopify_collection_id' => (string) $otherCollection->shopify_collection_id,
            ]);
            $this->fail('Checkout must reject a Collection from another store.');
        } catch (PersonalizationException $exception) {
            $this->assertSame('CHECKOUT_COLLECTION_NOT_FOUND', $exception->errorCode);
        }

        $store->forceFill(['shopify_domain' => 'macfoxebike.myshopify.com'])->save();
        try {
            $service->storefront($store->fresh()->load('organization'));
            $this->fail('The permanent denylist must block Checkout reads.');
        } catch (PersonalizationException $exception) {
            $this->assertSame('SHOP_WRITE_DENIED', $exception->errorCode);
        }
    }

    public function test_signed_checkout_endpoint_resolves_shop_from_token_and_returns_no_customer_data(): void
    {
        [$admin, $organization, $store] = $this->context('Checkout Endpoint');
        $product = $this->product($organization, $store, 301);
        $collection = $this->collection($organization, $store, 503, [$product]);
        $component = $this->activeCheckoutComponent($store, $admin, $product);
        app(PersonalizationCheckoutService::class)->save($store, $admin, [
            'enabled' => true,
            'component_uuid' => $component->uuid,
            'trust_items' => app(PersonalizationCheckoutService::class)->defaultTrustItems(),
            'shopify_collection_id' => (string) $collection->shopify_collection_id,
            'maximum_recommendations' => null,
        ]);

        $response = $this->withToken($this->checkoutToken($store->shopify_domain))
            ->getJson(route('personalization.checkout.configuration'));
        $response->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', '*')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.collection.id', 'gid://shopify/Collection/503')
            ->assertJsonPath('data.sequence.page_size', PersonalizationCheckoutService::COLLECTION_PAGE_SIZE)
            ->assertJsonPath('data.sequence.exhaustion', 'collection')
            ->assertJsonPath('data.sequence.maximum_recommendations', null)
            ->assertJsonMissingPath('data.store_id')
            ->assertJsonMissingPath('data.organization_id')
            ->assertJsonMissingPath('data.customer');

        $recommendations = $this->withToken($this->checkoutToken($store->shopify_domain))
            ->postJson(route('personalization.checkout.recommendations'), [
                'cart_lines' => [],
                'market' => 'us',
                'currency' => 'USD',
                'language' => 'en',
            ]);
        $recommendations->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.context.surface', 'checkout')
            ->assertJsonPath('data.items.0.shopify_product_id', '301')
            ->assertJsonPath('data.items.0.minimum_purchase_quantity', 1)
            ->assertJsonMissingPath('data.customer');
        $this->withToken($this->checkoutToken($store->shopify_domain))
            ->postJson(route('personalization.checkout.recommendations'), [
                'cart_lines' => [[
                    'product_id' => '301',
                    'variant_id' => '3010',
                    'quantity' => 1,
                ]],
            ])->assertOk()->assertJsonCount(0, 'data.items');

        $this->withToken($this->checkoutToken($store->shopify_domain, 'wrong-secret'))
            ->getJson(route('personalization.checkout.configuration'))
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'INVALID_SHOPIFY_CHECKOUT_TOKEN');
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
        $user->roles()->attach($role, [
            'organization_id' => $organization->id,
            'store_id' => $store->id,
        ]);

        return [$user, $organization, $store];
    }

    private function product(Organization $organization, Store $store, int $shopifyId): Product
    {
        $product = Product::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'shopify_product_id' => $shopifyId,
            'title' => 'Checkout product '.$shopifyId,
            'handle' => 'checkout-product-'.$shopifyId,
            'status' => 'active',
            'tags' => ['Checkout'],
            'published_at_shopify' => now(),
            'synced_at' => now(),
        ]);
        $product->variants()->create([
            'shopify_variant_id' => $shopifyId * 10,
            'title' => 'Default',
            'price' => '99.0000',
            'available_for_sale' => true,
            'selected_options' => [],
        ]);

        return $product->load('variants');
    }

    /** @param list<Product> $products */
    private function collection(Organization $organization, Store $store, int $shopifyId, array $products): ProductCollection
    {
        $collection = ProductCollection::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'shopify_collection_id' => $shopifyId,
            'title' => 'Checkout Collection '.$shopifyId,
            'handle' => 'checkout-collection-'.$shopifyId,
            'sort_order' => 'manual',
            'synced_at' => now(),
        ]);
        foreach ($products as $product) {
            $collection->products()->attach($product->id, [
                'organization_id' => $organization->id,
                'store_id' => $store->id,
                'shopify_product_id' => $product->shopify_product_id,
                'sync_batch' => (string) Str::uuid(),
            ]);
        }

        return $collection;
    }

    private function activeCheckoutComponent(Store $store, User $admin, Product $product): PersonalizationRecommendationComponent
    {
        $service = app(PersonalizationConfigurationService::class);
        $strategy = $service->createStrategy($store, $admin, [
            'name' => 'Checkout manual',
            'algorithm' => 'manual',
        ]);
        $service->replaceProductOverrides($store, $strategy, $admin, [[
            'shopify_product_id' => 'gid://shopify/Product/'.$product->shopify_product_id,
            'type' => 'manual',
        ]]);
        $component = $service->createComponent($store, $strategy, $admin, [
            'name' => 'Checkout sequence',
            'placement' => 'checkout',
            'heading' => 'Great Value Bundles for You',
            'button_label' => 'Add',
        ]);

        return $service->activateComponent($store, $component, $admin);
    }

    private function checkoutToken(string $shop, string $secret = 'personalization-test-secret'): string
    {
        $now = now()->timestamp;
        $header = $this->base64Url(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $payload = $this->base64Url(json_encode([
            'aud' => 'personalization-test-client-id',
            'dest' => $shop,
            // Checkout's documented token contract does not define `iss`.
            // Shopify may still attach one, so it must not override the
            // verified signature, audience, destination, or lifetime checks.
            'iss' => 'https://checkout.shopify.com',
            'nbf' => $now - 1,
            'iat' => $now,
            'exp' => $now + 60,
        ], JSON_THROW_ON_ERROR));
        $signature = $this->base64Url(hash_hmac('sha256', $header.'.'.$payload, $secret, true));

        return $header.'.'.$payload.'.'.$signature;
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
