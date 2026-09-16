<?php

namespace Tests\Feature;

use App\Exceptions\ShopifyApiException;
use App\Jobs\ProcessWebhookEventJob;
use App\Jobs\RegisterShopifyWebhooksJob;
use App\Models\App;
use App\Models\AppInstallation;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\ShopifyConnection;
use App\Models\Store;
use App\Services\Shopify\Webhooks\ShopifyWebhookSubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ShopifyWebhookSubscriptionIsolationTest extends TestCase
{
    use RefreshDatabase;

    private const TOPICS = ['APP_UNINSTALLED', 'PRODUCTS_CREATE', 'PRODUCTS_UPDATE', 'PRODUCTS_DELETE',
        'ORDERS_CREATE', 'ORDERS_UPDATED', 'ORDERS_CANCELLED', 'CUSTOMERS_CREATE', 'CUSTOMERS_UPDATE',
        'CUSTOMERS_DELETE', 'INVENTORY_LEVELS_UPDATE'];

    public function test_command_repairs_only_main_app_subscriptions_and_is_idempotent(): void
    {
        [$store, $main, $connection, $installation] = $this->context();
        $marketing = $this->independentInstallation($store, $connection, 'marketing');
        $student = $this->independentInstallation($store, $connection, 'student');
        $before = [$marketing->fresh()->toArray(), $student->fresh()->toArray()];
        $registered = [];
        foreach (self::TOPICS as $index => $topic) {
            if ($topic !== 'ORDERS_UPDATED') {
                $registered[$topic] = ['id' => 'gid://shopify/WebhookSubscription/'.($index + 1),
                    'topic' => $topic, 'uri' => 'https://testadmin.example.com/shopify/webhooks/marketing'];
            }
        }
        $registered['SHOP_UPDATE'] = ['id' => 'gid://shopify/WebhookSubscription/99', 'topic' => 'SHOP_UPDATE', 'uri' => 'https://testadmin.example.com/independent-handler'];
        $counts = ['reads' => 0, 'creates' => 0, 'updates' => 0];
        Http::preventStrayRequests();
        Http::fake(function ($request) use (&$registered, &$counts) {
            $this->assertSame(['main-token'], $request->header('X-Shopify-Access-Token'));
            $this->assertSame('https://owned.myshopify.com/admin/api/2026-07/graphql.json', $request->url());
            $query = $request['query'];
            if (str_contains($query, 'RegisteredWebhookSubscriptions')) {
                $counts['reads']++;

                return Http::response($this->response(array_values($registered)));
            }
            $uri = data_get($request->data(), 'variables.webhookSubscription.uri');
            $this->assertSame('https://testadmin.example.com/shopify/webhooks/shopify-commerce-hub', $uri);
            if (str_contains($query, 'RegisterWebhookSubscription')) {
                $counts['creates']++;
                $topic = $request['variables']['topic'];
                $this->assertSame('ORDERS_UPDATED', $topic);
                $registered[$topic] = ['id' => 'gid://shopify/WebhookSubscription/42', 'topic' => $topic, 'uri' => $uri];

                return Http::response(['data' => ['webhookSubscriptionCreate' => ['webhookSubscription' => $registered[$topic], 'userErrors' => []]]]);
            }
            $this->assertStringContainsString('UpdateWebhookSubscription', $query);
            $counts['updates']++;
            $topic = collect($registered)->firstWhere('id', $request['variables']['id'])['topic'];
            $this->assertContains($topic, self::TOPICS);
            $registered[$topic]['uri'] = $uri;

            return Http::response(['data' => ['webhookSubscriptionUpdate' => ['webhookSubscription' => $registered[$topic], 'userErrors' => []]]]);
        });

        $this->artisan('shopify:register-webhooks', ['--store' => $store->id])->assertSuccessful();
        $this->assertSame(['reads' => 1, 'creates' => 1, 'updates' => 10], $counts);
        $this->artisan('shopify:register-webhooks', ['--store' => $store->id])->assertSuccessful();
        $this->assertSame(['reads' => 2, 'creates' => 1, 'updates' => 10], $counts);
        $this->assertSame($before, [$marketing->fresh()->toArray(), $student->fresh()->toArray()]);
        $this->assertSame('https://testadmin.example.com/independent-handler', $registered['SHOP_UPDATE']['uri']);
        $this->assertSame('main-token', $connection->fresh()->access_token_encrypted);
        $this->assertNull($installation->fresh()->access_token_encrypted);
        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertDatabaseHas('audit_logs', ['organization_id' => $store->organization_id, 'store_id' => $store->id,
            'action' => 'shopify.webhook_subscriptions.reconciled', 'subject_id' => $installation->id]);
        $this->assertStringNotContainsString('main-token', AuditLog::query()->sole()->toJson());
    }

    public function test_independent_app_jobs_do_not_use_shared_connection(): void
    {
        [$store, , $connection] = $this->context();
        Http::fake();
        foreach (['marketing', 'student'] as $handle) {
            $installation = $this->independentInstallation($store, $connection, $handle);
            (new RegisterShopifyWebhooksJob($installation->id))->handle(app(ShopifyWebhookSubscriptionService::class));
        }
        Http::assertNothingSent();
    }

    public function test_direct_independent_app_registration_fails_closed(): void
    {
        [$store, , $connection] = $this->context();
        $installation = $this->independentInstallation($store, $connection, 'marketing');
        Http::fake();
        try {
            app(ShopifyWebhookSubscriptionService::class)->reconcile($installation);
            $this->fail('An independent app must never overwrite main app callbacks.');
        } catch (ShopifyApiException) {
            Http::assertNothingSent();
        }
    }

    #[DataProvider('invalidContexts')]
    public function test_invalid_context_is_rejected_before_any_api_call(string $case): void
    {
        [$store, $main, $connection, $installation] = $this->context();
        $otherOrganization = Organization::query()->create(['name' => 'Other', 'code' => 'other']);
        $otherStore = $otherOrganization->stores()->create(['name' => 'Other', 'shopify_domain' => 'other.myshopify.com', 'status' => 'active']);
        match ($case) {
            'connection_store' => $installation->setRelation('shopifyConnection', $connection->forceFill(['store_id' => $otherStore->id])),
            'connection_domain' => $connection->update(['shop_domain' => $otherStore->shopify_domain]),
            'app_organization' => $main->update(['organization_id' => $otherOrganization->id]),
            'inactive_installation' => $installation->update(['status' => 'uninstalled']),
            'inactive_store' => $store->update(['status' => 'inactive']),
            'inactive_app' => $main->update(['status' => 'inactive']),
            'disconnected' => $connection->update(['status' => 'disconnected']),
            'missing_secret' => $main->update(['client_secret_encrypted' => null]),
            'missing_token' => $connection->update(['access_token_encrypted' => '']),
        };
        Http::fake();
        try {
            app(ShopifyWebhookSubscriptionService::class)->reconcile($installation);
            $this->fail('Invalid registration context must be rejected.');
        } catch (ShopifyApiException) {
            Http::assertNothingSent();
        }
    }

    public static function invalidContexts(): array
    {
        return array_map(fn ($case) => [$case], ['connection_store', 'connection_domain', 'app_organization',
            'inactive_installation', 'inactive_store', 'inactive_app', 'disconnected', 'missing_secret', 'missing_token']);
    }

    #[DataProvider('unsafeRemoteResponses')]
    public function test_remote_owner_and_complete_subscription_snapshot_are_required(string $case): void
    {
        [, , , $installation] = $this->context();
        $response = $this->response([]);
        if ($case === 'wrong_owner') {
            $response['data']['currentAppInstallation']['app']['apiKey'] = 'another-app';
        }
        if ($case === 'missing_owner') {
            unset($response['data']['currentAppInstallation']);
        }
        if ($case === 'missing_snapshot') {
            unset($response['data']['webhookSubscriptions']);
        }
        if ($case === 'truncated') {
            $response['data']['webhookSubscriptions']['pageInfo']['hasNextPage'] = true;
        }
        if ($case === 'duplicate_topic') {
            $response['data']['webhookSubscriptions']['nodes'] = [
                ['id' => 'gid://shopify/WebhookSubscription/1', 'topic' => 'ORDERS_UPDATED', 'uri' => 'https://testadmin.example.com/first'],
                ['id' => 'gid://shopify/WebhookSubscription/2', 'topic' => 'ORDERS_UPDATED', 'uri' => 'https://testadmin.example.com/second'],
            ];
        }
        Http::fake(fn ($request) => Http::response($response));
        try {
            app(ShopifyWebhookSubscriptionService::class)->reconcile($installation);
            $this->fail('Unsafe upstream state must not lead to mutations.');
        } catch (ShopifyApiException $exception) {
            $this->assertStringNotContainsString('main-token', $exception->getMessage());
            Http::assertSentCount(1);
            Http::assertSent(fn ($request) => str_contains($request['query'], 'RegisteredWebhookSubscriptions'));
        }
    }

    public static function unsafeRemoteResponses(): array
    {
        return [['wrong_owner'], ['missing_owner'], ['missing_snapshot'], ['truncated'], ['duplicate_topic']];
    }

    public function test_main_signature_is_accepted_only_at_main_callback(): void
    {
        [$store, $main, $connection] = $this->context();
        $other = $this->independentInstallation($store, $connection, 'marketing');
        Queue::fake();
        $body = ['id' => 123];
        $raw = json_encode($body, JSON_THROW_ON_ERROR);
        $headers = ['X-Shopify-Hmac-Sha256' => base64_encode(hash_hmac('sha256', $raw, 'main-secret', true)),
            'X-Shopify-Webhook-Id' => (string) Str::uuid(), 'X-Shopify-Topic' => 'orders/updated',
            'X-Shopify-Shop-Domain' => $store->shopify_domain, 'X-Shopify-API-Version' => '2026-07'];
        $this->postJson(route('shopify.webhooks.receive', ['app' => $other->app->handle]), $body, $headers)->assertUnauthorized();
        $this->assertDatabaseCount('webhook_events', 0);
        $this->postJson(route('shopify.webhooks.receive', ['app' => $main->handle]), $body, $headers)->assertAccepted();
        $this->assertDatabaseHas('webhook_events', ['app_id' => $main->id, 'store_id' => $store->id, 'organization_id' => $store->organization_id]);
        Queue::assertPushed(ProcessWebhookEventJob::class, 1);
    }

    private function context(): array
    {
        config(['shopify.client_id' => 'main-client', 'shopify.app_handle' => 'shopify-commerce-hub', 'shopify.app_url' => 'https://testadmin.example.com']);
        $organization = Organization::query()->create(['name' => 'Owned', 'code' => 'owned']);
        $store = $organization->stores()->create(['name' => 'Owned', 'shopify_domain' => 'owned.myshopify.com', 'status' => 'active']);
        $main = App::query()->create(['name' => 'Main', 'handle' => 'shopify-commerce-hub', 'client_id' => 'main-client', 'client_secret_encrypted' => 'main-secret', 'distribution' => 'custom', 'status' => 'active']);
        $connection = ShopifyConnection::query()->create(['store_id' => $store->id, 'shop_domain' => $store->shopify_domain, 'access_token_encrypted' => 'main-token', 'status' => 'connected', 'api_version' => '2026-07', 'scopes' => ['read_orders']]);
        $installation = AppInstallation::query()->create(['app_id' => $main->id, 'store_id' => $store->id, 'shopify_connection_id' => $connection->id, 'status' => 'active', 'granted_scopes' => ['read_orders']]);

        return [$store, $main, $connection, $installation];
    }

    private function independentInstallation(Store $store, ShopifyConnection $connection, string $handle): AppInstallation
    {
        $app = App::query()->create(['name' => $handle, 'handle' => $handle, 'client_id' => $handle.'-client', 'client_secret_encrypted' => $handle.'-secret', 'distribution' => 'custom', 'status' => 'active']);

        return AppInstallation::query()->create(['app_id' => $app->id, 'store_id' => $store->id,
            'shopify_connection_id' => $connection->id, 'status' => 'active', 'access_token_encrypted' => $handle.'-token', 'granted_scopes' => ['read_orders']]);
    }

    private function response(array $nodes): array
    {
        return ['data' => ['currentAppInstallation' => ['app' => ['apiKey' => 'main-client']],
            'webhookSubscriptions' => ['nodes' => $nodes, 'pageInfo' => ['hasNextPage' => false]]]];
    }
}
