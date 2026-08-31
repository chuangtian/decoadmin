<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\PersonalizationRecommendationComponent;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\Personalization\PersonalizationConfigurationService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PersonalizationStorefrontTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'personalization.environment' => 'test',
            'personalization.active.client_secret' => 'personalization-proxy-secret',
            'personalization.active_proxy_path' => '/apps/deco-personalization-test',
            'personalization.denied_shop_domains' => ['macfoxebike.myshopify.com'],
        ]);
    }

    public function test_signed_app_proxy_returns_only_active_store_scoped_recommendations(): void
    {
        [$admin, $organization, $store] = $this->context('Storefront A');
        $this->product($organization, $store, 101, 'Store A Bike');
        [$otherAdmin, $otherOrganization, $otherStore] = $this->context('Storefront B');
        $this->product($otherOrganization, $otherStore, 101, 'Store B Bike');
        $component = $this->activeManualComponent($store, $admin, 101);
        $this->activeManualComponent($otherStore, $otherAdmin, 101);

        $response = $this->getJson($this->signedUrl($store, $component->uuid, [
            'logged_in_customer_id' => '999',
            'path_prefix' => '/apps/deco-personalization-test',
            'recently_viewed_product_ids' => ['101'],
        ]));

        $response->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertJsonPath('data.component.uuid', $component->uuid)
            ->assertJsonPath('data.items.0.shopify_product_id', '101')
            ->assertJsonPath('data.items.0.title', 'Store A Bike')
            ->assertJsonMissingPath('data.items.0.email')
            ->assertJsonMissingPath('data.items.0.customer_id')
            ->assertJsonMissingPath('data.context.logged_in_customer_id');
        $this->assertStringNotContainsString('Store B Bike', $response->getContent());
    }

    public function test_proxy_rejects_wrong_secret_expired_timestamp_and_cross_store_component(): void
    {
        [$admin, $organization, $store] = $this->context('Proxy A');
        $this->product($organization, $store, 201, 'Proxy Product');
        $component = $this->activeManualComponent($store, $admin, 201);
        [$otherAdmin, $otherOrganization, $otherStore] = $this->context('Proxy B');
        $this->product($otherOrganization, $otherStore, 202, 'Other Proxy Product');
        $otherComponent = $this->activeManualComponent($otherStore, $otherAdmin, 202);

        $this->getJson($this->signedUrl($store, $component->uuid, [], 'wrong-secret'))
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'INVALID_APP_PROXY_SIGNATURE');
        $this->getJson($this->signedUrl($store, $component->uuid, [
            'timestamp' => now()->subMinutes(10)->timestamp,
        ]))->assertUnauthorized();
        $this->getJson($this->signedUrl($store, $otherComponent->uuid))
            ->assertNotFound()
            ->assertJsonPath('error.code', 'COMPONENT_NOT_FOUND');
    }

    public function test_proxy_rejects_inactive_component_and_permanent_denied_shop(): void
    {
        [$admin, $organization, $store] = $this->context('Inactive');
        $this->product($organization, $store, 301, 'Inactive Product');
        $service = app(PersonalizationConfigurationService::class);
        $strategy = $service->createStrategy($store, $admin, ['name' => 'Manual', 'algorithm' => 'manual']);
        $service->replaceProductOverrides($store, $strategy, $admin, [[
            'shopify_product_id' => 'gid://shopify/Product/301',
            'type' => 'manual',
        ]]);
        $component = $service->createComponent($store, $strategy, $admin, [
            'name' => 'Inactive',
            'placement' => 'homepage',
        ]);

        $this->getJson($this->signedUrl($store, $component->uuid))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'COMPONENT_NOT_ACTIVE');

        $store->forceFill(['shopify_domain' => 'macfoxebike.myshopify.com'])->save();
        $this->getJson($this->signedUrl($store, $component->uuid))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'SHOP_WRITE_DENIED');
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
            'online_store_url' => 'https://example.invalid/products/'.Str::slug($title),
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

    private function activeManualComponent(
        Store $store,
        User $admin,
        int $shopifyId,
    ): PersonalizationRecommendationComponent {
        $service = app(PersonalizationConfigurationService::class);
        $strategy = $service->createStrategy($store, $admin, ['name' => 'Manual', 'algorithm' => 'manual']);
        $service->replaceProductOverrides($store, $strategy, $admin, [[
            'shopify_product_id' => "gid://shopify/Product/{$shopifyId}",
            'type' => 'manual',
        ]]);
        $component = $service->createComponent($store, $strategy, $admin, [
            'name' => 'Storefront',
            'placement' => 'homepage',
        ]);

        return $service->activateComponent($store, $component, $admin);
    }

    /** @param array<string, mixed> $overrides */
    private function signedUrl(
        Store $store,
        string $componentUuid,
        array $overrides = [],
        string $secret = 'personalization-proxy-secret',
    ): string {
        $parameters = [
            'shop' => $store->shopify_domain,
            'timestamp' => now()->timestamp,
            'path_prefix' => '/apps/deco-personalization-test',
            ...$overrides,
        ];
        $pieces = [];
        foreach ($parameters as $key => $value) {
            $values = is_array($value) ? $value : [$value];
            $pieces[] = $key.'='.implode(',', array_map('strval', $values));
        }
        sort($pieces, SORT_STRING);
        $parameters['signature'] = hash_hmac('sha256', implode('', $pieces), $secret);

        return route('personalization.public.recommendations', ['component' => $componentUuid], false)
            .'?'.http_build_query($parameters);
    }
}
