<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Role;
use App\Models\ShopifyConnection;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ShopifyConnectionHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_connection_is_marked_connected(): void
    {
        [$user, $organization, $store] = $this->storeContext('organization-admin');
        $connection = $this->connection($store, ['status' => 'warning', 'last_error' => 'Previous error']);
        Http::fake([
            $this->graphqlUrl($store) => Http::response(['data' => ['shop' => [
                'id' => 'gid://shopify/Shop/123456',
                'name' => 'Macfox US',
                'myshopifyDomain' => $store->shopify_domain,
            ]]]),
        ]);

        $this->actingAs($user)
            ->withSession([
                'current_organization_id' => $organization->id,
                'current_store_id' => $store->id,
            ])
            ->from(route('stores.show', $store))
            ->post(route('stores.shopify.verify', $store))
            ->assertRedirect(route('stores.show', $store))
            ->assertSessionHas('success', 'Shopify Connection 验证成功。');

        $connection->refresh();
        $this->assertSame('connected', $connection->status);
        $this->assertSame(123456, $connection->shopify_shop_id);
        $this->assertNotNull($connection->last_verified_at);
        $this->assertNull($connection->last_error);
        $this->assertNull($connection->last_error_at);
        $this->assertSame('Macfox US', data_get($connection->metadata, 'health_shop.name'));

        $this->get(route('stores.show', $store))->assertInertia(fn (Assert $page) => $page
            ->where('store.data.connection.status', 'connected')
            ->where('store.data.connection.api_version', '2026-07')
            ->where('store.data.connection.last_error', null)
            ->where('store.data.connection_status', 'connected'));

        Http::assertSent(fn (Request $request) => $request->url() === $this->graphqlUrl($store)
            && $request->hasHeader('X-Shopify-Access-Token', 'test-access-token')
            && str_contains((string) $request['query'], 'query ConnectionHealth'));
    }

    public function test_invalid_api_token_is_marked_invalid_without_leaking_token(): void
    {
        [$user, $organization, $store] = $this->storeContext('organization-admin');
        $connection = $this->connection($store);
        Http::fake([$this->graphqlUrl($store) => Http::response(['errors' => 'Unauthorized'], 401)]);

        $this->actingAs($user)
            ->withSession([
                'current_organization_id' => $organization->id,
                'current_store_id' => $store->id,
            ])
            ->from(route('stores.show', $store))
            ->post(route('stores.shopify.verify', $store))
            ->assertRedirect(route('stores.show', $store))
            ->assertSessionHas('error', 'Shopify Access Token 无效或已被撤销。');

        $connection->refresh();
        $this->assertSame('invalid', $connection->status);
        $this->assertSame('Shopify Access Token 无效或已被撤销。', $connection->last_error);
        $this->assertNotNull($connection->last_error_at);
        $this->assertStringNotContainsString('test-access-token', (string) $connection->last_error);
    }

    public function test_user_without_store_management_permission_cannot_verify_connection(): void
    {
        [$user, $organization, $store] = $this->storeContext('viewer');
        $connection = $this->connection($store);
        Http::fake();

        $this->actingAs($user)
            ->withSession([
                'current_organization_id' => $organization->id,
                'current_store_id' => $store->id,
            ])
            ->post(route('stores.shopify.verify', $store))
            ->assertForbidden();

        $this->assertSame('connected', $connection->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_user_cannot_verify_store_from_another_organization(): void
    {
        [$user, $organization] = $this->userWithRole('organization-admin', 'Macfox', 'macfox');
        $other = Organization::query()->create(['name' => 'Asiwo', 'code' => 'asiwo']);
        $store = $this->store($other, 'Asiwo US', 'asiwo-us.myshopify.com');
        $connection = $this->connection($store);
        Http::fake();

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('stores.shopify.verify', $store))
            ->assertForbidden();

        $this->assertSame('connected', $connection->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_command_checks_active_connections(): void
    {
        $organization = Organization::query()->create(['name' => 'Macfox', 'code' => 'macfox']);
        $store = $this->store($organization, 'Macfox US', 'macfox-us.myshopify.com');
        $connection = $this->connection($store, ['status' => 'warning']);
        Http::fake([
            $this->graphqlUrl($store) => Http::response(['data' => ['shop' => [
                'id' => 'gid://shopify/Shop/123456',
                'name' => 'Macfox US',
                'myshopifyDomain' => $store->shopify_domain,
            ]]]),
        ]);

        $this->artisan('shopify:check-connections')
            ->expectsOutputToContain('Checked 1 connection(s): 1 connected, 0 require attention.')
            ->assertSuccessful();

        $this->assertSame('connected', $connection->fresh()->status);
        $this->assertNotNull($connection->fresh()->last_verified_at);
    }

    /** @return array{0: User, 1: Organization, 2: Store} */
    private function storeContext(string $roleSlug): array
    {
        [$user, $organization] = $this->userWithRole($roleSlug, 'Macfox', 'macfox');
        $store = $this->store($organization, 'Macfox US', 'macfox-us.myshopify.com');
        $store->members()->attach($user, ['status' => 'active', 'joined_at' => now()]);

        return [$user, $organization, $store];
    }

    /** @return array{0: User, 1: Organization} */
    private function userWithRole(string $roleSlug, string $organizationName, string $organizationCode): array
    {
        $this->seed(PermissionSeeder::class);
        $user = User::factory()->create();
        $organization = Organization::query()->create([
            'name' => $organizationName,
            'code' => $organizationCode,
        ]);
        $organization->users()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $this->seed(RoleSeeder::class);
        $role = Role::query()->whereBelongsTo($organization)->where('slug', $roleSlug)->firstOrFail();
        $user->roles()->attach($role, [
            'organization_id' => $organization->id,
            'store_id' => null,
        ]);

        return [$user, $organization];
    }

    private function store(Organization $organization, string $name, string $domain): Store
    {
        return $organization->stores()->create([
            'name' => $name,
            'shopify_domain' => $domain,
            'status' => 'active',
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function connection(Store $store, array $overrides = []): ShopifyConnection
    {
        return ShopifyConnection::query()->create([
            'store_id' => $store->id,
            'shop_domain' => $store->shopify_domain,
            'access_token_encrypted' => 'test-access-token',
            'token_type' => 'offline',
            'scopes' => ['read_products'],
            'api_version' => '2026-07',
            'installed_at' => now(),
            ...$overrides,
        ]);
    }

    private function graphqlUrl(Store $store): string
    {
        return "https://{$store->shopify_domain}/admin/api/2026-07/graphql.json";
    }
}
