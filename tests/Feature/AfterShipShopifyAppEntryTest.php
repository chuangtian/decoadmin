<?php

namespace Tests\Feature;

use App\Jobs\RegisterShopifyWebhooksJob;
use App\Models\App;
use App\Models\AppInstallation;
use App\Models\Organization;
use App\Models\Role;
use App\Models\ShopifyConnection;
use App\Models\Store;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\AfterShip\AfterShipAppRegistryService;
use App\Services\Shopify\ShopifyOAuthService;
use App\Services\Shopify\Webhooks\Handlers\AppUninstalledHandler;
use App\Services\Shopify\Webhooks\ShopifyWebhookSubscriptionService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AfterShipShopifyAppEntryTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'aftership-test-client-secret';

    /** @var list<string> */
    private array $scopes = [
        'read_products',
        'read_inventory',
        'read_orders',
        'read_customers',
        'read_locations',
        'read_reports',
        'read_discounts',
        'write_discounts',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set([
            'aftership.environment' => 'test',
            'aftership.active' => [
                'client_id' => 'aftership-test-client-id',
                'client_secret' => self::SECRET,
                'name' => 'Deco-AfterShip-test',
                'handle' => 'deco-marketing',
                'app_url' => 'https://testadmin.decomkt.com',
                'launch_url' => 'https://testadmin.decomkt.com/shopify/aftership/launch',
                'redirect_uri' => 'https://testadmin.decomkt.com/shopify/aftership/oauth/callback',
            ],
            'aftership.required_scopes' => $this->scopes,
            'shopify.api_version' => '2026-07',
            'shopify.state_ttl_minutes' => 10,
        ]);
    }

    public function test_guest_is_sent_to_login_before_aftership_launch(): void
    {
        $this->get(route('aftership.shopify.app.launch', ['shop' => 'macfox-us.myshopify.com']))
            ->assertRedirect(route('login'));
    }

    public function test_launch_uses_the_exact_aftership_installation_and_store_scope(): void
    {
        [$user, $organization, $store] = $this->storeContext('organization-admin');
        $connection = $this->connection($store);
        $otherApp = App::query()->create([
            'name' => 'Other Shopify App',
            'handle' => 'other-shopify-app',
            'status' => 'active',
        ]);
        AppInstallation::query()->create([
            'app_id' => $otherApp->id,
            'store_id' => $store->id,
            'shopify_connection_id' => $connection->id,
            'status' => 'active',
            'granted_scopes' => ['read_products'],
            'installed_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->get(route('aftership.shopify.app.launch', ['shop' => $store->shopify_domain]));

        $response->assertRedirectContains("https://{$store->shopify_domain}/admin/oauth/authorize?");
        parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame('aftership-test-client-id', $query['client_id']);
        $this->assertSame(implode(',', $this->scopes), $query['scope']);
        $this->assertSame(config('aftership.active.redirect_uri'), $query['redirect_uri']);
        $this->assertNotEmpty($query['state']);
        $this->assertDatabaseHas('oauth_states', [
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'app_id' => app(AfterShipAppRegistryService::class)->configuredApp()->id,
        ]);

        $otherOrganization = Organization::query()->create(['name' => 'Other', 'code' => 'other-aftership']);
        $otherStore = $otherOrganization->stores()->create([
            'name' => 'Same Name',
            'shopify_domain' => 'other-aftership.myshopify.com',
            'status' => 'active',
        ]);
        $this->actingAs($user)
            ->get(route('aftership.shopify.app.launch', ['shop' => $otherStore->shopify_domain]))
            ->assertForbidden();
    }

    public function test_active_aftership_installation_opens_marketing_home_without_new_oauth_state(): void
    {
        [$user, , $store] = $this->storeContext('viewer');
        $connection = $this->connection($store);
        $app = app(AfterShipAppRegistryService::class)->configuredApp();
        $this->installation($app, $store, $connection, 'aftership-offline-token');

        $this->actingAs($user)
            ->get(route('aftership.shopify.app.launch', ['shop' => $store->shopify_domain]))
            ->assertRedirect(route('stores.marketing.home', $store))
            ->assertSessionHas('current_organization_id', $store->organization_id)
            ->assertSessionHas('current_store_id', $store->id);

        $this->assertDatabaseCount('oauth_states', 0);
    }

    public function test_user_without_install_permission_cannot_start_aftership_oauth(): void
    {
        [$user, , $store] = $this->storeContext('viewer');
        $this->connection($store);

        $this->actingAs($user)
            ->get(route('aftership.shopify.app.launch', ['shop' => $store->shopify_domain]))
            ->assertForbidden();

        $this->assertDatabaseCount('oauth_states', 0);
    }

    public function test_callback_stores_aftership_token_on_installation_without_overwriting_core_connection(): void
    {
        Queue::fake();
        [$user, $organization, $store] = $this->storeContext('organization-admin');
        $connection = $this->connection($store, 'commerce-hub-token');
        $app = app(AfterShipAppRegistryService::class)->configuredApp();
        $authorization = app(ShopifyOAuthService::class)->beginForApp($organization, $user, $store, $app);
        Http::fake([
            "https://{$store->shopify_domain}/admin/oauth/access_token" => Http::response([
                'access_token' => 'aftership-offline-token',
                'scope' => implode(',', $this->scopes),
            ]),
        ]);
        $query = $this->signedCallbackQuery($authorization['state'], $store->shopify_domain);

        $this->actingAs($user)
            ->withCookie(ShopifyOAuthService::STATE_COOKIE, $authorization['state'])
            ->get(route('aftership.shopify.oauth.callback', $query))
            ->assertRedirect(route('stores.marketing.home', $store))
            ->assertSessionHas('current_organization_id', $organization->id)
            ->assertSessionHas('current_store_id', $store->id);

        $installation = AppInstallation::query()->where('app_id', $app->id)->sole();
        $this->assertSame('aftership-offline-token', $installation->access_token_encrypted);
        $this->assertNotSame('aftership-offline-token', DB::table('app_installations')->value('access_token_encrypted'));
        $this->assertSame('commerce-hub-token', $connection->fresh()->access_token_encrypted);
        $this->assertSame('connected', $connection->fresh()->status);
        $this->assertSame('active', $installation->status);
        $this->assertCount(6, data_get($installation->settings, 'modules'));
        $this->assertArrayNotHasKey('access_token_encrypted', $installation->toArray());
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'user_id' => $user->id,
            'action' => 'shopify_app_installed',
            'subject_id' => $installation->id,
        ]);
        Queue::assertPushed(RegisterShopifyWebhooksJob::class, fn (RegisterShopifyWebhooksJob $job): bool => $job->appInstallationId === $installation->id);
        Http::assertSent(fn (Request $request): bool => $request->url() === "https://{$store->shopify_domain}/admin/oauth/access_token"
            && $request['client_id'] === 'aftership-test-client-id'
            && $request['client_secret'] === self::SECRET);
    }

    public function test_aftership_callback_rejects_oauth_state_for_another_app(): void
    {
        [$user, $organization, $store] = $this->storeContext('organization-admin');
        $this->connection($store);
        $otherApp = App::query()->create([
            'name' => 'Other App',
            'handle' => 'other-app',
            'client_id' => 'other-client-id',
            'client_secret_encrypted' => 'other-client-secret',
            'status' => 'active',
            'scopes' => ['read_products'],
            'redirect_uris' => [config('aftership.active.redirect_uri')],
        ]);
        $authorization = app(ShopifyOAuthService::class)->beginForApp($organization, $user, $store, $otherApp);
        $query = $this->signedCallbackQuery($authorization['state'], $store->shopify_domain, 'other-client-secret');

        $this->actingAs($user)
            ->withCookie(ShopifyOAuthService::STATE_COOKIE, $authorization['state'])
            ->get(route('aftership.shopify.oauth.callback', $query))
            ->assertForbidden();

        $this->assertNull($authorization['state_record']->fresh()->consumed_at);
    }

    public function test_incomplete_aftership_scope_grant_does_not_replace_core_connection(): void
    {
        [$user, $organization, $store] = $this->storeContext('organization-admin');
        $connection = $this->connection($store, 'commerce-hub-token');
        $app = app(AfterShipAppRegistryService::class)->configuredApp();
        $authorization = app(ShopifyOAuthService::class)->beginForApp($organization, $user, $store, $app);
        Http::fake([
            "https://{$store->shopify_domain}/admin/oauth/access_token" => Http::response([
                'access_token' => 'under-scoped-token',
                'scope' => 'read_products',
            ]),
        ]);

        $this->actingAs($user)
            ->withCookie(ShopifyOAuthService::STATE_COOKIE, $authorization['state'])
            ->get(route('aftership.shopify.oauth.callback', $this->signedCallbackQuery(
                $authorization['state'],
                $store->shopify_domain,
            )))
            ->assertForbidden();

        $this->assertSame('commerce-hub-token', $connection->fresh()->access_token_encrypted);
        $this->assertDatabaseMissing('app_installations', ['app_id' => $app->id, 'store_id' => $store->id]);
    }

    public function test_aftership_uninstall_clears_only_its_installation_token(): void
    {
        [$user, $organization, $store] = $this->storeContext('organization-admin');
        $connection = $this->connection($store, 'commerce-hub-token');
        $coreApp = App::query()->create([
            'name' => 'Commerce Hub',
            'handle' => 'shopify-commerce-hub',
            'status' => 'active',
        ]);
        $coreInstallation = $this->installation($coreApp, $store, $connection, null);
        $afterShip = app(AfterShipAppRegistryService::class)->configuredApp();
        $afterShipInstallation = $this->installation($afterShip, $store, $connection, 'aftership-offline-token');
        $event = WebhookEvent::query()->create([
            'webhook_id' => '85af3eb7-12ec-4203-b364-4b06f32c518e',
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'shopify_connection_id' => $connection->id,
            'app_id' => $afterShip->id,
            'topic' => 'app/uninstalled',
            'payload' => [],
            'status' => 'processing',
            'attempts' => 1,
            'received_at' => now(),
        ]);

        app(AppUninstalledHandler::class)->handle($event);

        $this->assertSame('uninstalled', $afterShipInstallation->fresh()->status);
        $this->assertNull($afterShipInstallation->fresh()->access_token_encrypted);
        $this->assertNotNull($afterShipInstallation->fresh()->uninstalled_at);
        $this->assertSame('active', $coreInstallation->fresh()->status);
        $this->assertSame('connected', $connection->fresh()->status);
        $this->assertSame('commerce-hub-token', $connection->fresh()->access_token_encrypted);
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'action' => 'shopify_app_uninstalled',
            'subject_id' => $afterShipInstallation->id,
        ]);
    }

    public function test_webhook_registration_uses_aftership_installation_token(): void
    {
        [, , $store] = $this->storeContext('organization-admin');
        $connection = $this->connection($store, 'commerce-hub-token');
        $app = app(AfterShipAppRegistryService::class)->configuredApp();
        $installation = $this->installation($app, $store, $connection, 'aftership-offline-token');
        Http::fake(function (Request $request) {
            $query = (string) $request['query'];

            if (str_contains($query, 'RegisteredWebhookSubscriptions')) {
                return Http::response(['data' => ['webhookSubscriptions' => ['nodes' => []]]]);
            }

            return Http::response(['data' => ['webhookSubscriptionCreate' => [
                'webhookSubscription' => ['id' => 'gid://shopify/WebhookSubscription/1'],
                'userErrors' => [],
            ]]]);
        });

        $result = app(ShopifyWebhookSubscriptionService::class)->reconcile($installation);

        $this->assertSame(11, $result['created']);
        $this->assertSame('https://testadmin.decomkt.com/shopify/webhooks/deco-marketing', $result['endpoint']);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-Shopify-Access-Token', 'aftership-offline-token'));
        Http::assertNotSent(fn (Request $request): bool => $request->hasHeader('X-Shopify-Access-Token', 'commerce-hub-token'));
    }

    /** @return array{0: User, 1: Organization, 2: Store} */
    private function storeContext(string $roleSlug): array
    {
        $this->seed(PermissionSeeder::class);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $organization = Organization::query()->create(['name' => 'Macfox', 'code' => 'macfox-aftership-entry']);
        $organization->users()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $this->seed(RoleSeeder::class);
        $role = Role::query()->whereBelongsTo($organization)->where('slug', $roleSlug)->firstOrFail();
        $user->roles()->attach($role, ['organization_id' => $organization->id, 'store_id' => null]);
        $store = $organization->stores()->create([
            'name' => 'Macfox US',
            'shopify_domain' => 'macfox-us.myshopify.com',
            'status' => 'active',
        ]);
        $store->members()->attach($user, ['status' => 'active', 'joined_at' => now()]);

        return [$user, $organization, $store];
    }

    private function connection(Store $store, string $token = 'commerce-hub-token'): ShopifyConnection
    {
        return ShopifyConnection::query()->create([
            'store_id' => $store->id,
            'shop_domain' => $store->shopify_domain,
            'access_token_encrypted' => $token,
            'token_type' => 'offline',
            'scopes' => $this->scopes,
            'api_version' => '2026-07',
            'status' => 'connected',
            'installed_at' => now(),
        ]);
    }

    private function installation(App $app, Store $store, ShopifyConnection $connection, ?string $token): AppInstallation
    {
        return AppInstallation::query()->create([
            'app_id' => $app->id,
            'store_id' => $store->id,
            'shopify_connection_id' => $connection->id,
            'status' => 'active',
            'granted_scopes' => $this->scopes,
            'access_token_encrypted' => $token,
            'token_type' => $token ? 'offline' : null,
            'settings' => ['modules' => array_fill_keys(array_keys(config('shopify.marketing_modules')), true)],
            'installed_at' => now(),
        ]);
    }

    /** @return array<string, string|int> */
    private function signedCallbackQuery(string $state, string $shop, string $secret = self::SECRET): array
    {
        $query = [
            'code' => 'authorization-code',
            'shop' => $shop,
            'state' => $state,
            'timestamp' => now()->timestamp,
        ];
        ksort($query, SORT_STRING);
        $message = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $query['hmac'] = hash_hmac('sha256', $message, $secret);

        return $query;
    }
}
