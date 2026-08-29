<?php

namespace Tests\Feature;

use App\Jobs\ProcessWebhookEventJob;
use App\Models\App;
use App\Models\AppInstallation;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Role;
use App\Models\ShopifyConnection;
use App\Models\Store;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\Shopify\Webhooks\WebhookEventProcessor;
use App\Services\Shopify\Webhooks\WebhookEventStateService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ShopifyWebhookCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_hmac_saves_encrypted_event_and_dispatches_processing_job(): void
    {
        Queue::fake();
        [$user, $organization, $store, $app] = $this->installedAppContext('organization-admin');
        $payloadData = ['id' => 987, 'name' => 'Webhook Test'];
        $payload = json_encode($payloadData, JSON_THROW_ON_ERROR);
        $webhookId = (string) Str::uuid();

        $this->postJson(
            route('shopify.webhooks.receive', ['app' => $app->handle]),
            $payloadData,
            $this->shopifyHeaders($payload, $webhookId, $store->shopify_domain),
        )
            ->assertAccepted()
            ->assertJson([
                'accepted' => true,
                'duplicate' => false,
                'webhook_id' => $webhookId,
            ]);

        $event = WebhookEvent::query()->sole();
        $this->assertSame($organization->id, $event->organization_id);
        $this->assertSame($store->id, $event->store_id);
        $this->assertSame('orders/create', $event->topic);
        $this->assertSame('queued', $event->status);
        $this->assertSame(['id' => 987, 'name' => 'Webhook Test'], $event->decodedPayload());
        $this->assertTrue($event->payloadIntegrityIsValid());
        $this->assertArrayNotHasKey('hmac', $event->headers);
        $this->assertStringNotContainsString($payload, (string) DB::table('webhook_events')->value('payload_encrypted'));
        Queue::assertPushed(ProcessWebhookEventJob::class, fn (ProcessWebhookEventJob $job) => $job->webhookEventId === $event->id);
    }

    public function test_invalid_hmac_is_rejected_without_saving_event(): void
    {
        Queue::fake();
        [, , $store, $app] = $this->installedAppContext('organization-admin');
        $payloadData = ['id' => 987];
        $payload = json_encode($payloadData, JSON_THROW_ON_ERROR);
        $headers = $this->shopifyHeaders($payload, (string) Str::uuid(), $store->shopify_domain);
        $headers['X-Shopify-Hmac-Sha256'] = base64_encode(str_repeat('x', 32));

        $this->postJson(
            route('shopify.webhooks.receive', ['app' => $app->handle]),
            $payloadData,
            $headers,
        )
            ->assertUnauthorized();

        $this->assertDatabaseCount('webhook_events', 0);
        Queue::assertNothingPushed();
    }

    public function test_duplicate_webhook_is_idempotent(): void
    {
        Queue::fake();
        [, , $store, $app] = $this->installedAppContext('organization-admin');
        $payloadData = ['id' => 987];
        $payload = json_encode($payloadData, JSON_THROW_ON_ERROR);
        $webhookId = (string) Str::uuid();
        $headers = $this->shopifyHeaders($payload, $webhookId, $store->shopify_domain);

        $this->postJson(route('shopify.webhooks.receive', ['app' => $app->handle]), $payloadData, $headers)
            ->assertAccepted();
        $this->postJson(route('shopify.webhooks.receive', ['app' => $app->handle]), $payloadData, $headers)
            ->assertOk()
            ->assertJson(['duplicate' => true]);

        $this->assertDatabaseCount('webhook_events', 1);
        Queue::assertPushed(ProcessWebhookEventJob::class, 1);
    }

    public function test_webhook_list_and_detail_are_isolated_by_organization(): void
    {
        [$user, $organization, $store, $app, $connection] = $this->installedAppContext('organization-admin');
        $visible = $this->event($organization, $store, $app, $connection);
        $otherOrganization = Organization::query()->create(['name' => 'Asiwo', 'code' => 'asiwo']);
        $otherStore = $otherOrganization->stores()->create(['name' => 'Asiwo US', 'shopify_domain' => 'asiwo-us.myshopify.com', 'status' => 'active']);
        $otherApp = $this->app('asiwo-app');
        $otherConnection = $this->connection($otherStore);
        $hidden = $this->event($otherOrganization, $otherStore, $otherApp, $otherConnection);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id, 'current_store_id' => $store->id])
            ->get(route('webhooks.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Webhooks/Index')
                ->has('events.data', 1)
                ->where('events.data.0.id', $visible->id));

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id, 'current_store_id' => $store->id])
            ->get(route('webhooks.show', $hidden))
            ->assertForbidden();
    }

    public function test_webhook_events_are_isolated_to_authorized_stores(): void
    {
        [$user, $organization, $store, $app, $connection] = $this->installedAppContext('organization-admin');
        $visible = $this->event($organization, $store, $app, $connection);
        $hiddenStore = $organization->stores()->create(['name' => 'Macfox EU', 'shopify_domain' => 'macfox-eu.myshopify.com', 'status' => 'active']);
        $hiddenConnection = $this->connection($hiddenStore);
        $hidden = $this->event($organization, $hiddenStore, $app, $hiddenConnection);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id, 'current_store_id' => $store->id])
            ->get(route('webhooks.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('events.data', 1)
                ->where('events.data.0.id', $visible->id));

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id, 'current_store_id' => $store->id])
            ->get(route('webhooks.show', $hidden))
            ->assertForbidden();
    }

    public function test_webhook_list_query_does_not_load_encrypted_payload_columns(): void
    {
        [$user, $organization, $store, $app, $connection] = $this->installedAppContext('organization-admin');
        $this->event($organization, $store, $app, $connection);
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $sql = strtolower($query->sql);
            if (str_contains($sql, 'webhook_events')) {
                $queries[] = $sql;
            }
        });

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id, 'current_store_id' => $store->id])
            ->get(route('webhooks.index'))
            ->assertOk();

        $listQuery = collect($queries)->first(
            fn (string $sql): bool => str_contains($sql, 'order by') && str_contains($sql, 'received_at'),
        );
        $this->assertNotNull($listQuery);
        $this->assertStringNotContainsString('payload_encrypted', $listQuery);
        $this->assertStringNotContainsString('"payload"', $listQuery);
        $this->assertStringNotContainsString('"headers"', $listQuery);
    }

    public function test_viewer_cannot_access_webhook_center(): void
    {
        [$viewer, $organization, $store, $app, $connection] = $this->installedAppContext('viewer');
        $event = $this->event($organization, $store, $app, $connection);

        $this->actingAs($viewer)
            ->withSession(['current_organization_id' => $organization->id, 'current_store_id' => $store->id])
            ->get(route('webhooks.index'))
            ->assertForbidden();
        $this->actingAs($viewer)
            ->withSession(['current_organization_id' => $organization->id, 'current_store_id' => $store->id])
            ->get(route('webhooks.show', $event))
            ->assertForbidden();
        $this->actingAs($viewer)
            ->withSession(['current_organization_id' => $organization->id, 'current_store_id' => $store->id])
            ->post(route('webhooks.retry', $event))
            ->assertForbidden();
    }

    public function test_store_admin_can_retry_failed_event_without_leaking_secrets_to_audit_log(): void
    {
        Queue::fake();
        [$admin, $organization, $store, $app, $connection] = $this->installedAppContext('store-admin');
        $event = $this->event($organization, $store, $app, $connection, 'failed');
        $event->forceFill(['last_error' => 'Temporary handler failure', 'attempts' => 2])->save();

        $this->actingAs($admin)
            ->withSession(['current_organization_id' => $organization->id, 'current_store_id' => $store->id])
            ->post(route('webhooks.retry', $event))
            ->assertRedirect();

        $event->refresh();
        $this->assertSame('retrying', $event->status);
        $this->assertSame('Temporary handler failure', $event->last_error);
        $audit = AuditLog::query()->where('action', 'shopify_webhook_retried')->sole();
        $this->assertSame($organization->id, $audit->organization_id);
        $this->assertSame($store->id, $audit->store_id);
        $this->assertSame($admin->id, $audit->user_id);
        $serializedAudit = json_encode($audit->toArray(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('webhook-secret', $serializedAudit);
        $this->assertStringNotContainsString('shpat_test_token', $serializedAudit);
        Queue::assertPushed(ProcessWebhookEventJob::class, fn (ProcessWebhookEventJob $job) => $job->webhookEventId === $event->id);
    }

    public function test_processing_job_completes_ingestion_without_running_business_sync(): void
    {
        [, $organization, $store, $app, $connection] = $this->installedAppContext('store-admin');
        $event = $this->event($organization, $store, $app, $connection, 'queued');
        $event->forceFill(['attempts' => 0, 'processed_at' => null])->save();

        (new ProcessWebhookEventJob($event->id))->handle(
            app(WebhookEventProcessor::class),
            app(WebhookEventStateService::class),
        );

        $event->refresh();
        $this->assertSame('processed', $event->status);
        $this->assertSame(1, $event->attempts);
        $this->assertSame('handled', $event->processing_result);
        $this->assertNotNull($event->processed_at);
        $this->assertNotNull($event->processing_duration_ms);
        $this->assertDatabaseCount('sync_jobs', 0);
    }

    /** @return array{0: User, 1: Organization, 2: Store, 3: App, 4: ShopifyConnection} */
    private function installedAppContext(string $roleSlug): array
    {
        $this->seed(PermissionSeeder::class);
        $user = User::factory()->create();
        $organization = Organization::query()->create(['name' => 'Macfox', 'code' => 'macfox']);
        $organization->users()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $store = $organization->stores()->create(['name' => 'Macfox US', 'shopify_domain' => 'macfox-us.myshopify.com', 'status' => 'active']);
        $store->members()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $this->seed(RoleSeeder::class);
        $role = Role::query()->whereBelongsTo($organization)->where('slug', $roleSlug)->firstOrFail();
        $user->roles()->attach($role, ['organization_id' => $organization->id, 'store_id' => null]);
        $app = $this->app('shopify-commerce-hub');
        $connection = $this->connection($store);
        AppInstallation::query()->create([
            'app_id' => $app->id,
            'store_id' => $store->id,
            'shopify_connection_id' => $connection->id,
            'installed_by' => $user->id,
            'status' => 'active',
            'granted_scopes' => ['read_products'],
            'installed_at' => now(),
        ]);

        return [$user, $organization, $store, $app, $connection];
    }

    private function app(string $handle): App
    {
        return App::query()->create([
            'name' => 'Shopify Commerce Hub',
            'handle' => $handle,
            'client_id' => Str::uuid()->toString(),
            'client_secret_encrypted' => 'webhook-secret',
            'distribution' => 'custom',
            'status' => 'active',
        ]);
    }

    private function connection(Store $store): ShopifyConnection
    {
        return ShopifyConnection::query()->create([
            'store_id' => $store->id,
            'shop_domain' => $store->shopify_domain,
            'access_token_encrypted' => 'shpat_test_token',
            'token_type' => 'offline',
            'scopes' => ['read_products'],
            'api_version' => '2026-07',
            'status' => 'connected',
            'installed_at' => now(),
        ]);
    }

    private function event(
        Organization $organization,
        Store $store,
        App $app,
        ShopifyConnection $connection,
        string $status = 'processed',
    ): WebhookEvent {
        $rawPayload = json_encode([
            'id' => random_int(1, 999999),
            'name' => '#1001',
            'email' => 'webhook@example.com',
            'financial_status' => 'paid',
            'fulfillment_status' => null,
            'currency' => 'USD',
            'total_price' => '25.00',
            'subtotal_price' => '25.00',
            'total_tax' => '0.00',
            'processed_at' => now()->toIso8601String(),
            'created_at' => now()->toIso8601String(),
            'line_items' => [],
        ], JSON_THROW_ON_ERROR);

        return WebhookEvent::query()->create([
            'webhook_id' => (string) Str::uuid(),
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'shopify_connection_id' => $connection->id,
            'app_id' => $app->id,
            'topic' => 'orders/create',
            'api_version' => '2026-07',
            'headers' => ['topic' => 'orders/create', 'shop_domain' => $store->shopify_domain],
            'payload' => ['storage' => 'encrypted'],
            'payload_encrypted' => $rawPayload,
            'payload_sha256' => hash('sha256', $rawPayload),
            'status' => $status,
            'attempts' => 1,
            'received_at' => now(),
            'processed_at' => $status === 'processed' ? now() : null,
        ]);
    }

    /** @return array<string, string> */
    private function shopifyHeaders(string $payload, string $webhookId, string $shopDomain): array
    {
        return [
            'X-Shopify-Hmac-Sha256' => base64_encode(hash_hmac('sha256', $payload, 'webhook-secret', true)),
            'X-Shopify-Webhook-Id' => $webhookId,
            'X-Shopify-Topic' => 'orders/create',
            'X-Shopify-Shop-Domain' => $shopDomain,
            'X-Shopify-API-Version' => '2026-07',
            'X-Shopify-Triggered-At' => now()->toIso8601String(),
        ];
    }
}
