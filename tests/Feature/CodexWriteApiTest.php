<?php

namespace Tests\Feature;

use App\Jobs\ProcessSyncJob;
use App\Models\CodexApiToken;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreNotificationSetting;
use App\Models\SyncJob;
use App\Models\User;
use App\Services\AnalyticsCacheVersionService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Tests\TestCase;

class CodexWriteApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_token_ability_and_current_user_rbac_are_both_required(): void
    {
        [$admin, $organization, $store] = $this->context('organization-admin');
        $readToken = $this->token($admin, $organization, CodexApiToken::DEFAULT_ABILITIES);
        $this->withToken($readToken)
            ->postJson("/api/codex/v1/stores/{$store->id}/actions/prepare/analytics-refresh", [
                'idempotency_key' => 'refresh-read-only',
            ])->assertForbidden();

        [$serviceUser, $serviceOrganization, $serviceStore] = $this->context('customer-service', 'customer-service-org');
        $writeToken = $this->token($serviceUser, $serviceOrganization, ['analytics:write']);
        $this->withToken($writeToken)
            ->postJson("/api/codex/v1/stores/{$serviceStore->id}/actions/prepare/analytics-refresh", [
                'idempotency_key' => 'refresh-no-rbac',
            ])->assertForbidden()
            ->assertJsonPath('error.code', 'codex_rbac_forbidden');
    }

    public function test_analytics_refresh_requires_a_second_confirmation_and_replays_idempotently(): void
    {
        [$user, $organization, $store] = $this->context('organization-admin');
        $token = $this->token($user, $organization, ['analytics:write']);
        $versions = app(AnalyticsCacheVersionService::class);
        $this->assertSame(1, $versions->current($store->id));

        $prepared = $this->withToken($token)
            ->postJson("/api/codex/v1/stores/{$store->id}/actions/prepare/analytics-refresh", [
                'idempotency_key' => 'refresh-confirmation-001',
            ])->assertStatus(202)
            ->assertJsonPath('data.confirmation.status', 'pending')
            ->assertJsonPath('data.required_confirmation_text', '确认执行');
        $confirmationId = $prepared->json('data.confirmation.id');
        $this->assertSame(1, $versions->current($store->id));

        $this->withToken($token)
            ->postJson("/api/codex/v1/actions/{$confirmationId}/execute", ['confirmation_text' => '确认'])
            ->assertUnprocessable();
        $this->assertSame(1, $versions->current($store->id));

        $this->withToken($token)
            ->postJson("/api/codex/v1/actions/{$confirmationId}/execute", ['confirmation_text' => '确认执行'])
            ->assertOk()
            ->assertJsonPath('data.idempotent_replay', false)
            ->assertJsonPath('data.result.cache_version', 2);
        $this->assertSame(2, $versions->current($store->id));

        $this->withToken($token)
            ->postJson("/api/codex/v1/actions/{$confirmationId}/execute", ['confirmation_text' => '确认执行'])
            ->assertOk()
            ->assertJsonPath('data.idempotent_replay', true)
            ->assertJsonPath('data.result.cache_version', 2);
        $this->assertSame(2, $versions->current($store->id));
        $this->assertDatabaseCount('codex_action_confirmations', 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'codex_action_confirmation_prepared', 'user_id' => $user->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'codex_action_executed', 'user_id' => $user->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'codex_analytics_refreshed', 'user_id' => $user->id]);
    }

    public function test_prepare_is_idempotent_and_rejects_changed_payload_for_the_same_key(): void
    {
        [$user, $organization, $store] = $this->context('store-admin');
        $token = $this->token($user, $organization, ['configuration:write']);
        $url = "/api/codex/v1/stores/{$store->id}/actions/prepare/store-notifications";
        $first = $this->withToken($token)->postJson($url, [
            'mail_enabled' => false,
            'idempotency_key' => 'notification-idempotency-001',
        ])->assertStatus(202);
        $confirmationId = $first->json('data.confirmation.id');

        $this->withToken($token)->postJson($url, [
            'mail_enabled' => false,
            'idempotency_key' => 'notification-idempotency-001',
        ])->assertStatus(202)
            ->assertJsonPath('data.confirmation.id', $confirmationId)
            ->assertJsonPath('data.idempotent_replay', true);

        $this->withToken($token)->postJson($url, [
            'mail_enabled' => true,
            'idempotency_key' => 'notification-idempotency-001',
        ])->assertConflict()
            ->assertJsonPath('error.code', 'codex_idempotency_conflict');
    }

    public function test_permission_is_checked_again_when_the_confirmation_is_executed(): void
    {
        [$user, $organization, $store, $role] = $this->context('store-admin');
        $token = $this->token($user, $organization, ['configuration:write']);
        $prepared = $this->withToken($token)
            ->postJson("/api/codex/v1/stores/{$store->id}/actions/prepare/store-notifications", [
                'notify_sync_failed' => false,
                'idempotency_key' => 'permission-recheck-001',
            ])->assertStatus(202);
        $confirmationId = $prepared->json('data.confirmation.id');
        $user->roles()->detach($role->id);

        $this->withToken($token)
            ->postJson("/api/codex/v1/actions/{$confirmationId}/execute", ['confirmation_text' => '确认执行'])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'codex_rbac_forbidden');
        $this->assertDatabaseMissing('store_notification_settings', ['store_id' => $store->id]);
    }

    public function test_sync_is_queued_once_after_confirmation(): void
    {
        Bus::fake([ProcessSyncJob::class]);
        [$user, $organization, $store] = $this->context('organization-admin');
        $token = $this->token($user, $organization, ['sync:write']);
        $prepared = $this->withToken($token)
            ->postJson("/api/codex/v1/stores/{$store->id}/actions/prepare/sync", [
                'type' => 'orders',
                'mode' => 'incremental',
                'idempotency_key' => 'sync-orders-001',
            ])->assertStatus(202);
        $confirmationId = $prepared->json('data.confirmation.id');
        $this->assertDatabaseCount('sync_jobs', 0);

        $this->withToken($token)
            ->postJson("/api/codex/v1/actions/{$confirmationId}/execute", ['confirmation_text' => '确认执行'])
            ->assertOk()
            ->assertJsonPath('data.result.sync_job.type', 'orders')
            ->assertJsonPath('data.result.sync_job.mode', 'full')
            ->assertJsonPath('data.result.sync_job.status', 'queued');
        $this->withToken($token)
            ->postJson("/api/codex/v1/actions/{$confirmationId}/execute", ['confirmation_text' => '确认执行'])
            ->assertOk()
            ->assertJsonPath('data.idempotent_replay', true);

        $this->assertDatabaseCount('sync_jobs', 1);
        Bus::assertDispatchedTimes(ProcessSyncJob::class, 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'codex_sync_started', 'user_id' => $user->id]);
    }

    public function test_notification_update_returns_only_safe_flags(): void
    {
        [$user, $organization, $store] = $this->context('store-admin');
        $token = $this->token($user, $organization, ['configuration:write']);
        $secret = 'smtp-secret-never-return';
        StoreNotificationSetting::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'mail_enabled' => false,
            'mail_host' => 'smtp.example.com',
            'mail_from_address' => 'robot@example.com',
            'mail_recipients' => ['ops@example.com'],
            'mail_password' => $secret,
        ]);
        $prepared = $this->withToken($token)
            ->postJson("/api/codex/v1/stores/{$store->id}/actions/prepare/store-notifications", [
                'mail_enabled' => true,
                'notify_sync_failed' => false,
                'idempotency_key' => 'notification-safe-001',
            ])->assertStatus(202);

        $this->withToken($token)
            ->postJson('/api/codex/v1/actions/'.$prepared->json('data.confirmation.id').'/execute', [
                'confirmation_text' => '确认执行',
            ])->assertOk()
            ->assertJsonPath('data.result.settings.mail_enabled', true)
            ->assertJsonPath('data.result.settings.notify_sync_failed', false)
            ->assertDontSee($secret)
            ->assertJsonMissingPath('data.result.settings.mail_password');
    }

    public function test_failed_sync_can_be_retried_once_after_confirmation(): void
    {
        Bus::fake([ProcessSyncJob::class]);
        [$user, $organization, $store] = $this->context('organization-admin');
        $token = $this->token($user, $organization, ['sync:write']);
        $failed = SyncJob::query()->create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'type' => 'inventory',
            'direction' => 'pull',
            'mode' => 'full',
            'status' => 'failed',
            'failed_at' => now(),
        ]);
        $prepared = $this->withToken($token)
            ->postJson("/api/codex/v1/stores/{$store->id}/actions/prepare/sync-retry", [
                'sync_job_id' => $failed->id,
                'idempotency_key' => 'sync-retry-001',
            ])->assertStatus(202);

        $this->withToken($token)
            ->postJson('/api/codex/v1/actions/'.$prepared->json('data.confirmation.id').'/execute', [
                'confirmation_text' => '确认执行',
            ])->assertOk()
            ->assertJsonPath('data.result.action', 'retry_sync')
            ->assertJsonPath('data.result.sync_job.type', 'inventory')
            ->assertJsonPath('data.result.sync_job.status', 'queued');
        $this->withToken($token)
            ->postJson('/api/codex/v1/actions/'.$prepared->json('data.confirmation.id').'/execute', [
                'confirmation_text' => '确认执行',
            ])->assertOk()
            ->assertJsonPath('data.idempotent_replay', true);

        $this->assertDatabaseCount('sync_jobs', 2);
        $this->assertNotNull(data_get($failed->fresh()->payload, 'retry_job_id'));
        Bus::assertDispatchedTimes(ProcessSyncJob::class, 1);
    }

    public function test_student_discount_status_uses_its_own_permission(): void
    {
        [$operator, $operatorOrganization, $operatorStore] = $this->context('operator', 'operator-org');
        $operatorToken = $this->token($operator, $operatorOrganization, ['configuration:write']);
        $this->withToken($operatorToken)
            ->postJson("/api/codex/v1/stores/{$operatorStore->id}/actions/prepare/student-discount-status", [
                'enabled' => true,
                'idempotency_key' => 'student-operator-001',
            ])->assertForbidden()
            ->assertJsonPath('error.code', 'codex_rbac_forbidden');

        [$admin, $organization, $store] = $this->context('store-admin', 'student-admin-org');
        $token = $this->token($admin, $organization, ['configuration:write']);
        $prepared = $this->withToken($token)
            ->postJson("/api/codex/v1/stores/{$store->id}/actions/prepare/student-discount-status", [
                'enabled' => true,
                'idempotency_key' => 'student-admin-001',
            ])->assertStatus(202);
        $this->withToken($token)
            ->postJson('/api/codex/v1/actions/'.$prepared->json('data.confirmation.id').'/execute', [
                'confirmation_text' => '确认执行',
            ])->assertOk()
            ->assertJsonPath('data.result.enabled', true);
        $this->assertDatabaseHas('student_discount_campaigns', ['store_id' => $store->id, 'enabled' => true]);
    }

    public function test_another_user_cannot_execute_someone_elses_confirmation(): void
    {
        [$owner, $organization, $store] = $this->context('organization-admin');
        $ownerToken = $this->token($owner, $organization, ['analytics:write']);
        $prepared = $this->withToken($ownerToken)
            ->postJson("/api/codex/v1/stores/{$store->id}/actions/prepare/analytics-refresh", [
                'idempotency_key' => 'owner-only-001',
            ])->assertStatus(202);

        $otherUser = User::factory()->create();
        $organization->users()->attach($otherUser, ['status' => 'active', 'joined_at' => now()]);
        $store->members()->attach($otherUser, ['status' => 'active', 'joined_at' => now()]);
        $role = Role::query()->whereBelongsTo($organization)->where('slug', 'organization-admin')->firstOrFail();
        $otherUser->roles()->attach($role, ['organization_id' => $organization->id, 'store_id' => null]);
        $otherToken = $this->token($otherUser, $organization, ['analytics:write']);

        $this->withToken($otherToken)
            ->postJson('/api/codex/v1/actions/'.$prepared->json('data.confirmation.id').'/execute', [
                'confirmation_text' => '确认执行',
            ])->assertNotFound()
            ->assertJsonPath('error.code', 'codex_confirmation_not_found');
    }

    /** @return array{0: User, 1: Organization, 2: Store, 3: Role} */
    private function context(string $roleSlug, string $organizationCode = 'decomkt'): array
    {
        $this->seed(PermissionSeeder::class);
        $user = User::factory()->create();
        $organization = Organization::query()->create([
            'name' => Str::headline($organizationCode),
            'code' => $organizationCode,
        ]);
        $organization->users()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $store = $organization->stores()->create([
            'name' => 'Macfox Bike '.$organizationCode,
            'shopify_domain' => "{$organizationCode}.myshopify.com",
            'status' => 'active',
        ]);
        $store->members()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $this->seed(RoleSeeder::class);
        $role = Role::query()->whereBelongsTo($organization)->where('slug', $roleSlug)->firstOrFail();
        $user->roles()->attach($role, ['organization_id' => $organization->id, 'store_id' => null]);

        return [$user, $organization, $store, $role];
    }

    /** @param list<string> $abilities */
    private function token(User $user, Organization $organization, array $abilities): string
    {
        return CodexApiToken::issue(
            $user,
            $organization,
            'Codex write test token',
            $abilities,
            30,
        )['plain_text_token'];
    }
}
