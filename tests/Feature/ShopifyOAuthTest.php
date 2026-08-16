<?php

namespace Tests\Feature;

use App\Exceptions\ShopifyOAuthException;
use App\Models\App;
use App\Models\AppInstallation;
use App\Models\OAuthState;
use App\Models\Organization;
use App\Models\ShopifyConnection;
use App\Models\Store;
use App\Models\User;
use App\Services\Shopify\ShopifyOAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopifyOAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set([
            'shopify.client_id' => 'test-client-id',
            'shopify.client_secret' => 'test-client-secret',
            'shopify.api_version' => '2026-07',
            'shopify.requested_scopes' => ['read_products', 'read_orders'],
            'shopify.app_name' => 'Test Shopify App',
            'shopify.app_handle' => 'test-shopify-app',
            'shopify.app_url' => 'http://localhost:8000',
            'shopify.redirect_uri' => 'http://localhost:8000/shopify/oauth/callback',
            'shopify.state_ttl_minutes' => 10,
        ]);
    }

    public function test_store_creation_generates_authorization_url_and_persists_hashed_state(): void
    {
        [$user, $organization] = $this->superAdminContext();

        $response = $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('stores.store'), [
                'name' => 'Macfox US',
                'shop_domain' => 'macfox-us.myshopify.com',
                'environment' => 'development',
            ]);

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertIsString($location);
        $this->assertStringStartsWith('https://macfox-us.myshopify.com/admin/oauth/authorize?', $location);

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('test-client-id', $query['client_id']);
        $this->assertSame('read_products,read_orders', $query['scope']);
        $this->assertSame('http://localhost:8000/shopify/oauth/callback', $query['redirect_uri']);
        $this->assertNotEmpty($query['state']);

        $state = OAuthState::query()->sole();
        $this->assertSame(hash('sha256', $query['state']), $state->state_hash);
        $this->assertNotSame($query['state'], $state->state_hash);
        $store = Store::query()->sole();
        $this->assertSame('pending', $store->status);
        $this->assertSame('development', data_get($store->settings, 'environment'));
    }

    public function test_expired_oauth_state_is_rejected(): void
    {
        [$user, $organization] = $this->superAdminContext();
        $authorization = app(ShopifyOAuthService::class)->begin(
            $organization,
            $user,
            'Macfox US',
            'macfox-us.myshopify.com',
        );
        $authorization['state_record']->forceFill(['expires_at' => now()->subMinute()])->save();
        $query = $this->signedCallbackQuery($authorization['state'], 'macfox-us.myshopify.com');

        $this->expectException(ShopifyOAuthException::class);
        app(ShopifyOAuthService::class)->complete($query, $authorization['state']);
    }

    public function test_callback_with_invalid_hmac_is_rejected(): void
    {
        [$user, $organization] = $this->superAdminContext();
        $authorization = app(ShopifyOAuthService::class)->begin(
            $organization,
            $user,
            'Macfox US',
            'macfox-us.myshopify.com',
        );
        $query = $this->signedCallbackQuery($authorization['state'], 'macfox-us.myshopify.com');
        $query['hmac'] = str_repeat('0', 64);

        $this->withCookie(ShopifyOAuthService::STATE_COOKIE, $authorization['state'])
            ->get(route('shopify.oauth.callback', $query))
            ->assertForbidden();

        $this->assertNull($authorization['state_record']->fresh()->consumed_at);
        $this->assertDatabaseCount('shopify_connections', 0);
    }

    public function test_successful_callback_stores_encrypted_token_and_creates_installation_without_leaking_secrets(): void
    {
        Http::fake([
            'https://macfox-us.myshopify.com/admin/oauth/access_token' => Http::response([
                'access_token' => 'shpat_sensitive_access_token',
                'scope' => 'read_products,read_orders',
            ]),
        ]);
        [$user, $organization] = $this->superAdminContext();
        $authorization = app(ShopifyOAuthService::class)->begin(
            $organization,
            $user,
            'Macfox US',
            'macfox-us.myshopify.com',
        );
        $query = $this->signedCallbackQuery($authorization['state'], 'macfox-us.myshopify.com');

        $store = app(ShopifyOAuthService::class)->complete($query, $authorization['state']);

        $connection = ShopifyConnection::query()->sole();
        $installation = AppInstallation::query()->sole();
        $app = App::query()->sole();
        $this->assertSame('active', $store->status);
        $this->assertSame('shpat_sensitive_access_token', $connection->access_token_encrypted);
        $this->assertNotSame('shpat_sensitive_access_token', DB::table('shopify_connections')->value('access_token_encrypted'));
        $this->assertSame($connection->id, $installation->shopify_connection_id);
        $this->assertSame($app->id, $installation->app_id);
        $this->assertSame('test-client-secret', $app->client_secret_encrypted);
        $this->assertNotSame('test-client-secret', DB::table('apps')->value('client_secret_encrypted'));
        $this->assertArrayNotHasKey('access_token_encrypted', $connection->toArray());
        $this->assertArrayNotHasKey('refresh_token_encrypted', $connection->toArray());
        $this->assertArrayNotHasKey('client_secret_encrypted', $app->toArray());
        $this->assertNotNull($authorization['state_record']->fresh()->consumed_at);

        Http::assertSent(fn ($request) => $request->url() === 'https://macfox-us.myshopify.com/admin/oauth/access_token'
            && $request['client_id'] === 'test-client-id'
            && $request['client_secret'] === 'test-client-secret'
            && $request['code'] === 'authorization-code');
    }

    public function test_consumed_state_cannot_be_used_twice(): void
    {
        Http::fake([
            'https://macfox-us.myshopify.com/admin/oauth/access_token' => Http::response([
                'access_token' => 'first-token',
                'scope' => 'read_products',
            ]),
        ]);
        [$user, $organization] = $this->superAdminContext();
        $authorization = app(ShopifyOAuthService::class)->begin(
            $organization,
            $user,
            'Macfox US',
            'macfox-us.myshopify.com',
        );
        $query = $this->signedCallbackQuery($authorization['state'], 'macfox-us.myshopify.com');
        app(ShopifyOAuthService::class)->complete($query, $authorization['state']);

        $this->expectException(ShopifyOAuthException::class);
        app(ShopifyOAuthService::class)->complete($query, $authorization['state']);
    }

    /** @return array{0: User, 1: Organization} */
    private function superAdminContext(): array
    {
        $user = User::factory()->create(['metadata' => ['is_super_admin' => true]]);
        $organization = Organization::query()->create([
            'name' => 'Macfox',
            'code' => 'macfox',
        ]);
        $organization->users()->attach($user, ['status' => 'active', 'joined_at' => now()]);

        return [$user, $organization];
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
