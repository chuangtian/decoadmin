<?php

namespace Tests\Feature;

use App\Jobs\ProcessSyncJob;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Store;
use App\Models\SyncJob;
use App\Models\User;
use App\Models\WebhookEvent;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StoreOperationsTabsTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_detail_exposes_scoped_sync_webhook_and_log_data(): void
    {
        [$admin, $organization, $store] = $this->context('organization-admin');
        $otherStore = $this->store($organization, 'Macfox EU', 'macfox-eu.myshopify.com');
        $otherStore->members()->attach($admin, ['status' => 'active', 'joined_at' => now()]);

        $syncJob = $this->syncJob($organization, $store, 'failed');
        $this->syncJob($organization, $otherStore, 'completed');
        $webhook = $this->webhook($organization, $store, 'failed');
        $this->webhook($organization, $otherStore, 'processed');
        $audit = AuditLog::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'user_id' => $admin->id,
            'action' => 'shopify_connection_warning',
        ]);
        AuditLog::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $otherStore->id,
            'action' => 'shopify_connection_connected',
        ]);

        $this->actingAs($admin)
            ->withSession($this->contextSession($organization, $store))
            ->get(route('stores.show', ['store' => $store, 'tab' => 'sync']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Stores/Show')
                ->where('operations.capabilities.sync_view', true)
                ->where('operations.capabilities.sync_run', true)
                ->where('operations.capabilities.webhooks_view', true)
                ->where('operations.capabilities.audit_view', true)
                ->where('operations.sync.summary.total', 1)
                ->has('operations.sync.jobs', 1)
                ->where('operations.sync.jobs.0.id', $syncJob->id)
                ->where('operations.sync.jobs.0.can_retry', true)
                ->where('operations.webhooks.summary.total', 1)
                ->has('operations.webhooks.events', 1)
                ->where('operations.webhooks.events.0.id', $webhook->id)
                ->where('operations.webhooks.events.0.can_retry', true)
                ->where('operations.logs.summary.operations', 1)
                ->has('operations.logs.items', 3)
                ->where('operations.logs.items.0.key', "audit-{$audit->id}"));
    }

    public function test_store_detail_hides_modules_without_backend_permissions(): void
    {
        [$viewer, $organization, $store] = $this->context('viewer');
        $this->syncJob($organization, $store, 'failed');
        $this->webhook($organization, $store, 'failed');
        AuditLog::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'action' => 'shopify_connection_connected',
        ]);

        $this->actingAs($viewer)
            ->withSession($this->contextSession($organization, $store))
            ->get(route('stores.show', $store))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('operations.capabilities.sync_view', false)
                ->where('operations.capabilities.webhooks_view', false)
                ->where('operations.capabilities.audit_view', true)
                ->where('operations.sync.summary.total', 0)
                ->has('operations.sync.jobs', 0)
                ->where('operations.webhooks.summary.total', 0)
                ->has('operations.webhooks.events', 0)
                ->where('operations.logs.summary.operations', 1)
                ->has('operations.logs.items', 1));
    }

    public function test_failed_sync_job_can_be_retried_once_with_audit_record(): void
    {
        Queue::fake();
        [$admin, $organization, $store] = $this->context('organization-admin');
        $failed = $this->syncJob($organization, $store, 'failed');

        $this->actingAs($admin)
            ->withSession($this->contextSession($organization, $store))
            ->post(route('sync.retry', $failed))
            ->assertRedirect(route('stores.show', ['store' => $store, 'tab' => 'sync']))
            ->assertSessionHas('success');

        $retry = SyncJob::query()->whereKeyNot($failed->id)->sole();
        $this->assertSame('queued', $retry->status);
        $this->assertSame($failed->id, data_get($retry->payload, 'retry_of_job_id'));
        $this->assertSame($retry->id, data_get($failed->fresh()->payload, 'retry_job_id'));
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'user_id' => $admin->id,
            'action' => 'shopify_sync_retried',
            'subject_id' => $failed->id,
        ]);
        Queue::assertPushed(ProcessSyncJob::class, fn (ProcessSyncJob $job): bool => $job->syncJobId === $retry->id);

        $this->actingAs($admin)
            ->withSession($this->contextSession($organization, $store))
            ->post(route('sync.retry', $failed))
            ->assertSessionHasErrors('sync_job');
        $this->assertDatabaseCount('sync_jobs', 2);
    }

    /** @return array{0: User, 1: Organization, 2: Store} */
    private function context(string $roleSlug): array
    {
        $this->seed(PermissionSeeder::class);
        $user = User::factory()->create();
        $organization = Organization::query()->create(['name' => 'Macfox', 'code' => 'macfox']);
        $organization->users()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $store = $this->store($organization, 'Macfox US', 'macfox-us.myshopify.com');
        $store->members()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $this->seed(RoleSeeder::class);
        $role = Role::query()->whereBelongsTo($organization)->where('slug', $roleSlug)->firstOrFail();
        $user->roles()->attach($role, ['organization_id' => $organization->id, 'store_id' => null]);

        return [$user, $organization, $store];
    }

    private function store(Organization $organization, string $name, string $domain): Store
    {
        return $organization->stores()->create([
            'name' => $name,
            'shopify_domain' => $domain,
            'status' => 'active',
        ]);
    }

    private function syncJob(Organization $organization, Store $store, string $status): SyncJob
    {
        return SyncJob::query()->create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'type' => 'products',
            'direction' => 'pull',
            'mode' => 'full',
            'status' => $status,
            'payload' => [],
            'logs' => [],
            'error_code' => $status === 'failed' ? 'shopify_api_error' : null,
            'failed_at' => $status === 'failed' ? now()->subMinute() : null,
            'finished_at' => now()->subMinute(),
        ]);
    }

    private function webhook(Organization $organization, Store $store, string $status): WebhookEvent
    {
        return WebhookEvent::query()->create([
            'webhook_id' => (string) Str::uuid(),
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'topic' => 'orders/create',
            'api_version' => '2026-07',
            'headers' => [],
            'payload' => [],
            'status' => $status,
            'attempts' => 1,
            'received_at' => now()->subMinutes(2),
            'processed_at' => now()->subMinute(),
        ]);
    }

    /** @return array{current_organization_id: int, current_store_id: int} */
    private function contextSession(Organization $organization, Store $store): array
    {
        return [
            'current_organization_id' => $organization->id,
            'current_store_id' => $store->id,
        ];
    }
}
