<?php

namespace Tests\Feature;

use App\Models\App;
use App\Models\AppInstallation;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\PersonalizationEventSource;
use App\Models\Role;
use App\Models\ShopifyConnection;
use App\Models\Store;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\AppCenter\AppConfigurationCatalog;
use App\Services\Personalization\PersonalizationDiscountService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PersonalizationShopifyAppEntryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'personalization.environment' => 'test',
            'personalization.active.client_id' => 'personalization-test-client-id',
            'personalization.active.client_secret' => 'personalization-test-secret',
            'personalization.active.name' => 'Deco 个性化推荐测试',
            'personalization.active.handle' => 'deco-personalization-test',
            'personalization.active.app_url' => 'https://testadmin.decomkt.com',
            'personalization.active_proxy_path' => '/apps/deco-personalization-test',
            'personalization.required_scopes' => ['write_app_proxy', 'write_pixels', 'read_customer_events'],
            'personalization.denied_shop_domains' => ['macfoxebike.myshopify.com'],
            'shopify.api_version' => '2026-07',
        ]);
    }

    public function test_management_entry_requires_login_store_membership_and_permission(): void
    {
        [$operator, $organization, $store] = $this->context('operator');
        $url = route('personalization.shopify-app.management', ['shop' => $store->shopify_domain]);

        $this->get($url)->assertRedirect(route('login'));
        $this->actingAs($operator)->get($url)->assertRedirect(route('personalization.index', [$organization, $store]));

        $otherStore = $organization->stores()->create([
            'name' => 'Unauthorized Store',
            'shopify_domain' => 'unauthorized-personalization.myshopify.com',
            'status' => 'active',
        ]);
        $this->actingAs($operator)
            ->get(route('personalization.shopify-app.management', ['shop' => $otherStore->shopify_domain]))
            ->assertForbidden();

        $viewer = User::factory()->create(['email_verified_at' => now()]);
        $organization->users()->attach($viewer, ['status' => 'active', 'joined_at' => now()]);
        $store->members()->attach($viewer, ['status' => 'active', 'joined_at' => now()]);
        $viewerRole = Role::query()->whereBelongsTo($organization)->where('slug', 'viewer')->firstOrFail();
        $viewer->roles()->attach($viewerRole, ['organization_id' => $organization->id, 'store_id' => null]);

        $this->actingAs($viewer)->get($url)->assertForbidden();
        $this->actingAs($operator)
            ->get(route('personalization.shopify-app.management', ['shop' => 'missing-store.myshopify.com']))
            ->assertNotFound();
    }

    public function test_connection_validates_id_token_and_bootstrap_preserves_commerce_hub_connection(): void
    {
        [, , $store] = $this->context('store-admin');
        $connection = $this->connection($store);
        $token = $this->shopifyIdToken(
            $store->shopify_domain,
            'personalization-test-client-id',
            'personalization-test-secret',
        );

        $this->withToken($token)
            ->getJson(route('personalization.shopify-app.connection', ['shop' => $store->shopify_domain]))
            ->assertOk()
            ->assertJsonPath('data.connected', true)
            ->assertJsonPath('data.environment', 'test')
            ->assertJsonPath('data.store.id', $store->id);

        Http::fakeSequence("https://{$store->shopify_domain}/*")
            ->push([
                'access_token' => 'personalization-offline-token',
                'refresh_token' => 'personalization-refresh-token',
                'expires_in' => 3600,
                'refresh_token_expires_in' => 7776000,
                'scope' => 'write_app_proxy,write_pixels,read_customer_events',
            ])
            ->push(['data' => [
                'currentAppInstallation' => [
                    'id' => 'gid://shopify/AppInstallation/123',
                    'accessScopes' => [
                        ['handle' => 'write_app_proxy'],
                        ['handle' => 'write_pixels'],
                        ['handle' => 'read_customer_events'],
                    ],
                ],
                'shop' => ['id' => 'gid://shopify/Shop/321'],
            ]])
            ->push(['data' => ['metafieldsSet' => [
                'metafields' => [
                    [
                        'id' => 'gid://shopify/Metafield/456',
                        'namespace' => 'deco_personalization',
                        'key' => 'proxy_path',
                        'value' => '/apps/deco-personalization-test',
                    ],
                    [
                        'id' => 'gid://shopify/Metafield/457',
                        'namespace' => 'app--123--deco_personalization',
                        'key' => 'checkout_configuration_url',
                        'value' => 'https://testadmin.decomkt.com/api/shopify-app/personalization/checkout/configuration',
                    ],
                ],
                'userErrors' => [],
            ]]])
            ->push(['errors' => [[
                'message' => 'Web pixel was not found.',
                'extensions' => ['code' => 'RESOURCE_NOT_FOUND'],
            ]]])
            ->push(['data' => ['webPixelCreate' => [
                'webPixel' => [
                    'id' => 'gid://shopify/WebPixel/789',
                    'settings' => ['endpoint' => 'configured-by-request'],
                ],
                'userErrors' => [],
            ]]]);

        $this->withToken($token)
            ->postJson(route('personalization.shopify-app.bootstrap', ['shop' => $store->shopify_domain]))
            ->assertOk()
            ->assertJsonPath('data.app_installation_id', 'gid://shopify/AppInstallation/123')
            ->assertJsonPath('data.granted_scopes.0', 'write_app_proxy')
            ->assertJsonPath('data.proxy_path', '/apps/deco-personalization-test')
            ->assertJsonPath('data.checkout_configuration_url', 'https://testadmin.decomkt.com/api/shopify-app/personalization/checkout/configuration')
            ->assertJsonPath('data.web_pixel_id', 'gid://shopify/WebPixel/789');

        Http::assertSent(fn ($request): bool => $request->url() === "https://{$store->shopify_domain}/admin/oauth/access_token"
            && $request['subject_token'] === $token
            && $request['requested_token_type'] === 'urn:shopify:params:oauth:token-type:offline-access-token'
            && (int) $request['expiring'] === 1);
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/admin/api/2026-07/graphql.json')
            && str_contains($request->body(), '"variables":{}')
            && str_contains((string) $request['query'], 'currentAppInstallation'));
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/admin/api/2026-07/graphql.json')
            && data_get($request->data(), 'variables.metafields.0.ownerId') === 'gid://shopify/AppInstallation/123'
            && data_get($request->data(), 'variables.metafields.0.namespace') === 'deco_personalization'
            && data_get($request->data(), 'variables.metafields.0.key') === 'proxy_path'
            && data_get($request->data(), 'variables.metafields.0.value') === '/apps/deco-personalization-test'
            && data_get($request->data(), 'variables.metafields.1.ownerId') === 'gid://shopify/Shop/321'
            && data_get($request->data(), 'variables.metafields.1.namespace') === '$app:deco_personalization'
            && data_get($request->data(), 'variables.metafields.1.key') === 'checkout_configuration_url'
            && data_get($request->data(), 'variables.metafields.1.value') === 'https://testadmin.decomkt.com/api/shopify-app/personalization/checkout/configuration');
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/admin/api/2026-07/graphql.json')
            && str_contains((string) $request['query'], 'CurrentPersonalizationWebPixel'));
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/admin/api/2026-07/graphql.json')
            && str_contains((string) $request['query'], 'CreatePersonalizationWebPixel')
            && str_starts_with(
                (string) data_get($request->data(), 'variables.webPixel.settings.endpoint'),
                'https://testadmin.decomkt.com/api/shopify-app/personalization/events/',
            ));

        $app = App::query()->sole();
        $installation = AppInstallation::query()->whereBelongsTo($app)->whereBelongsTo($store)->sole();
        $eventSource = PersonalizationEventSource::query()->whereBelongsTo($store)->sole();
        $this->assertSame('deco-personalization-test', $app->handle);
        $this->assertSame('personalization_config', data_get($app->settings, 'managed_by'));
        $this->assertSame($connection->id, $installation->shopify_connection_id);
        $this->assertSame('gid://shopify/AppInstallation/123', $installation->external_installation_id);
        $this->assertSame('personalization_bootstrap', data_get($installation->settings, 'source'));
        $this->assertSame('personalization-offline-token', $installation->access_token_encrypted);
        $this->assertSame('personalization-refresh-token', $installation->refresh_token_encrypted);
        $this->assertSame('active', $eventSource->status);
        $this->assertSame('gid://shopify/WebPixel/789', $eventSource->web_pixel_id);
        $this->assertNotNull($eventSource->activated_at);
        $this->assertStringNotContainsString(
            'personalization-offline-token',
            (string) DB::table('app_installations')->whereKey($installation->id)->value('access_token_encrypted'),
        );
        $this->assertSame('connected', $connection->fresh()->status);
        $this->assertSame('commerce-hub-token', $connection->fresh()->access_token_encrypted);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'personalization_shopify_app_bootstrapped',
            'store_id' => $store->id,
        ]);
    }

    public function test_connection_rejects_wrong_audience_or_shop(): void
    {
        [, , $store] = $this->context('store-admin');
        $wrongAudience = $this->shopifyIdToken(
            $store->shopify_domain,
            'wrong-client-id',
            'personalization-test-secret',
        );

        $this->withToken($wrongAudience)
            ->getJson(route('personalization.shopify-app.connection', ['shop' => $store->shopify_domain]))
            ->assertUnauthorized()
            ->assertHeader('X-Shopify-Retry-Invalid-Session-Request', '1')
            ->assertJsonPath('error.code', 'INVALID_SHOPIFY_ID_TOKEN');

        $valid = $this->shopifyIdToken(
            $store->shopify_domain,
            'personalization-test-client-id',
            'personalization-test-secret',
        );
        $this->withToken($valid)
            ->getJson(route('personalization.shopify-app.connection', ['shop' => 'other-shop.myshopify.com']))
            ->assertUnauthorized();
    }

    public function test_bootstrap_updates_existing_web_pixel_without_rotating_store_event_key(): void
    {
        [, $organization, $store] = $this->context('store-admin');
        $this->connection($store);
        $source = PersonalizationEventSource::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'web_pixel_id' => 'gid://shopify/WebPixel/789',
            'status' => 'active',
            'activated_at' => now()->subDay(),
        ]);
        $originalKey = $source->ingest_key;
        $token = $this->shopifyIdToken(
            $store->shopify_domain,
            'personalization-test-client-id',
            'personalization-test-secret',
        );
        Http::fakeSequence("https://{$store->shopify_domain}/*")
            ->push([
                'access_token' => 'rotated-offline-token',
                'refresh_token' => 'rotated-refresh-token',
                'expires_in' => 3600,
                'refresh_token_expires_in' => 7776000,
                'scope' => 'write_app_proxy,write_pixels,read_customer_events',
            ])
            ->push(['data' => [
                'currentAppInstallation' => [
                    'id' => 'gid://shopify/AppInstallation/456',
                    'accessScopes' => [
                        ['handle' => 'write_app_proxy'],
                        ['handle' => 'write_pixels'],
                        ['handle' => 'read_customer_events'],
                    ],
                ],
                'shop' => ['id' => 'gid://shopify/Shop/654'],
            ]])
            ->push(['data' => ['metafieldsSet' => [
                'metafields' => [
                    [
                        'id' => 'gid://shopify/Metafield/654',
                        'namespace' => 'deco_personalization',
                        'key' => 'proxy_path',
                        'value' => '/apps/deco-personalization-test',
                    ],
                    [
                        'id' => 'gid://shopify/Metafield/655',
                        'namespace' => 'app--123--deco_personalization',
                        'key' => 'checkout_configuration_url',
                        'value' => 'https://testadmin.decomkt.com/api/shopify-app/personalization/checkout/configuration',
                    ],
                ],
                'userErrors' => [],
            ]]])
            ->push(['data' => ['webPixel' => [
                'id' => 'gid://shopify/WebPixel/789',
                'settings' => ['endpoint' => 'old-endpoint'],
            ]]])
            ->push(['data' => ['webPixelUpdate' => [
                'webPixel' => [
                    'id' => 'gid://shopify/WebPixel/789',
                    'settings' => ['endpoint' => 'configured-by-request'],
                ],
                'userErrors' => [],
            ]]]);

        $this->withToken($token)
            ->postJson(route('personalization.shopify-app.bootstrap', ['shop' => $store->shopify_domain]))
            ->assertOk()
            ->assertJsonPath('data.web_pixel_id', 'gid://shopify/WebPixel/789');

        $source->refresh();
        $this->assertSame($originalKey, $source->ingest_key);
        $this->assertSame('active', $source->status);
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/admin/api/2026-07/graphql.json')
            && str_contains((string) data_get($request->data(), 'query'), 'UpdatePersonalizationWebPixel')
            && data_get($request->data(), 'variables.id') === 'gid://shopify/WebPixel/789'
            && data_get($request->data(), 'variables.webPixel.settings.endpoint')
                === "https://testadmin.decomkt.com/api/shopify-app/personalization/events/{$originalKey}");
    }

    public function test_bootstrap_rejects_unexpected_web_pixel_query_errors(): void
    {
        [, , $store] = $this->context('store-admin');
        $this->connection($store);
        $token = $this->shopifyIdToken(
            $store->shopify_domain,
            'personalization-test-client-id',
            'personalization-test-secret',
        );
        Http::fakeSequence("https://{$store->shopify_domain}/*")
            ->push([
                'access_token' => 'personalization-offline-token',
                'refresh_token' => 'personalization-refresh-token',
                'expires_in' => 3600,
                'refresh_token_expires_in' => 7776000,
                'scope' => 'write_app_proxy,write_pixels,read_customer_events',
            ])
            ->push(['data' => [
                'currentAppInstallation' => [
                    'id' => 'gid://shopify/AppInstallation/123',
                    'accessScopes' => [
                        ['handle' => 'write_app_proxy'],
                        ['handle' => 'write_pixels'],
                        ['handle' => 'read_customer_events'],
                    ],
                ],
                'shop' => ['id' => 'gid://shopify/Shop/321'],
            ]])
            ->push(['data' => ['metafieldsSet' => [
                'metafields' => [
                    [
                        'id' => 'gid://shopify/Metafield/456',
                        'namespace' => 'deco_personalization',
                        'key' => 'proxy_path',
                        'value' => '/apps/deco-personalization-test',
                    ],
                    [
                        'id' => 'gid://shopify/Metafield/457',
                        'namespace' => 'app--123--deco_personalization',
                        'key' => 'checkout_configuration_url',
                        'value' => 'https://testadmin.decomkt.com/api/shopify-app/personalization/checkout/configuration',
                    ],
                ],
                'userErrors' => [],
            ]]])
            ->push(['errors' => [[
                'message' => 'Access denied.',
                'extensions' => ['code' => 'ACCESS_DENIED'],
            ]]]);

        $this->withToken($token)
            ->postJson(route('personalization.shopify-app.bootstrap', ['shop' => $store->shopify_domain]))
            ->assertStatus(502)
            ->assertJsonPath('error.code', 'SHOPIFY_ADMIN_API_FAILED');

        $this->assertDatabaseCount('app_installations', 0);
        $this->assertDatabaseHas('personalization_event_sources', [
            'store_id' => $store->id,
            'status' => 'inactive',
        ]);
    }

    public function test_denylisted_shop_is_blocked_before_connection_bootstrap_or_webhook_writes(): void
    {
        $deniedShop = 'macfoxebike.myshopify.com';
        $token = $this->shopifyIdToken(
            $deniedShop,
            'personalization-test-client-id',
            'personalization-test-secret',
        );

        $this->withToken($token)
            ->getJson(route('personalization.shopify-app.connection', ['shop' => $deniedShop]))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'SHOP_WRITE_DENIED');
        $this->withToken($token)
            ->postJson(route('personalization.shopify-app.bootstrap', ['shop' => $deniedShop]))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'SHOP_WRITE_DENIED');
        $this->webhook('app/uninstalled', $deniedShop, ['id' => 1], (string) Str::uuid())
            ->assertForbidden()
            ->assertJsonPath('error.code', 'SHOP_WRITE_DENIED');

        Http::assertNothingSent();
        $this->assertDatabaseCount('apps', 0);
        $this->assertDatabaseCount('app_installations', 0);
        $this->assertDatabaseCount('webhook_events', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_bootstrap_rejects_missing_web_pixel_scope_before_admin_graphql_writes(): void
    {
        [, , $store] = $this->context('store-admin');
        $this->connection($store);
        $token = $this->shopifyIdToken(
            $store->shopify_domain,
            'personalization-test-client-id',
            'personalization-test-secret',
        );
        Http::fakeSequence("https://{$store->shopify_domain}/*")->push([
            'access_token' => 'incomplete-offline-token',
            'refresh_token' => 'incomplete-refresh-token',
            'expires_in' => 3600,
            'refresh_token_expires_in' => 7776000,
            'scope' => 'write_app_proxy,write_pixels',
        ]);

        $this->withToken($token)
            ->postJson(route('personalization.shopify-app.bootstrap', ['shop' => $store->shopify_domain]))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'SHOPIFY_REQUIRED_SCOPES_MISSING');

        Http::assertSentCount(1);
        $this->assertDatabaseCount('personalization_event_sources', 0);
        $this->assertDatabaseCount('app_installations', 0);
    }

    public function test_lifecycle_webhooks_are_idempotent_and_only_change_personalization_installation(): void
    {
        [, $organization, $store] = $this->context('store-admin');
        $connection = $this->connection($store);
        $this->activeInstallation($store, $connection);
        $eventSource = PersonalizationEventSource::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'web_pixel_id' => 'gid://shopify/WebPixel/321',
            'status' => 'active',
            'activated_at' => now(),
        ]);
        $webhookId = (string) Str::uuid();
        $payload = ['id' => 99, 'shop' => 'body-shop-is-ignored.myshopify.com'];

        $this->webhook('app/uninstalled', $store->shopify_domain, $payload, $webhookId)
            ->assertAccepted()
            ->assertJsonPath('duplicate', false);

        $installation = AppInstallation::query()->sole();
        $event = WebhookEvent::query()->sole();
        $this->assertSame('uninstalled', $installation->status);
        $this->assertNull($installation->access_token_encrypted);
        $this->assertNull($installation->refresh_token_encrypted);
        $this->assertSame($organization->id, $event->organization_id);
        $this->assertSame('processed', $event->status);
        $this->assertSame('connected', $connection->fresh()->status);
        $this->assertSame('commerce-hub-token', $connection->fresh()->access_token_encrypted);
        $this->assertSame('inactive', $eventSource->fresh()->status);
        $this->assertNull($eventSource->fresh()->activated_at);
        $this->assertTrue($eventSource->fresh()->purge_after->between(
            now()->addHours(47),
            now()->addHours(49),
        ));

        $this->webhook('app/uninstalled', $store->shopify_domain, $payload, $webhookId)
            ->assertOk()
            ->assertJsonPath('duplicate', true);
        $this->assertDatabaseCount('webhook_events', 1);
        $this->assertSame(1, AuditLog::query()->where('action', 'personalization_shopify_app_uninstalled')->count());

        $this->webhook(
            'app/scopes_update',
            $store->shopify_domain,
            ['current' => ['write_products', 'read_products', 'write_products']],
            (string) Str::uuid(),
        )->assertAccepted();
        $installation->refresh();
        $this->assertSame('active', $installation->status);
        $this->assertSame(['read_products', 'write_products'], $installation->granted_scopes);
        $this->assertNull($installation->access_token_encrypted);
        $this->assertSame('connected', $connection->fresh()->status);

        $this->webhook('app/uninstalled', $store->shopify_domain, ['id' => 100], $webhookId)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'WEBHOOK_ID_CONFLICT');
    }

    public function test_privacy_webhooks_never_retain_customer_payload_and_shop_redact_schedules_immediate_purge(): void
    {
        [, $organization, $store] = $this->context('store-admin');
        $connection = $this->connection($store);
        $this->activeInstallation($store, $connection);
        $source = PersonalizationEventSource::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'web_pixel_id' => 'gid://shopify/WebPixel/654',
            'status' => 'active',
            'activated_at' => now(),
        ]);

        $this->webhook('customers/data_request', $store->shopify_domain, [
            'customer' => ['id' => 999, 'email' => 'private@example.com'],
        ], (string) Str::uuid(), 'wrong-secret')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'INVALID_WEBHOOK_HMAC');
        $this->assertDatabaseCount('webhook_events', 0);

        $this->webhook('customers/data_request', $store->shopify_domain, [
            'shop_id' => 1,
            'shop_domain' => $store->shopify_domain,
            'customer' => ['id' => 999, 'email' => 'private@example.com'],
            'orders_requested' => [123],
        ], (string) Str::uuid())->assertAccepted();
        $requestEvent = WebhookEvent::query()->where('topic', 'customers/data_request')->sole();
        $this->assertNull($requestEvent->payload_encrypted);
        $this->assertSame(['storage' => 'redacted'], $requestEvent->payload);
        $this->assertTrue($requestEvent->payloadIntegrityIsValid());
        $this->assertSame('no_customer_data', $requestEvent->processing_result);
        $this->assertStringNotContainsString(
            'private@example.com',
            (string) DB::table('webhook_events')->whereKey($requestEvent->id)->value('payload'),
        );
        $this->assertSame('active', $source->fresh()->status);

        $this->webhook('customers/redact', $store->shopify_domain, [
            'shop_id' => 1,
            'shop_domain' => $store->shopify_domain,
            'customer' => ['id' => 999, 'email' => 'private@example.com'],
            'orders_to_redact' => [123],
        ], (string) Str::uuid())->assertAccepted();
        $this->assertSame('active', $source->fresh()->status);

        $this->webhook('shop/redact', $store->shopify_domain, [
            'shop_id' => 1,
            'shop_domain' => $store->shopify_domain,
        ], (string) Str::uuid())->assertAccepted();
        $source->refresh();
        $this->assertSame('inactive', $source->status);
        $this->assertTrue($source->purge_after->lessThanOrEqualTo(now()));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'personalization_shop_redact_received',
            'store_id' => $store->id,
        ]);
    }

    public function test_app_center_uses_personalization_provider_and_rbac(): void
    {
        [$operator, , $store] = $this->context('operator');
        $connection = $this->connection($store);
        $installation = $this->activeInstallation($store, $connection);
        $installation->load(['app', 'store.organization', 'shopifyConnection']);

        $presented = app(AppConfigurationCatalog::class)->forInstallation($installation, $operator);

        $this->assertSame('个性化与推荐', $presented['category']);
        $this->assertSame('待配置', $presented['configuration_status_label']);
        $this->assertStringContainsString('/shopify-app/personalization', $presented['management_url']);
        $this->assertSame('管理个性化推荐', $presented['action_label']);
    }

    public function test_personalization_discount_listing_and_creation_use_only_the_app_owned_store_token(): void
    {
        config(['personalization.required_scopes' => [
            'write_app_proxy', 'write_pixels', 'read_customer_events', 'read_discounts', 'write_discounts',
        ]]);
        [$actor, , $store] = $this->context('store-admin');
        $this->activeInstallation($store, $this->connection($store));
        Http::fakeSequence("https://{$store->shopify_domain}/*")
            ->push(['data' => ['discountNodes' => ['nodes' => [[
                'id' => 'gid://shopify/DiscountCodeNode/1',
                'discount' => [
                    '__typename' => 'DiscountCodeBasic',
                    'title' => 'Existing 10%',
                    'summary' => '10% off products',
                    'status' => 'ACTIVE',
                    'codes' => ['nodes' => [['code' => 'EXISTING10']]],
                    'customerGets' => ['value' => ['percentage' => 0.1]],
                ],
            ]]]]])
            ->push(['data' => ['discountCodeBasicCreate' => [
                'codeDiscountNode' => ['id' => 'gid://shopify/DiscountCodeNode/2'],
                'userErrors' => [],
            ]]]);

        $service = app(PersonalizationDiscountService::class);
        $listed = $service->listing($store, $actor)[0];
        $this->assertSame('Existing 10%', $listed['title']);
        $this->assertSame(10.0, $listed['percentage']);
        $created = $service->create($store, $actor, [
            'title' => '九折优惠',
            'code' => 'DECO10',
            'percentage' => 10,
            'product_ids' => ['123'],
        ]);

        $this->assertSame('gid://shopify/DiscountCodeNode/2', $created['id']);
        $this->assertSame(10, $created['percentage']);
        Http::assertSent(fn ($request): bool => $request->hasHeader('X-Shopify-Access-Token', 'personalization-app-token')
            && $request->url() === "https://{$store->shopify_domain}/admin/api/2026-07/graphql.json");
        $this->assertDatabaseHas('audit_logs', [
            'store_id' => $store->id,
            'action' => 'personalization_discount_created',
        ]);
    }

    /** @return array{User, Organization, Store} */
    private function context(string $roleSlug): array
    {
        $this->seed(PermissionSeeder::class);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $organization = Organization::query()->create([
            'name' => 'Personalization App',
            'code' => 'personalization-app',
            'status' => 'active',
        ]);
        $organization->users()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $store = $organization->stores()->create([
            'name' => 'Personalization Store',
            'shopify_domain' => 'personalization-store.myshopify.com',
            'status' => 'active',
            'currency' => 'USD',
        ]);
        $store->members()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $this->seed(RoleSeeder::class);
        $role = Role::query()->whereBelongsTo($organization)->where('slug', $roleSlug)->firstOrFail();
        $user->roles()->attach($role, [
            'organization_id' => $organization->id,
            'store_id' => in_array($roleSlug, ['store-admin', 'operator'], true) ? $store->id : null,
        ]);

        return [$user, $organization, $store];
    }

    private function connection(Store $store): ShopifyConnection
    {
        return ShopifyConnection::query()->create([
            'store_id' => $store->id,
            'shop_domain' => $store->shopify_domain,
            'access_token_encrypted' => 'commerce-hub-token',
            'scopes' => ['read_products'],
            'api_version' => '2026-07',
            'status' => 'connected',
            'installed_at' => now(),
        ]);
    }

    private function activeInstallation(Store $store, ShopifyConnection $connection): AppInstallation
    {
        $app = App::query()->create([
            'organization_id' => null,
            'name' => (string) config('personalization.active.name'),
            'handle' => (string) config('personalization.active.handle'),
            'client_id' => (string) config('personalization.active.client_id'),
            'client_secret_encrypted' => (string) config('personalization.active.client_secret'),
            'distribution' => 'custom',
            'status' => 'active',
            'scopes' => (array) config('personalization.required_scopes'),
            'webhook_api_version' => (string) config('shopify.api_version'),
            'settings' => ['managed_by' => 'personalization_config', 'environment' => 'test'],
        ]);

        return AppInstallation::query()->create([
            'app_id' => $app->id,
            'store_id' => $store->id,
            'shopify_connection_id' => $connection->id,
            'status' => 'active',
            'granted_scopes' => (array) config('personalization.required_scopes'),
            'access_token_encrypted' => 'personalization-app-token',
            'refresh_token_encrypted' => 'personalization-app-refresh-token',
            'token_type' => 'offline',
            'access_token_expires_at' => now()->addHour(),
            'refresh_token_expires_at' => now()->addDays(90),
            'installed_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function webhook(
        string $topic,
        string $shop,
        array $payload,
        string $webhookId,
        string $secret = 'personalization-test-secret',
    ): TestResponse {
        $rawPayload = json_encode($payload, JSON_THROW_ON_ERROR);

        return $this->call('POST', route('personalization.shopify-app.webhooks'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SHOPIFY_HMAC_SHA256' => base64_encode(hash_hmac('sha256', $rawPayload, $secret, true)),
            'HTTP_X_SHOPIFY_WEBHOOK_ID' => $webhookId,
            'HTTP_X_SHOPIFY_TOPIC' => $topic,
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => $shop,
            'HTTP_X_SHOPIFY_API_VERSION' => '2026-07',
            'HTTP_X_SHOPIFY_TRIGGERED_AT' => '2026-08-28T10:00:00Z',
        ], $rawPayload);
    }

    private function shopifyIdToken(string $shop, string $audience, string $secret): string
    {
        $encode = fn (array $value): string => rtrim(strtr(base64_encode(json_encode($value, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $header = $encode(['alg' => 'HS256', 'typ' => 'JWT']);
        $payload = $encode([
            'iss' => "https://{$shop}/admin",
            'dest' => "https://{$shop}",
            'aud' => $audience,
            'sub' => '42',
            'exp' => now()->addMinute()->timestamp,
            'nbf' => now()->subSecond()->timestamp,
            'iat' => now()->timestamp,
            'jti' => (string) Str::uuid(),
        ]);
        $signature = rtrim(strtr(base64_encode(hash_hmac('sha256', $header.'.'.$payload, $secret, true)), '+/', '-_'), '=');

        return $header.'.'.$payload.'.'.$signature;
    }
}
