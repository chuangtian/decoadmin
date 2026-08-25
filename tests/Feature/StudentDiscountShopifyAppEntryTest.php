<?php

namespace Tests\Feature;

use App\Models\App;
use App\Models\AppInstallation;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Role;
use App\Models\ShopifyConnection;
use App\Models\Store;
use App\Models\User;
use App\Models\WebhookEvent;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class StudentDiscountShopifyAppEntryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'student_discount.environment' => 'test',
            'student_discount.active.client_id' => 'student-test-client-id',
            'student_discount.active.client_secret' => 'student-test-webhook-secret',
            'student_discount.active.name' => 'Deco Student Discount Test',
            'student_discount.active.handle' => 'deco-student-discount-test',
            'student_discount.active.proxy_path' => '/apps/deco-student-test',
            'student_discount.required_scopes' => ['read_discounts', 'write_discounts', 'read_products', 'write_app_proxy'],
            'shopify.api_version' => '2026-07',
        ]);
    }

    public function test_management_entry_requires_login_store_membership_and_student_discount_permission(): void
    {
        [$operator, $organization, $store] = $this->context('operator');
        $url = route('student-discounts.shopify-app.management', ['shop' => $store->shopify_domain, 'source' => 'shopify']);

        $this->get($url)->assertRedirect(route('login'));

        $this->actingAs($operator)
            ->get($url)
            ->assertRedirect(route('student-discounts.index', [$organization, $store]));

        $otherStore = $organization->stores()->create([
            'name' => 'Unauthorized Store',
            'shopify_domain' => 'unauthorized-student.myshopify.com',
            'status' => 'active',
        ]);
        $this->actingAs($operator)
            ->get(route('student-discounts.shopify-app.management', ['shop' => $otherStore->shopify_domain]))
            ->assertForbidden();

        $viewer = User::factory()->create(['email_verified_at' => now()]);
        $organization->users()->attach($viewer, ['status' => 'active', 'joined_at' => now()]);
        $store->members()->attach($viewer, ['status' => 'active', 'joined_at' => now()]);
        $viewerRole = Role::query()->whereBelongsTo($organization)->where('slug', 'viewer')->firstOrFail();
        $viewer->roles()->attach($viewerRole, ['organization_id' => $organization->id, 'store_id' => null]);

        $this->actingAs($viewer)->get($url)->assertForbidden();
        $this->actingAs($operator)
            ->get(route('student-discounts.shopify-app.management', ['shop' => 'missing-store.myshopify.com']))
            ->assertNotFound();
    }

    public function test_uninstall_webhook_uses_header_shop_is_idempotent_and_keeps_core_connection_active(): void
    {
        [, $organization, $store] = $this->context('store-admin');
        $connection = $this->connection($store);
        $webhookId = (string) Str::uuid();
        $payload = ['id' => 99, 'shop' => 'attacker.myshopify.com', 'domain' => 'attacker.myshopify.com'];

        $this->webhook('app/uninstalled', $store->shopify_domain, $payload, $webhookId)
            ->assertAccepted()
            ->assertJsonPath('accepted', true)
            ->assertJsonPath('duplicate', false)
            ->assertJsonPath('webhook_id', $webhookId);

        $app = App::query()->sole();
        $installation = AppInstallation::query()->sole();
        $event = WebhookEvent::query()->sole();
        $audit = AuditLog::query()->where('action', 'student_discount_shopify_app_uninstalled')->sole();
        $rawPayload = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->assertSame('deco-student-discount-test', $app->handle);
        $this->assertSame('student-test-webhook-secret', $app->client_secret_encrypted);
        $this->assertSame($store->id, $installation->store_id);
        $this->assertSame($connection->id, $installation->shopify_connection_id);
        $this->assertSame('uninstalled', $installation->status);
        $this->assertNotNull($installation->uninstalled_at);
        $this->assertSame($organization->id, $event->organization_id);
        $this->assertSame($store->id, $event->store_id);
        $this->assertSame('processed', $event->status);
        $this->assertSame('handled', $event->processing_result);
        $this->assertSame($rawPayload, $event->payload_encrypted);
        $this->assertArrayNotHasKey('hmac', $event->headers);
        $this->assertSame($store->id, $audit->store_id);
        $this->assertSame('active', $connection->fresh()->status);
        $this->assertSame('core-shopify-token', $connection->fresh()->access_token_encrypted);
        $this->assertStringNotContainsString('student-test-webhook-secret', (string) DB::table('apps')->value('client_secret_encrypted'));
        $this->assertStringNotContainsString('attacker.myshopify.com', (string) DB::table('webhook_events')->value('payload_encrypted'));
        $this->assertStringNotContainsString('student-test-webhook-secret', $audit->toJson());
        $storedSecretBeforeDuplicate = (string) DB::table('apps')->value('client_secret_encrypted');

        $this->webhook('app/uninstalled', $store->shopify_domain, $payload, $webhookId)
            ->assertOk()
            ->assertJsonPath('duplicate', true);
        $this->assertSame($storedSecretBeforeDuplicate, (string) DB::table('apps')->value('client_secret_encrypted'));
        $this->assertDatabaseCount('webhook_events', 1);
        $this->assertDatabaseCount('app_installations', 1);
        $this->assertSame(1, AuditLog::query()->where('action', 'student_discount_shopify_app_uninstalled')->count());

        $this->webhook('app/uninstalled', $store->shopify_domain, ['id' => 100], $webhookId)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'WEBHOOK_ID_CONFLICT');
        $this->assertDatabaseCount('webhook_events', 1);
    }

    public function test_scopes_update_reactivates_only_student_app_installation_and_normalizes_scopes(): void
    {
        [, , $store] = $this->context('store-admin');
        $connection = $this->connection($store);

        $this->webhook(
            'app/uninstalled',
            $store->shopify_domain,
            ['shop' => 'body-shop-is-ignored.myshopify.com'],
            (string) Str::uuid(),
        )->assertAccepted();
        $this->webhook(
            'app/scopes_update',
            $store->shopify_domain,
            ['shop' => 'body-shop-is-ignored.myshopify.com', 'current' => ['write_discounts', 'read_products', 'write_discounts']],
            (string) Str::uuid(),
        )->assertAccepted();

        $installation = AppInstallation::query()->sole();
        $this->assertSame('active', $installation->status);
        $this->assertNull($installation->uninstalled_at);
        $this->assertSame(['read_products', 'write_discounts'], $installation->granted_scopes);
        $this->assertSame($store->id, $installation->store_id);
        $this->assertSame('active', $connection->fresh()->status);
        $this->assertDatabaseCount('webhook_events', 2);
        $this->assertSame(1, AuditLog::query()->where('action', 'student_discount_shopify_app_scopes_updated')->count());
    }

    public function test_webhook_rejects_wrong_environment_secret_unsupported_topic_and_body_only_shop(): void
    {
        [, , $store] = $this->context('store-admin');
        $this->connection($store);

        $this->webhook(
            'app/uninstalled',
            $store->shopify_domain,
            ['shop' => $store->shopify_domain],
            (string) Str::uuid(),
            'wrong-environment-secret',
        )->assertUnauthorized()->assertJsonPath('error.code', 'INVALID_WEBHOOK_HMAC');

        $this->webhook('products/update', $store->shopify_domain, ['id' => 1], (string) Str::uuid())
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'UNSUPPORTED_WEBHOOK_TOPIC');

        $this->webhook(
            'app/uninstalled',
            'not-connected.myshopify.com',
            ['shop' => $store->shopify_domain],
            (string) Str::uuid(),
        )->assertNotFound()->assertJsonPath('error.code', 'STORE_NOT_CONNECTED');

        $this->assertDatabaseCount('apps', 0);
        $this->assertDatabaseCount('app_installations', 0);
        $this->assertDatabaseCount('webhook_events', 0);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertSame('active', $store->shopifyConnection->fresh()->status);
    }

    /** @return array{User, Organization, Store} */
    private function context(string $roleSlug): array
    {
        $this->seed(PermissionSeeder::class);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $organization = Organization::query()->create([
            'name' => 'Student Discount App',
            'code' => 'student-discount-app',
            'status' => 'active',
        ]);
        $organization->users()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $store = $organization->stores()->create([
            'name' => 'Student Discount Store',
            'shopify_domain' => 'student-discount-store.myshopify.com',
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
            'access_token_encrypted' => 'core-shopify-token',
            'scopes' => ['read_products'],
            'api_version' => '2026-07',
            'status' => 'active',
            'installed_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function webhook(
        string $topic,
        string $shop,
        array $payload,
        string $webhookId,
        string $secret = 'student-test-webhook-secret',
    ): TestResponse {
        $rawPayload = json_encode($payload, JSON_THROW_ON_ERROR);

        return $this->call('POST', route('student-discounts.shopify-app.webhooks'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SHOPIFY_HMAC_SHA256' => base64_encode(hash_hmac('sha256', $rawPayload, $secret, true)),
            'HTTP_X_SHOPIFY_WEBHOOK_ID' => $webhookId,
            'HTTP_X_SHOPIFY_TOPIC' => $topic,
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => $shop,
            'HTTP_X_SHOPIFY_API_VERSION' => '2026-07',
            'HTTP_X_SHOPIFY_TRIGGERED_AT' => '2026-08-26T10:00:00Z',
        ], $rawPayload);
    }
}
