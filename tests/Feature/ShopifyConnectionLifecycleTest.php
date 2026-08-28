<?php

namespace Tests\Feature;

use App\Models\App;
use App\Models\AppInstallation;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Role;
use App\Models\ShopifyConnection;
use App\Models\Store;
use App\Models\User;
use App\Services\Shopify\ShopifyConnectionLifecycleService;
use App\Services\Shopify\ShopifyOAuthService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ShopifyConnectionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set([
            'shopify.client_id' => 'test-client-id',
            'shopify.client_secret' => 'test-client-secret',
            'shopify.api_version' => '2026-07',
            'shopify.requested_scopes' => ['read_products'],
            'shopify.app_name' => 'Test Shopify App',
            'shopify.app_handle' => 'test-shopify-app',
            'shopify.app_url' => 'http://localhost:8000',
            'shopify.redirect_uri' => 'http://localhost:8000/shopify/oauth/callback',
            'shopify.state_ttl_minutes' => 10,
        ]);
    }

    public function test_invalid_connection_can_reconnect_through_existing_oauth_flow(): void
    {
        [$user, $organization, $store] = $this->storeContext('organization-admin');
        $connection = $this->connection($store, 'invalid');

        $response = $this->actingAs($user)
            ->withSession([
                'current_organization_id' => $organization->id,
                'current_store_id' => $store->id,
            ])
            ->post(route('stores.connect', $store), $this->connectionPayload($store));

        $response->assertRedirect();
        $location = (string) $response->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $authorizationQuery);
        $this->assertNotEmpty($authorizationQuery['state']);

        Http::fake([
            "https://{$store->shopify_domain}/admin/oauth/access_token" => Http::response([
                'access_token' => 'shpat_reconnected_secret',
                'scope' => 'read_products',
            ]),
        ]);

        $callbackQuery = $this->signedCallbackQuery($authorizationQuery['state'], $store->shopify_domain);
        $reconnectedStore = app(ShopifyOAuthService::class)->complete($callbackQuery, $authorizationQuery['state']);

        $connection->refresh();
        $this->assertSame($store->id, $reconnectedStore->id);
        $this->assertSame('connected', $connection->status);
        $this->assertSame('shpat_reconnected_secret', $connection->access_token_encrypted);
        $this->assertNull($connection->last_error);

        $audit = AuditLog::query()->where('action', 'shopify_connection_reconnected')->sole();
        $this->assertSame($organization->id, $audit->organization_id);
        $this->assertSame($store->id, $audit->store_id);
        $this->assertSame($user->id, $audit->user_id);
        $this->assertSame('invalid', data_get($audit->old_values, 'status'));
        $this->assertSame('connected', data_get($audit->new_values, 'status'));
        $this->assertSame('invalid', data_get($audit->metadata, 'previous_status'));
        $this->assertSame('connected', data_get($audit->metadata, 'new_status'));
    }

    public function test_disconnected_connection_can_reconnect_through_existing_oauth_flow(): void
    {
        [$user, $organization, $store] = $this->storeContext('organization-admin');
        $connection = $this->connection($store, 'disconnected');

        $response = $this->actingAs($user)
            ->withSession([
                'current_organization_id' => $organization->id,
                'current_store_id' => $store->id,
            ])
            ->post(route('stores.connect', $store), $this->connectionPayload($store));

        $response->assertRedirect();
        parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $authorizationQuery);
        $this->assertNotEmpty($authorizationQuery['state']);

        Http::fake([
            "https://{$store->shopify_domain}/admin/oauth/access_token" => Http::response([
                'access_token' => 'shpat_reconnected_after_disconnect',
                'scope' => 'read_products',
            ]),
        ]);

        app(ShopifyOAuthService::class)->complete(
            $this->signedCallbackQuery($authorizationQuery['state'], $store->shopify_domain),
            $authorizationQuery['state'],
        );

        $connection->refresh();
        $this->assertSame('connected', $connection->status);
        $this->assertSame('shpat_reconnected_after_disconnect', $connection->access_token_encrypted);

        $audit = AuditLog::query()->where('action', 'shopify_connection_reconnected')->sole();
        $this->assertSame('disconnected', data_get($audit->metadata, 'previous_status'));
        $this->assertSame('connected', data_get($audit->metadata, 'new_status'));
    }

    public function test_user_without_store_management_permission_cannot_reconnect(): void
    {
        [$user, $organization, $store] = $this->storeContext('viewer');
        $connection = $this->connection($store, 'invalid');

        $this->actingAs($user)
            ->withSession([
                'current_organization_id' => $organization->id,
                'current_store_id' => $store->id,
            ])
            ->post(route('stores.connect', $store), $this->connectionPayload($store))
            ->assertForbidden();

        $this->assertSame('invalid', $connection->fresh()->status);
        $this->assertDatabaseCount('oauth_states', 0);
    }

    public function test_store_admin_cannot_install_or_uninstall_shopify_app(): void
    {
        [$user, $organization, $store] = $this->storeContext('store-admin');

        $this->actingAs($user)
            ->withSession([
                'current_organization_id' => $organization->id,
                'current_store_id' => $store->id,
            ])
            ->post(route('stores.connect', $store))
            ->assertForbidden();

        $connection = $this->connection($store, 'connected');
        $this->actingAs($user)
            ->withSession([
                'current_organization_id' => $organization->id,
                'current_store_id' => $store->id,
            ])
            ->post(route('stores.shopify.uninstall', $store))
            ->assertForbidden();

        $this->assertSame('connected', $connection->fresh()->status);
        $this->assertDatabaseCount('oauth_states', 0);
    }

    public function test_user_cannot_reconnect_store_from_another_organization(): void
    {
        [$user, $organization] = $this->userWithRole('organization-admin', 'Macfox', 'macfox');
        $otherOrganization = Organization::query()->create(['name' => 'Asiwo', 'code' => 'asiwo']);
        $store = $this->store($otherOrganization, 'Asiwo US', 'asiwo-us.myshopify.com');
        $connection = $this->connection($store, 'invalid');

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('stores.connect', $store), $this->connectionPayload($store))
            ->assertForbidden();

        $this->assertSame('invalid', $connection->fresh()->status);
        $this->assertDatabaseCount('oauth_states', 0);
    }

    public function test_lifecycle_audit_never_contains_access_or_refresh_tokens(): void
    {
        [$user, , $store] = $this->storeContext('organization-admin');
        $connection = ShopifyConnection::query()->create([
            'store_id' => $store->id,
            'shop_domain' => $store->shopify_domain,
            'access_token_encrypted' => 'shpat_sensitive_access_token',
            'refresh_token_encrypted' => 'shprt_sensitive_refresh_token',
            'token_type' => 'offline',
            'scopes' => ['read_products'],
            'api_version' => '2026-07',
            'status' => 'connected',
            'installed_at' => now(),
        ]);

        app(ShopifyConnectionLifecycleService::class)->markInvalid(
            $connection,
            'Invalid token shpat_sensitive_access_token and shprt_sensitive_refresh_token',
            $user,
            apiChecked: true,
        );

        $audit = AuditLog::query()->where('action', 'shopify_connection_invalid')->sole();
        $serialized = json_encode($audit->toArray(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('shpat_sensitive_access_token', $serialized);
        $this->assertStringNotContainsString('shprt_sensitive_refresh_token', $serialized);
        $this->assertStringContainsString('[redacted]', data_get($audit->metadata, 'reason'));
    }

    public function test_disconnected_transition_creates_connection_audit(): void
    {
        [$user, $organization, $store] = $this->storeContext('organization-admin');
        $connection = $this->connection($store, 'connected');

        app(ShopifyConnectionLifecycleService::class)->markDisconnected(
            $connection,
            'Shopify App 已卸载。',
            $user,
        );

        $this->assertSame('disconnected', $connection->fresh()->status);
        $audit = AuditLog::query()->where('action', 'shopify_connection_disconnected')->sole();
        $this->assertSame($organization->id, $audit->organization_id);
        $this->assertSame($store->id, $audit->store_id);
        $this->assertSame($user->id, $audit->user_id);
        $this->assertSame('Shopify App 已卸载。', data_get($audit->metadata, 'reason'));
    }

    public function test_organization_admin_can_truly_uninstall_and_revoke_connection(): void
    {
        [$user, $organization, $store] = $this->storeContext('organization-admin');
        $connection = $this->connection($store, 'connected');
        $installation = $this->installation($connection, $user);
        Http::fake([
            "https://{$store->shopify_domain}/admin/api/2026-07/graphql.json" => Http::response([
                'data' => ['appUninstall' => [
                    'app' => ['id' => 'gid://shopify/App/1'],
                    'userErrors' => [],
                ]],
            ]),
        ]);

        $this->actingAs($user)
            ->withSession([
                'current_organization_id' => $organization->id,
                'current_store_id' => $store->id,
            ])
            ->post(route('stores.shopify.uninstall', $store))
            ->assertRedirect()
            ->assertSessionHas('success');

        $connection->refresh();
        $this->assertSame('disconnected', $connection->status);
        $this->assertNull($connection->access_token_encrypted);
        $this->assertNotNull($connection->uninstalled_at);
        $this->assertSame('uninstalled', $installation->fresh()->status);
        $this->assertDatabaseCount('shopify_connections', 1);

        $audit = AuditLog::query()->where('action', 'shopify_app_uninstalled')->sole();
        $this->assertSame($organization->id, $audit->organization_id);
        $this->assertSame($store->id, $audit->store_id);
        $this->assertSame($user->id, $audit->user_id);
        $this->assertSame('管理员从 decoAdmin 真正卸载 Shopify 应用。', data_get($audit->metadata, 'reason'));
        $this->assertSame('connected', data_get($audit->metadata, 'previous_status'));
        $this->assertSame('disconnected', data_get($audit->metadata, 'new_status'));
        $this->assertStringNotContainsString('test-access-token', json_encode($audit->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_operator_cannot_disconnect_connection(): void
    {
        [$user, $organization, $store] = $this->storeContext('operator');
        $connection = $this->connection($store, 'connected');

        $this->actingAs($user)
            ->withSession([
                'current_organization_id' => $organization->id,
                'current_store_id' => $store->id,
            ])
            ->post(route('stores.shopify.uninstall', $store))
            ->assertForbidden();

        $this->assertSame('connected', $connection->fresh()->status);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_user_cannot_disconnect_store_from_another_organization(): void
    {
        [$user, $organization] = $this->userWithRole('organization-admin', 'Macfox', 'macfox');
        $otherOrganization = Organization::query()->create(['name' => 'Asiwo', 'code' => 'asiwo']);
        $store = $this->store($otherOrganization, 'Asiwo US', 'asiwo-us.myshopify.com');
        $connection = $this->connection($store, 'connected');

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('stores.shopify.uninstall', $store))
            ->assertForbidden();

        $this->assertSame('connected', $connection->fresh()->status);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_store_detail_only_exposes_connection_history_for_current_store(): void
    {
        [$user, $organization, $store] = $this->storeContext('organization-admin');
        $connection = $this->connection($store, 'connected');
        app(ShopifyConnectionLifecycleService::class)->markDisconnected($connection, '管理员主动断开 Shopify 连接。', $user);

        $otherStore = $this->store($organization, 'Macfox EU', 'macfox-eu.myshopify.com');
        $otherStore->members()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $otherConnection = $this->connection($otherStore, 'connected');
        app(ShopifyConnectionLifecycleService::class)->markInvalid($otherConnection, 'Other store token invalid.');

        $this->actingAs($user)
            ->withSession([
                'current_organization_id' => $organization->id,
                'current_store_id' => $store->id,
            ])
            ->get(route('stores.show', $store))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Stores/Show')
                ->has('connectionHistory', 1)
                ->where('connectionHistory.0.action', 'shopify_connection_disconnected')
                ->where('connectionHistory.0.actor', $user->name)
                ->where('connectionHistory.0.previous_status', 'connected')
                ->where('connectionHistory.0.new_status', 'disconnected'));
    }

    public function test_uninstalled_store_data_is_erased_after_48_hours_and_settings_after_30_days(): void
    {
        [$user, , $store] = $this->storeContext('organization-admin');
        $connection = $this->connection($store, 'connected');
        $installation = $this->installation($connection, $user);
        $installation->forceFill(['settings' => ['template' => 'retained temporarily']])->save();
        Customer::query()->create([
            'organization_id' => $store->organization_id,
            'store_id' => $store->id,
            'shopify_customer_id' => 1001,
            'email' => 'customer@example.com',
            'phone' => '+15550000000',
            'created_at_shopify' => now(),
            'updated_at_shopify' => now(),
            'synced_at' => now(),
        ]);
        $order = Order::query()->create([
            'organization_id' => $store->organization_id,
            'store_id' => $store->id,
            'shopify_order_id' => 2001,
            'shopify_customer_id' => 1001,
            'order_number' => '#2001',
            'email' => 'customer@example.com',
            'currency' => 'USD',
            'total_price' => 100,
            'subtotal_price' => 90,
            'total_tax' => 10,
            'created_at_shopify' => now(),
            'synced_at' => now(),
        ]);

        app(ShopifyConnectionLifecycleService::class)
            ->markUninstalled($connection, 'Test uninstall.', $user);

        $this->travel(49)->hours();
        $this->artisan('shopify:prune-uninstalled-data')->assertSuccessful();

        $this->assertDatabaseCount('customers', 0);
        $this->assertNull($order->fresh()->email);
        $this->assertNull($order->fresh()->shopify_customer_id);
        $this->assertSame(['template' => 'retained temporarily'], $installation->fresh()->settings);
        $this->assertNotNull(data_get($connection->fresh()->metadata, 'personal_data_erased_at'));

        $this->travel(29)->days();
        $this->artisan('shopify:prune-uninstalled-data')->assertSuccessful();

        $this->assertNull($installation->fresh()->settings);
        $this->assertNotNull(data_get($connection->fresh()->metadata, 'configuration_purged_at'));
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

    private function connection(Store $store, string $status): ShopifyConnection
    {
        return ShopifyConnection::query()->create([
            'store_id' => $store->id,
            'shop_domain' => $store->shopify_domain,
            'access_token_encrypted' => 'test-access-token',
            'token_type' => 'offline',
            'scopes' => ['read_products'],
            'api_version' => '2026-07',
            'status' => $status,
            'last_error' => $status === 'invalid' ? 'Invalid token' : null,
            'last_error_at' => $status === 'invalid' ? now() : null,
            'installed_at' => now(),
        ]);
    }

    private function installation(ShopifyConnection $connection, User $user): AppInstallation
    {
        $app = App::query()->create([
            'name' => 'Shopify Commerce Hub',
            'handle' => 'test-shopify-app',
            'client_id' => 'test-client-id',
            'client_secret_encrypted' => 'test-client-secret',
            'distribution' => 'custom',
            'status' => 'active',
        ]);

        return AppInstallation::query()->create([
            'app_id' => $app->id,
            'store_id' => $connection->store_id,
            'shopify_connection_id' => $connection->id,
            'installed_by' => $user->id,
            'status' => 'active',
            'granted_scopes' => ['read_products'],
            'installed_at' => now(),
        ]);
    }

    /** @return array{name: string, shop_domain: string, environment: string} */
    private function connectionPayload(Store $store): array
    {
        return [
            'name' => $store->name,
            'shop_domain' => $store->shopify_domain,
            'environment' => 'production',
        ];
    }

    /** @return array<string, string|int> */
    private function signedCallbackQuery(string $state, string $shop): array
    {
        $query = [
            'code' => 'authorization-code',
            'shop' => $shop,
            'state' => $state,
            'timestamp' => now()->timestamp,
        ];
        ksort($query, SORT_STRING);
        $message = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $query['hmac'] = hash_hmac('sha256', $message, 'test-client-secret');

        return $query;
    }
}
