<?php

namespace Tests\Feature;

use App\Models\OAuthState;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Role;
use App\Models\ShopifyConnection;
use App\Models\Store;
use App\Models\User;
use App\Services\Shopify\ShopifyOAuthService;
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
            'inertia.ssr.enabled' => false,
            'shopify.client_id' => 'existing-commerce-client',
            'shopify.client_secret' => 'existing-commerce-secret',
            'shopify.app_handle' => 'existing-commerce-test',
            'shopify.requested_scopes' => ['read_products', 'read_orders'],
            'shopify.redirect_uri' => 'https://testadmin.decomkt.com/shopify/oauth/callback',
            'shopify.api_version' => '2026-07',
        ]);
    }

    public function test_discount_page_reuses_only_the_current_store_connection_and_products(): void
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
        $this->assertDatabaseCount('app_installations', 0);

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
            && data_get($request->data(), 'variables.input.startsAt') === '2026-09-03T15:00:00+00:00'
            && data_get($request->data(), 'variables.input.customerGets.items.products.productsToAdd.0') === 'gid://shopify/Product/101');
    }

    public function test_updating_real_length_shopify_ids_preserves_target_isolation_and_idempotency(): void
    {
        [$actor, $organization, $store] = $this->context('store-admin');
        $product = $this->product($store, '101', 'X1 Bike');
        $this->installation($store, 'current-store-token');
        $ids = ['1789315613037', '1789315613038'];
        $sequence = Http::fakeSequence("https://{$store->shopify_domain}/*");
        foreach ($ids as $id) {
            $gid = "gid://shopify/DiscountCodeNode/{$id}";
            $updated = $this->detailPayload($product, $gid, 'LABOR10');
            $updated['data']['node']['codeDiscount']['customerGets']['value']['percentage'] = 0.11;
            $sequence->push($this->detailPayload($product, $gid, 'LABOR10'))
                ->push(['data' => ['discountCodeBasicUpdate' => [
                    'codeDiscountNode' => ['id' => $gid], 'userErrors' => [],
                ]]])
                ->push($updated);
        }
        $payload = $this->writePayload((string) Str::uuid(), ['101']);
        $payload['value'] = 11;

        $this->actingAs($actor)->withSession($this->contextSession($organization, $store));
        foreach ($ids as $id) {
            $gid = "gid://shopify/DiscountCodeNode/{$id}";
            $this->assertGreaterThan(40, strlen('update:'.$gid));
            $response = $this->putJson(route('discounts.update', ['discountId' => $id]), $payload)
                ->assertOk()->assertJsonPath('data.id', $gid)->assertJsonPath('data.value', 11);
            $this->putJson(route('discounts.update', ['discountId' => $id]), $payload)
                ->assertOk()->assertExactJson($response->json());
            $this->assertDatabaseHas('discount_action_idempotencies', [
                'store_id' => $store->id,
                'operation' => 'update:'.$gid,
                'shopify_discount_id' => $gid,
                'status' => 'completed',
            ]);
        }
        $this->assertDatabaseCount('discount_action_idempotencies', 2);
        Http::assertSentCount(6);
        Http::assertSent(fn ($request): bool => str_contains((string) $request['query'], 'DiscountManagerBasicUpdate')
            && data_get($request->data(), 'variables.id') === 'gid://shopify/DiscountCodeNode/1789315613037'
            && data_get($request->data(), 'variables.input.customerGets.value.percentage') === 0.11);
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
        [$actor, $organization, $store] = $this->context('super-admin');
        $otherStore = $organization->stores()->create([
            'name' => 'Other Store', 'shopify_domain' => 'other-oauth.myshopify.com', 'status' => 'active',
        ]);
        $connection = $this->installation($store, 'current-token');
        $connection->update(['scopes' => ['read_products', 'read_orders', 'read_all_orders']]);
        $otherConnection = $this->installation($otherStore, 'untouched-token');
        $before = $otherConnection->refresh()->getRawOriginal();

        $response = $this->actingAs($actor)->withSession($this->contextSession($organization, $store))
            ->post(route('discounts.connect'), ['store_id' => $store->id]);

        $response->assertRedirectContains("https://{$store->shopify_domain}/admin/oauth/authorize");
        $this->assertStringNotContainsString($otherStore->shopify_domain, (string) $response->headers->get('Location'));
        $state = OAuthState::query()->sole();
        $this->assertSame($store->id, $state->store_id);
        $this->assertSame($organization->id, $state->organization_id);
        $this->assertSame($actor->id, $state->user_id);
        $this->assertEqualsCanonicalizing(['read_products', 'read_orders', 'read_all_orders', 'read_discounts', 'write_discounts'], $state->scopes);
        $this->assertSame('existing-commerce-client', $state->app->client_id);
        $this->assertSame('https://testadmin.decomkt.com/shopify/oauth/callback', $state->redirect_uri);
        $this->assertSame($before, $otherConnection->fresh()->getRawOriginal());
        $this->assertSame(['read_products', 'read_orders', 'read_all_orders'], $connection->fresh()->scopes);
        $normal = app(ShopifyOAuthService::class)->begin($organization, $actor, $otherStore);
        $this->assertSame(['read_products', 'read_orders'], $normal['state_record']->scopes);
    }

    public function test_missing_discount_scopes_do_not_invalidate_the_existing_connection(): void
    {
        [$actor, $organization, $store] = $this->context('store-admin');
        $this->product($store, '101', 'X1');
        $connection = $this->installation($store, 'existing-token');
        $connection->update(['scopes' => ['read_products', 'read_orders']]);
        $before = $connection->refresh()->getRawOriginal();
        Http::fake();
        $this->actingAs($actor)->withSession($this->contextSession($organization, $store))
            ->get(route('discounts.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Discounts/Index')
                ->where('connection.ready', false)->where('connection.code', 'SHOPIFY_DISCOUNT_SCOPE_REQUIRED'));
        $this->postJson(route('discounts.store'), $this->writePayload((string) Str::uuid(), ['101']))
            ->assertStatus(409)->assertJsonPath('error.code', 'SHOPIFY_DISCOUNT_SCOPE_REQUIRED');
        $this->assertSame($before, $connection->fresh()->getRawOriginal());
        Http::assertNothingSent();
    }

    public function test_read_permission_allows_listing_but_not_writing(): void
    {
        [$actor, $organization, $store] = $this->context('store-admin');
        $product = $this->product($store, '101', 'X1');
        $this->installation($store, 'read-only-token')->update(['scopes' => ['read_products', 'read_discounts']]);
        Http::fake(["https://{$store->shopify_domain}/*" => Http::response($this->listPayload($product))]);
        $this->actingAs($actor)->withSession($this->contextSession($organization, $store))
            ->get(route('discounts.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Discounts/Index')
                ->where('connection.ready', true)->where('connection.can_write', false));
        $this->postJson(route('discounts.store'), $this->writePayload((string) Str::uuid(), ['101']))->assertStatus(409);
        Http::assertSentCount(1);
    }

    public function test_stale_store_form_cannot_write_or_authorize_a_different_store(): void
    {
        [$actor, $organization, $store] = $this->context('super-admin');
        $this->product($store, '101', 'X1');
        $this->installation($store, 'current-token');
        Http::fake();
        $payload = $this->writePayload((string) Str::uuid(), ['101']);
        $payload['store_id'] = $store->id + 1;
        $this->actingAs($actor)->withSession($this->contextSession($organization, $store))
            ->postJson(route('discounts.store'), $payload)->assertStatus(409);
        $this->postJson(route('discounts.connect'), ['store_id' => $store->id + 1])->assertStatus(409);
        $this->assertDatabaseCount('oauth_states', 0);
        Http::assertNothingSent();
    }

    public function test_discount_operator_without_app_install_permission_cannot_reauthorize(): void
    {
        [$actor, $organization, $store] = $this->context('operator');
        $this->actingAs($actor)->withSession($this->contextSession($organization, $store))
            ->post(route('discounts.connect'), ['store_id' => $store->id])->assertForbidden();
        $this->assertDatabaseCount('oauth_states', 0);
    }

    public function test_invalid_connection_identity_or_expiry_is_blocked_without_network_access(): void
    {
        [$actor, $organization, $store] = $this->context('store-admin');
        $connection = $this->installation($store, 'current-token');
        Http::fake();
        foreach ([['shop_domain' => 'different.myshopify.com'], ['shop_domain' => $store->shopify_domain, 'access_token_expires_at' => now()->subMinute()]] as $attributes) {
            $connection->update($attributes);
            $this->actingAs($actor)->withSession($this->contextSession($organization, $store))
                ->get(route('discounts.index'))->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component('Discounts/Index')
                    ->where('connection.ready', false)->where('connection.code', 'SHOPIFY_CONNECTION_REQUIRED'));
        }
        Http::assertNothingSent();
    }

    public function test_store_admin_can_enable_priority_monitoring_only_for_the_current_store(): void
    {
        [$actor, $organization, $store] = $this->context('store-admin');
        $otherStore = $organization->stores()->create([
            'name' => 'Other Store', 'shopify_domain' => 'other-monitor.myshopify.com', 'status' => 'active',
        ]);

        $this->actingAs($actor)->withSession($this->contextSession($organization, $store))
            ->patchJson(route('discounts.monitor.update', ['discountId' => 1789315613037]), [
                'store_id' => $store->id,
                'enabled' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.shopify_discount_id', 'gid://shopify/DiscountCodeNode/1789315613037')
            ->assertJsonPath('data.is_enabled', true);

        $this->assertDatabaseHas('shopify_discount_monitors', [
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'shopify_discount_id' => 'gid://shopify/DiscountCodeNode/1789315613037',
            'is_enabled' => true,
            'baseline_pending' => true,
        ]);
        $this->assertDatabaseMissing('shopify_discount_monitors', ['store_id' => $otherStore->id]);
        $this->assertDatabaseHas('audit_logs', [
            'store_id' => $store->id,
            'action' => 'shopify_discount_monitor_enabled',
        ]);

        $this->patchJson(route('discounts.monitor.update', ['discountId' => 1789315613037]), [
            'store_id' => $otherStore->id,
            'enabled' => false,
        ])->assertStatus(409);
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

    private function installation(Store $store, string $token): ShopifyConnection
    {
        return ShopifyConnection::query()->create([
            'store_id' => $store->id,
            'shop_domain' => $store->shopify_domain,
            'access_token_encrypted' => $token,
            'scopes' => ['read_products', 'read_discounts', 'write_discounts'],
            'api_version' => '2026-07',
            'status' => 'connected',
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
            'store_id' => Store::query()->firstOrFail()->id,
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
