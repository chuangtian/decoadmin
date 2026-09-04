<?php

namespace Tests\Feature;

use App\Models\CodexApiToken;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreNotificationSetting;
use App\Models\User;
use App\Models\WebhookEvent;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CodexApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_codex_api_requires_a_valid_bearer_token(): void
    {
        $this->getJson('/api/codex/v1/stores')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'codex_unauthenticated');

        $this->withToken('not-a-valid-token')
            ->getJson('/api/codex/v1/stores')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'codex_unauthenticated');
    }

    public function test_store_list_is_limited_to_the_token_organization_and_user_rbac(): void
    {
        [$user, $organization, $store] = $this->context('organization-admin');
        $otherOrganization = Organization::query()->create(['name' => 'Other', 'code' => 'other']);
        $otherOrganization->stores()->create([
            'name' => 'Hidden Store', 'shopify_domain' => 'hidden-codex.myshopify.com', 'status' => 'active',
        ]);
        $plainTextToken = $this->token($user, $organization);

        $this->withToken($plainTextToken)
            ->getJson('/api/codex/v1/stores')
            ->assertOk()
            ->assertJsonPath('schema_version', 'decoadmin-codex-v1')
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $store->id)
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonMissing(['name' => 'Hidden Store']);
    }

    public function test_order_output_is_bounded_to_the_store_and_excludes_customer_contact_data(): void
    {
        [$user, $organization, $store] = $this->context('organization-admin');
        $secretEmail = 'private-customer@example.com';
        Order::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'shopify_order_id' => 1001,
            'order_number' => '#1001',
            'email' => $secretEmail,
            'financial_status' => 'paid',
            'fulfillment_status' => null,
            'currency' => 'USD',
            'total_price' => 120,
            'subtotal_price' => 100,
            'net_sales' => 100,
            'total_tax' => 20,
            'created_at_shopify' => now(),
            'synced_at' => now(),
        ]);

        $response = $this->withToken($this->token($user, $organization))
            ->getJson("/api/codex/v1/stores/{$store->id}/orders")
            ->assertOk()
            ->assertJsonPath('data.items.0.order_number', '#1001')
            ->assertJsonPath('data.pagination.total', 1);

        $response->assertDontSee($secretEmail)->assertJsonMissingPath('data.items.0.email');
    }

    public function test_token_ability_and_user_permission_are_both_enforced(): void
    {
        [$user, $organization, $store] = $this->context('customer-service');
        $storesOnlyToken = $this->token($user, $organization, ['stores:read']);

        $this->withToken($storesOnlyToken)
            ->getJson("/api/codex/v1/stores/{$store->id}/orders")
            ->assertForbidden();

        $ordersToken = $this->token($user, $organization, ['orders:read']);
        $this->withToken($ordersToken)
            ->getJson("/api/codex/v1/stores/{$store->id}/orders")
            ->assertOk();

        $this->withToken($ordersToken)
            ->getJson("/api/codex/v1/stores/{$store->id}/configuration-status")
            ->assertForbidden();
    }

    public function test_store_from_another_organization_is_not_disclosed(): void
    {
        [$user, $organization] = $this->context('organization-admin');
        $otherOrganization = Organization::query()->create(['name' => 'Other', 'code' => 'other-scope']);
        $otherStore = $otherOrganization->stores()->create([
            'name' => 'Other Store', 'shopify_domain' => 'other-codex.myshopify.com', 'status' => 'active',
        ]);

        $this->withToken($this->token($user, $organization))
            ->getJson("/api/codex/v1/stores/{$otherStore->id}/orders")
            ->assertNotFound();
    }

    public function test_missing_store_returns_a_stable_sanitized_error(): void
    {
        [$user, $organization] = $this->context('organization-admin');

        $this->withToken($this->token($user, $organization))
            ->getJson('/api/codex/v1/stores/999999/orders')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'codex_resource_not_found')
            ->assertJsonPath('error.message', '目标资源不存在或当前用户无权访问。')
            ->assertDontSee('App\\Models\\Store');
    }

    public function test_configuration_status_never_returns_stored_secrets(): void
    {
        [$user, $organization, $store] = $this->context('organization-admin');
        $mailSecret = 'smtp-secret-value';
        $feishuSecret = 'https://open.feishu.cn/open-apis/bot/v2/hook/private-secret';
        StoreNotificationSetting::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'mail_enabled' => true,
            'mail_host' => 'smtp.example.com',
            'mail_from_address' => 'robot@example.com',
            'mail_recipients' => ['ops@example.com'],
            'mail_password' => $mailSecret,
            'feishu_enabled' => true,
            'feishu_webhook_url' => $feishuSecret,
        ]);

        $response = $this->withToken($this->token($user, $organization))
            ->getJson("/api/codex/v1/stores/{$store->id}/configuration-status")
            ->assertOk()
            ->assertJsonPath('data.store_notifications.mail.checks.password', true)
            ->assertJsonPath('data.store_notifications.feishu.checks.webhook', true);

        $response->assertDontSee($mailSecret)->assertDontSee($feishuSecret);
    }

    public function test_operations_output_excludes_webhook_processing_details(): void
    {
        [$user, $organization, $store] = $this->context('organization-admin');
        $secret = 'webhook-processing-secret';
        WebhookEvent::query()->create([
            'webhook_id' => (string) Str::uuid(),
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'topic' => 'orders/create',
            'payload' => ['storage' => 'redacted'],
            'status' => 'processed',
            'processing_result' => $secret,
            'received_at' => now(),
            'processed_at' => now(),
        ]);

        $this->withToken($this->token($user, $organization))
            ->getJson("/api/codex/v1/stores/{$store->id}/operations")
            ->assertOk()
            ->assertJsonPath('data.webhooks.events.0.topic', 'orders/create')
            ->assertJsonMissingPath('data.webhooks.events.0.processing_result')
            ->assertDontSee($secret);
    }

    public function test_expired_or_revoked_token_is_rejected(): void
    {
        [$user, $organization] = $this->context('organization-admin');
        $issued = CodexApiToken::issue($user, $organization, 'expired', ['stores:read'], 1);
        $issued['token']->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->withToken($issued['plain_text_token'])
            ->getJson('/api/codex/v1/stores')
            ->assertUnauthorized();
    }

    public function test_token_for_an_unverified_user_is_rejected(): void
    {
        [$user, $organization] = $this->context('organization-admin');
        $issued = CodexApiToken::issue($user, $organization, 'unverified', ['stores:read'], 30);
        $user->forceFill(['email_verified_at' => null])->save();

        $this->withToken($issued['plain_text_token'])
            ->getJson('/api/codex/v1/stores')
            ->assertUnauthorized();
    }

    public function test_token_can_be_revoked_and_the_action_is_audited(): void
    {
        [$user, $organization] = $this->context('organization-admin');
        $issued = CodexApiToken::issue($user, $organization, 'revoke me', ['stores:read'], 30);

        $this->artisan('codex:revoke-token', ['token' => $issued['token']->uuid])
            ->assertSuccessful();

        $this->assertNotNull($issued['token']->fresh()->revoked_at);
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'action' => 'codex_api_token_revoked',
            'subject_type' => CodexApiToken::class,
            'subject_id' => $issued['token']->id,
        ]);
        $this->withToken($issued['plain_text_token'])
            ->getJson('/api/codex/v1/stores')
            ->assertUnauthorized();
    }

    public function test_token_can_be_issued_using_numeric_user_and_organization_ids(): void
    {
        [$user, $organization] = $this->context('organization-admin');

        $this->artisan('codex:issue-token', [
            'user' => (string) $user->id,
            'organization' => (string) $organization->id,
            '--name' => 'Numeric identifier test',
        ])->assertSuccessful();

        $token = CodexApiToken::query()->where('name', 'Numeric identifier test')->firstOrFail();

        $this->assertSame($user->id, $token->user_id);
        $this->assertSame($organization->id, $token->organization_id);
        $this->assertSame(CodexApiToken::DEFAULT_ABILITIES, $token->abilities);
    }

    /** @return array{0: User, 1: Organization, 2: Store} */
    private function context(string $roleSlug): array
    {
        $this->seed(PermissionSeeder::class);
        $user = User::factory()->create();
        $organization = Organization::query()->create(['name' => 'DecoMKT', 'code' => 'decomkt']);
        $organization->users()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $store = $organization->stores()->create([
            'name' => 'Macfox Bike', 'shopify_domain' => 'macfox-codex.myshopify.com', 'status' => 'active',
        ]);
        $store->members()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $this->seed(RoleSeeder::class);
        $role = Role::query()->whereBelongsTo($organization)->where('slug', $roleSlug)->firstOrFail();
        $user->roles()->attach($role, ['organization_id' => $organization->id, 'store_id' => null]);

        return [$user, $organization, $store];
    }

    /** @param list<string>|null $abilities */
    private function token(User $user, Organization $organization, ?array $abilities = null): string
    {
        return CodexApiToken::issue(
            $user,
            $organization,
            'Codex test token',
            $abilities ?? CodexApiToken::DEFAULT_ABILITIES,
            30,
        )['plain_text_token'];
    }
}
