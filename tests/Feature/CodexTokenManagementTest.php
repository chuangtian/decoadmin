<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CodexApiToken;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CodexTokenManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_organization_admin_can_open_the_scoped_authorization_page_without_token_secrets(): void
    {
        [$admin, $organization, $store] = $this->context('organization-admin');
        $recipient = $this->member($organization, 'recipient@example.com');
        $issued = CodexApiToken::issue($recipient, $organization, 'Recipient laptop', ['stores:read'], 30, $admin);
        $secret = $issued['plain_text_token'];

        $this->actingAs($admin)
            ->withSession($this->contextSession($organization, $store))
            ->get(route('codex-tokens.index'))
            ->assertOk()
            ->assertDontSee($secret)
            ->assertDontSee($issued['token']->token_hash)
            ->assertInertia(fn (Assert $page) => $page
                ->component('CodexTokens/Index')
                ->where('canManage', true)
                ->has('tokens.data', 1)
                ->where('tokens.data.0.user.email', 'recipient@example.com')
                ->missing('tokens.data.0.token_hash')
                ->missing('tokens.data.0.idempotency_key')
                ->has('abilities', count(CodexApiToken::SUPPORTED_ABILITIES)));
    }

    public function test_authorized_admin_can_issue_a_confirmed_audited_write_token_once(): void
    {
        [$admin, $organization, $store] = $this->context('organization-admin');
        $recipient = $this->member($organization, 'codex-user@example.com');
        $idempotencyKey = (string) Str::uuid();
        $payload = [
            'user_id' => $recipient->id,
            'name' => 'Codex user laptop',
            'abilities' => ['stores:read', 'dashboard:read', 'analytics:write'],
            'expires_in_days' => 30,
            'idempotency_key' => $idempotencyKey,
            'confirmation_text' => '确认签发',
        ];

        $response = $this->actingAs($admin)
            ->withSession($this->contextSession($organization, $store))
            ->postJson(route('codex-tokens.store'), $payload)
            ->assertCreated()
            ->assertJsonPath('data.user.id', $recipient->id)
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('idempotent_replay', false);

        $plainTextToken = $response->json('plain_text_token');
        $this->assertIsString($plainTextToken);
        $this->assertStringStartsWith('dca_', $plainTextToken);
        $token = CodexApiToken::query()->sole();
        $this->assertSame($admin->id, $token->issued_by);
        $this->assertSame($idempotencyKey, $token->idempotency_key);
        $this->assertSame(['stores:read', 'dashboard:read', 'analytics:write'], $token->abilities);
        $this->assertStringNotContainsString($token->token_hash, $plainTextToken);
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'user_id' => $admin->id,
            'action' => 'codex_api_token_issued',
            'subject_type' => CodexApiToken::class,
            'subject_id' => $token->id,
        ]);

        $this->actingAs($admin)
            ->withSession($this->contextSession($organization, $store))
            ->postJson(route('codex-tokens.store'), $payload)
            ->assertOk()
            ->assertJsonPath('plain_text_token', null)
            ->assertJsonPath('idempotent_replay', true);

        $this->assertDatabaseCount('codex_api_tokens', 1);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_issue_requires_exact_confirmation_and_rejects_idempotency_payload_changes(): void
    {
        [$admin, $organization, $store] = $this->context('organization-admin');
        $recipient = $this->member($organization, 'confirm@example.com');
        $idempotencyKey = (string) Str::uuid();
        $payload = [
            'user_id' => $recipient->id,
            'name' => 'Confirmation test',
            'abilities' => ['stores:read'],
            'expires_in_days' => 30,
            'idempotency_key' => $idempotencyKey,
        ];

        $this->actingAs($admin)
            ->withSession($this->contextSession($organization, $store))
            ->postJson(route('codex-tokens.store'), [...$payload, 'confirmation_text' => '立即执行'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('confirmation_text');
        $this->assertDatabaseCount('codex_api_tokens', 0);

        $this->actingAs($admin)
            ->withSession($this->contextSession($organization, $store))
            ->postJson(route('codex-tokens.store'), [...$payload, 'confirmation_text' => '确认签发'])
            ->assertCreated();
        $this->actingAs($admin)
            ->withSession($this->contextSession($organization, $store))
            ->postJson(route('codex-tokens.store'), [
                ...$payload,
                'name' => 'Changed request',
                'confirmation_text' => '确认签发',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('idempotency_key');
        $this->assertDatabaseCount('codex_api_tokens', 1);
    }

    public function test_token_target_must_be_an_active_member_of_the_current_organization(): void
    {
        [$admin, $organization, $store] = $this->context('organization-admin');
        $otherOrganization = Organization::query()->create(['name' => 'Other', 'code' => 'other-codex-org']);
        $otherUser = $this->member($otherOrganization, 'other@example.com');

        $this->actingAs($admin)
            ->withSession($this->contextSession($organization, $store))
            ->postJson(route('codex-tokens.store'), [
                'user_id' => $otherUser->id,
                'name' => 'Cross organization attempt',
                'abilities' => ['stores:read'],
                'expires_in_days' => 30,
                'idempotency_key' => (string) Str::uuid(),
                'confirmation_text' => '确认签发',
            ])
            ->assertNotFound();

        $this->assertDatabaseCount('codex_api_tokens', 0);
    }

    public function test_unverified_member_cannot_receive_a_plugin_token(): void
    {
        [$admin, $organization, $store] = $this->context('organization-admin');
        $recipient = User::factory()->unverified()->create(['status' => 'active']);
        $organization->users()->attach($recipient, ['status' => 'active', 'joined_at' => now()]);

        $this->actingAs($admin)
            ->withSession($this->contextSession($organization, $store))
            ->postJson(route('codex-tokens.store'), [
                'user_id' => $recipient->id,
                'name' => 'Unverified member',
                'abilities' => ['stores:read'],
                'expires_in_days' => 30,
                'idempotency_key' => (string) Str::uuid(),
                'confirmation_text' => '确认签发',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user_id');

        $this->assertDatabaseCount('codex_api_tokens', 0);
    }

    public function test_revoke_requires_confirmation_is_scoped_and_is_idempotent(): void
    {
        [$admin, $organization, $store] = $this->context('organization-admin');
        $recipient = $this->member($organization, 'revoke-web@example.com');
        $issued = CodexApiToken::issue($recipient, $organization, 'Revoke web token', ['stores:read'], 30, $admin);
        $endpoint = route('codex-tokens.destroy', $issued['token']);

        $this->actingAs($admin)
            ->withSession($this->contextSession($organization, $store))
            ->deleteJson($endpoint, [
                'idempotency_key' => (string) Str::uuid(),
                'confirmation_text' => '撤销',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('confirmation_text');
        $this->assertNull($issued['token']->fresh()->revoked_at);

        $payload = ['idempotency_key' => (string) Str::uuid(), 'confirmation_text' => '确认撤销'];
        $this->actingAs($admin)
            ->withSession($this->contextSession($organization, $store))
            ->deleteJson($endpoint, $payload)
            ->assertOk()
            ->assertJsonPath('data.status', 'revoked')
            ->assertJsonPath('idempotent_replay', false);
        $this->actingAs($admin)
            ->withSession($this->contextSession($organization, $store))
            ->deleteJson($endpoint, $payload)
            ->assertOk()
            ->assertJsonPath('idempotent_replay', true);

        $this->assertNotNull($issued['token']->fresh()->revoked_at);
        $this->assertSame(1, AuditLog::query()
            ->where('organization_id', $organization->id)
            ->where('action', 'codex_api_token_revoked')
            ->count());
    }

    public function test_user_without_codex_token_permissions_cannot_view_or_manage_authorizations(): void
    {
        [$user, $organization, $store] = $this->context('customer-service');

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->get(route('codex-tokens.index'))
            ->assertForbidden();
        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->postJson(route('codex-tokens.store'), [])
            ->assertForbidden();
    }

    /** @return array{0: User, 1: Organization, 2: Store} */
    private function context(string $roleSlug): array
    {
        $this->seed(PermissionSeeder::class);
        $organization = Organization::query()->create(['name' => 'DecoMKT', 'code' => 'decomkt-codex-management']);
        $user = $this->member($organization, "{$roleSlug}@example.com");
        $store = $organization->stores()->create([
            'name' => 'Macfox Bike',
            'shopify_domain' => "{$roleSlug}-codex-management.myshopify.com",
            'status' => 'active',
        ]);
        $store->members()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $this->seed(RoleSeeder::class);
        $role = Role::query()->whereBelongsTo($organization)->where('slug', $roleSlug)->firstOrFail();
        $user->roles()->attach($role, ['organization_id' => $organization->id, 'store_id' => null]);

        return [$user, $organization, $store];
    }

    private function member(Organization $organization, string $email): User
    {
        $user = User::factory()->create(['email' => $email, 'status' => 'active']);
        $organization->users()->attach($user, ['status' => 'active', 'joined_at' => now()]);

        return $user;
    }

    /** @return array<string, int> */
    private function contextSession(Organization $organization, Store $store): array
    {
        return ['current_organization_id' => $organization->id, 'current_store_id' => $store->id];
    }
}
