<?php

namespace Tests\Feature;

use App\Models\App;
use App\Models\AppInstallation;
use App\Models\OAuthState;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Role;
use App\Models\ShopifyConnection;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DiscountManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'discount_manager.environment' => 'test',
            'discount_manager.active.client_id' => 'discount-manager-client',
            'discount_manager.active.client_secret' => 'discount-manager-secret',
            'discount_manager.active.name' => 'Deco 折扣管理测试',
            'discount_manager.active.handle' => 'deco-discount-manager-test',
            'discount_manager.required_scopes' => ['read_discounts', 'write_discounts', 'read_products'],
            'shopify.api_version' => '2026-07',
        ]);
    }

    public function test_discount_page_reads_only_the_current_store_installation_and_products(): void
    {
        [$actor, $organization, $store] = $this->context('store-admin');
        $product = $this->product($store, '101', 'X1 Bike');
        $otherStore = $organization->stores()->create([
            'name' => 'Other Store', 'shopify_domain' => 'other-discounts.myshopify.com', 'status' => 'active',
        ]);
        $this->installation($store, 'current-store-token');
        $this->installation($otherStore, 'other-store-token');
        Http::fake(["https://{$store->shopify_domain}/*" => Http::response($this->listPayload($product), 200)]);

        $this->actingAs($actor)->withSession($this->contextSession($organization, $store))
            ->get(route('discounts.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Discounts/Index')
                ->where('store.id', $store->id)
                ->where('products.0.id', '101')
                ->where('discounts.data.0.title', 'X1 九折')
                ->where('discounts.data.0.product_ids.0', '101')
                ->where('discounts.data.0.editable', true)
                ->where('permissions.manage', true));

        Http::assertSent(fn ($request): bool => $request->hasHeader('X-Shopify-Access-Token', 'current-store-token')
            && $request->url() === "https://{$store->shopify_domain}/admin/api/2026-07/graphql.json"
            && str_contains((string) $request['query'], 'method:code') === false
            && data_get($request->data(), 'variables.query') === 'method:code');
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), $otherStore->shopify_domain));
    }

    public function test_store_admin_can_create_current_store_discount_idempotently(): void
    {
        [$actor, $organization, $store] = $this->context('store-admin');
        $product = $this->product($store, '101', 'X1 Bike');
        $this->installation($store, 'current-store-token');
        $detail = $this->detailPayload($product, 'gid://shopify/DiscountCodeNode/22', 'LABOR10');
        Http::fakeSequence("https://{$store->shopify_domain}/*")
            ->push(['data' => ['discountCodeBasicCreate' => ['codeDiscountNode' => ['id' => 'gid://shopify/DiscountCodeNode/22'], 'userErrors' => []]]])
            ->push($detail);
        $payload = $this->writePayload((string) Str::uuid(), ['101']);

        $response = $this->actingAs($actor)->withSession($this->contextSession($organization, $store))
            ->postJson(route('discounts.store'), $payload)
            ->assertCreated()
            ->assertJsonPath('data.id', 'gid://shopify/DiscountCodeNode/22')
            ->assertJsonPath('data.codes.0', 'LABOR10');
        $this->assertDatabaseHas('audit_logs', ['store_id' => $store->id, 'action' => 'discount_created']);
        $this->assertDatabaseHas('discount_action_idempotencies', [
            'store_id' => $store->id, 'user_id' => $actor->id, 'status' => 'completed',
        ]);

        $this->actingAs($actor)->withSession($this->contextSession($organization, $store))
            ->postJson(route('discounts.store'), $payload)
            ->assertCreated()
            ->assertExactJson($response->json());
        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => str_contains((string) $request['query'], 'DiscountManagerBasicCreate')
            && data_get($request->data(), 'variables.input.context.all') === 'ALL'
            && data_get($request->data(), 'variables.input.customerGets.items.products.productsToAdd.0') === 'gid://shopify/Product/101');
    }

    public function test_cross_store_product_and_read_only_user_cannot_write_discount(): void
    {
        [$viewer, $organization, $store] = $this->context('viewer');
        $otherStore = $organization->stores()->create([
            'name' => 'Other Store', 'shopify_domain' => 'other-products.myshopify.com', 'status' => 'active',
        ]);
        $this->product($otherStore, '999', 'Other Product');
        $this->installation($store, 'viewer-store-token');
        Http::fake();

        $this->actingAs($viewer)->withSession($this->contextSession($organization, $store))
            ->postJson(route('discounts.store'), $this->writePayload((string) Str::uuid(), ['999']))
            ->assertForbidden();
        Http::assertNothingSent();

        [$admin] = $this->addUser($organization, $store, 'store-admin');
        $this->actingAs($admin)->withSession($this->contextSession($organization, $store))
            ->postJson(route('discounts.store'), $this->writePayload((string) Str::uuid(), ['999']))
            ->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_connect_authorization_is_bound_to_the_current_store(): void
    {
        [$actor, $organization, $store] = $this->context('store-admin');
        $otherStore = $organization->stores()->create([
            'name' => 'Other Store', 'shopify_domain' => 'other-oauth.myshopify.com', 'status' => 'active',
        ]);

        $response = $this->actingAs($actor)->withSession($this->contextSession($organization, $store))
            ->post(route('discounts.connect'));

        $response->assertRedirectContains("https://{$store->shopify_domain}/admin/oauth/authorize");
        $this->assertStringNotContainsString($otherStore->shopify_domain, (string) $response->headers->get('Location'));
        $state = OAuthState::query()->sole();
        $this->assertSame($store->id, $state->store_id);
        $this->assertSame($organization->id, $state->organization_id);
        $this->assertSame($actor->id, $state->user_id);
        $this->assertSame(['read_discounts', 'write_discounts', 'read_products'], $state->scopes);
    }

    /** @return array{User, Organization, Store} */
    private function context(string $role): array
    {
        $this->seed(PermissionSeeder::class);
        $organization = Organization::query()->create(['name' => 'Discount Org', 'code' => 'discount-org', 'status' => 'active']);
        $store = $organization->stores()->create([
            'name' => 'Discount Store', 'shopify_domain' => 'discount-store.myshopify.com',
            'status' => 'active', 'currency' => 'USD', 'timezone' => 'America/Los_Angeles',
        ]);
        [$user] = $this->addUser($organization, $store, $role);

        return [$user, $organization, $store];
    }

    /** @return array{User} */
    private function addUser(Organization $organization, Store $store, string $role): array
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $organization->users()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $store->members()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $this->seed(RoleSeeder::class);
        $roleModel = Role::query()->whereBelongsTo($organization)->where('slug', $role)->firstOrFail();
        $user->roles()->attach($roleModel, ['organization_id' => $organization->id, 'store_id' => $store->id]);

        return [$user];
    }

    private function installation(Store $store, string $token): AppInstallation
    {
        $connection = ShopifyConnection::query()->firstOrCreate(['store_id' => $store->id], [
            'shop_domain' => $store->shopify_domain,
            'access_token_encrypted' => 'commerce-token-'.$store->id,
            'scopes' => ['read_products'],
            'api_version' => '2026-07',
            'status' => 'connected',
            'installed_at' => now(),
        ]);
        $app = App::query()->firstOrCreate(['handle' => 'deco-discount-manager-test'], [
            'organization_id' => null,
            'name' => 'Deco 折扣管理测试',
            'client_id' => 'discount-manager-client',
            'client_secret_encrypted' => 'discount-manager-secret',
            'distribution' => 'custom',
            'status' => 'active',
            'scopes' => ['read_discounts', 'read_products', 'write_discounts'],
            'webhook_api_version' => '2026-07',
        ]);

        return AppInstallation::query()->create([
            'app_id' => $app->id,
            'store_id' => $store->id,
            'shopify_connection_id' => $connection->id,
            'status' => 'active',
            'granted_scopes' => ['read_discounts', 'write_discounts', 'read_products'],
            'access_token_encrypted' => $token,
            'refresh_token_encrypted' => 'refresh-'.$store->id,
            'token_type' => 'offline',
            'access_token_expires_at' => now()->addHour(),
            'refresh_token_expires_at' => now()->addDays(30),
            'installed_at' => now(),
        ]);
    }

    private function product(Store $store, string $id, string $title): Product
    {
        return Product::query()->create([
            'organization_id' => $store->organization_id,
            'store_id' => $store->id,
            'shopify_product_id' => $id,
            'title' => $title,
            'handle' => Str::slug($title),
            'status' => 'active',
            'synced_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function listPayload(Product $product): array
    {
        return ['data' => ['discountNodes' => [
            'nodes' => [[
                'id' => 'gid://shopify/DiscountCodeNode/11',
                'discount' => $this->basicDiscount($product, 'X1 九折', 'X1OFF10'),
            ]],
            'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
        ]]];
    }

    /** @return array<string, mixed> */
    private function detailPayload(Product $product, string $id, string $code): array
    {
        return ['data' => ['node' => ['id' => $id, 'codeDiscount' => $this->basicDiscount($product, '劳动节九折', $code)]]];
    }

    /** @return array<string, mixed> */
    private function basicDiscount(Product $product, string $title, string $code): array
    {
        return [
            '__typename' => 'DiscountCodeBasic',
            'title' => $title,
            'summary' => '10% off selected products',
            'status' => 'ACTIVE',
            'startsAt' => '2026-09-03T00:00:00Z',
            'endsAt' => null,
            'discountClasses' => ['PRODUCT'],
            'codes' => ['nodes' => [['code' => $code]]],
            'usageLimit' => null,
            'asyncUsageCount' => 0,
            'appliesOncePerCustomer' => false,
            'combinesWith' => ['orderDiscounts' => false, 'productDiscounts' => false, 'shippingDiscounts' => false],
            'context' => ['__typename' => 'DiscountBuyerSelectionAll', 'all' => 'ALL'],
            'customerGets' => [
                'value' => ['percentage' => 0.1],
                'items' => [
                    '__typename' => 'DiscountProducts',
                    'products' => ['nodes' => [['id' => 'gid://shopify/Product/'.$product->shopify_product_id, 'title' => $product->title]]],
                    'productVariants' => ['nodes' => []],
                ],
            ],
            'minimumRequirement' => null,
        ];
    }

    /** @param list<string> $productIds @return array<string, mixed> */
    private function writePayload(string $key, array $productIds): array
    {
        return [
            'idempotency_key' => $key,
            'kind' => 'product_amount',
            'title' => '劳动节九折',
            'code' => 'LABOR10',
            'starts_at' => '2026-09-03T08:00',
            'ends_at' => null,
            'usage_limit' => null,
            'applies_once_per_customer' => false,
            'combine_order' => false,
            'combine_product' => false,
            'combine_shipping' => false,
            'minimum_type' => 'none',
            'minimum_subtotal' => null,
            'minimum_quantity' => null,
            'value_type' => 'percentage',
            'value' => 10,
            'product_ids' => $productIds,
            'buys_product_ids' => [],
            'gets_product_ids' => [],
            'buys_quantity' => null,
            'gets_quantity' => null,
            'gets_percentage' => null,
            'uses_per_order_limit' => null,
        ];
    }

    /** @return array{current_organization_id: int, current_store_id: int} */
    private function contextSession(Organization $organization, Store $store): array
    {
        return ['current_organization_id' => $organization->id, 'current_store_id' => $store->id];
    }
}
