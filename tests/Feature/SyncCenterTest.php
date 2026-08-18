<?php

namespace Tests\Feature;

use App\Contracts\Shopify\SyncHandlerInterface;
use App\Jobs\ProcessSyncJob;
use App\Models\App;
use App\Models\AppInstallation;
use App\Models\Organization;
use App\Models\Role;
use App\Models\ShopifyConnection;
use App\Models\Store;
use App\Models\SyncJob;
use App\Models\User;
use App\Services\Shopify\Sync\SyncHandlerRegistry;
use App\Services\Shopify\Sync\SyncProcessor;
use App\Services\Shopify\Sync\SyncResult;
use App\Services\Sync\SyncJobService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

class SyncCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_organization_admin_can_create_queued_sync_job(): void
    {
        Queue::fake();
        [$admin, $organization, $store, $installation] = $this->context('organization-admin');

        $response = $this->actingAs($admin)
            ->withSession($this->contextSession($organization, $store))
            ->post(route('sync.store'), [
                'store_id' => $store->id,
                'app_installation_id' => $installation->id,
                'type' => 'products',
            ]);

        $syncJob = SyncJob::query()->sole();
        $response->assertRedirect(route('sync.show', $syncJob));
        $this->assertSame('queued', $syncJob->status);
        $this->assertSame($organization->id, $syncJob->organization_id);
        $this->assertSame($store->id, $syncJob->store_id);
        $this->assertSame($installation->id, $syncJob->app_installation_id);
        $this->assertSame('manual', $syncJob->payload['source']);
        Queue::assertPushed(ProcessSyncJob::class, fn (ProcessSyncJob $job) => $job->syncJobId === $syncJob->id
            && $job->queue === 'shopify-sync');
    }

    public function test_sync_job_lifecycle_moves_from_queued_to_running_and_completed(): void
    {
        [, $organization, $store, $installation] = $this->context('store-admin');
        $syncJob = $this->syncJob($organization, $store, $installation, 'queued');
        $service = app(SyncJobService::class);

        $running = $service->markRunning($syncJob->id);
        $this->assertNotNull($running);
        $this->assertSame('running', $running->status);
        $this->assertSame(1, $running->attempts);
        $this->assertNotNull($running->started_at);

        $service->markCompleted($running);
        $syncJob->refresh();

        $this->assertSame('completed', $syncJob->status);
        $this->assertNotNull($syncJob->finished_at);
        $this->assertNotNull($syncJob->completed_at);
        $this->assertNull($syncJob->last_error);
        $this->assertTrue($syncJob->result['metadata']['framework_only']);
        $this->assertCount(2, $syncJob->logs);
    }

    public function test_processing_job_uses_framework_without_shopify_data_sync(): void
    {
        [, $organization, $store, $installation] = $this->context('store-admin');
        $syncJob = $this->syncJob($organization, $store, $installation, 'queued', 'inventory');
        $handler = new class implements SyncHandlerInterface
        {
            public function type(): string
            {
                return 'inventory';
            }

            public function handle(SyncJob $syncJob): SyncResult
            {
                return SyncResult::successful('Framework test completed.', metadata: [
                    'framework_only' => true,
                ]);
            }
        };
        $processor = new SyncProcessor(
            new SyncHandlerRegistry([$handler]),
            app(SyncJobService::class),
        );

        (new ProcessSyncJob($syncJob->id))->handle($processor);
        $syncJob->refresh();

        $this->assertSame('completed', $syncJob->status);
        $this->assertSame(1, $syncJob->attempts);
        $this->assertSame(0, $syncJob->total_items);
        $this->assertSame(0, $syncJob->processed_items);
        $this->assertSame(0, $syncJob->failed_items);
        $this->assertTrue($syncJob->result['metadata']['framework_only']);
    }

    public function test_processing_failure_marks_job_failed_and_redacts_token_values(): void
    {
        [, $organization, $store, $installation] = $this->context('store-admin');
        $syncJob = $this->syncJob($organization, $store, $installation, 'queued');
        $handler = new class implements SyncHandlerInterface
        {
            public function type(): string
            {
                return 'products';
            }

            public function handle(SyncJob $syncJob): SyncResult
            {
                throw new RuntimeException('Remote failure access_token=secret-token client_secret=secret-value');
            }
        };
        $processor = new SyncProcessor(new SyncHandlerRegistry([$handler]), app(SyncJobService::class));

        try {
            (new ProcessSyncJob($syncJob->id))->handle($processor);
            $this->fail('Expected queue processing to fail.');
        } catch (RuntimeException) {
            // Expected: the job rethrows so Horizon can retry it.
        }

        $syncJob->refresh();
        $this->assertSame('failed', $syncJob->status);
        $this->assertNotNull($syncJob->failed_at);
        $this->assertNotNull($syncJob->finished_at);
        $this->assertStringNotContainsString('secret-token', $syncJob->last_error);
        $this->assertStringNotContainsString('secret-value', $syncJob->last_error);
        $this->assertStringContainsString('[redacted]', $syncJob->last_error);
        $this->assertSame('error', $syncJob->logs[array_key_last($syncJob->logs)]['level']);
    }

    public function test_sync_jobs_are_isolated_by_organization_and_store_scope(): void
    {
        [$admin, $organization, $store, $installation] = $this->context('organization-admin');
        $visible = $this->syncJob($organization, $store, $installation, 'completed');
        $otherOrganization = Organization::query()->create(['name' => 'Asiwo', 'code' => 'asiwo']);
        $otherStore = $otherOrganization->stores()->create([
            'name' => 'Asiwo US',
            'shopify_domain' => 'asiwo-us.myshopify.com',
            'status' => 'active',
        ]);
        $hidden = $this->syncJob($otherOrganization, $otherStore, null, 'completed');

        $this->actingAs($admin)
            ->withSession($this->contextSession($organization, $store))
            ->get(route('sync.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Sync/Index')
                ->has('syncJobs.data', 1)
                ->where('syncJobs.data.0.id', $visible->id));

        $this->actingAs($admin)
            ->withSession($this->contextSession($organization, $store))
            ->get(route('sync.show', $hidden))
            ->assertForbidden();
    }

    public function test_store_admin_only_sees_authorized_store_jobs(): void
    {
        [$admin, $organization, $store, $installation] = $this->context('store-admin');
        $visible = $this->syncJob($organization, $store, $installation, 'completed');
        $hiddenStore = $organization->stores()->create([
            'name' => 'Macfox EU',
            'shopify_domain' => 'macfox-eu.myshopify.com',
            'status' => 'active',
        ]);
        $hidden = $this->syncJob($organization, $hiddenStore, null, 'completed');

        $this->actingAs($admin)
            ->withSession($this->contextSession($organization, $store))
            ->get(route('sync.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('syncJobs.data', 1)
                ->where('syncJobs.data.0.id', $visible->id)
                ->has('stores', 1)
                ->where('stores.0.id', $store->id));

        $this->actingAs($admin)
            ->withSession($this->contextSession($organization, $store))
            ->get(route('sync.show', $hidden))
            ->assertForbidden();
    }

    public function test_viewer_cannot_access_or_create_sync_jobs(): void
    {
        [$viewer, $organization, $store, $installation] = $this->context('viewer');
        $syncJob = $this->syncJob($organization, $store, $installation, 'completed');

        $this->actingAs($viewer)
            ->withSession($this->contextSession($organization, $store))
            ->get(route('sync.index'))
            ->assertForbidden();
        $this->actingAs($viewer)
            ->withSession($this->contextSession($organization, $store))
            ->get(route('sync.show', $syncJob))
            ->assertForbidden();
        $this->actingAs($viewer)
            ->withSession($this->contextSession($organization, $store))
            ->post(route('sync.store'), ['store_id' => $store->id, 'type' => 'orders'])
            ->assertForbidden();
    }

    /** @return array{0: User, 1: Organization, 2: Store, 3: AppInstallation} */
    private function context(string $roleSlug): array
    {
        $this->seed(PermissionSeeder::class);
        $user = User::factory()->create();
        $organization = Organization::query()->create(['name' => 'Macfox', 'code' => 'macfox']);
        $organization->users()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $store = $organization->stores()->create([
            'name' => 'Macfox US',
            'shopify_domain' => 'macfox-us.myshopify.com',
            'status' => 'active',
        ]);
        $store->members()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $this->seed(RoleSeeder::class);
        $role = Role::query()->whereBelongsTo($organization)->where('slug', $roleSlug)->firstOrFail();
        $user->roles()->attach($role, ['organization_id' => $organization->id, 'store_id' => null]);
        $app = App::query()->create([
            'name' => 'Shopify Commerce Hub',
            'handle' => 'shopify-commerce-hub',
            'client_id' => Str::uuid()->toString(),
            'client_secret_encrypted' => 'sync-test-secret',
            'distribution' => 'custom',
            'status' => 'active',
        ]);
        $connection = ShopifyConnection::query()->create([
            'store_id' => $store->id,
            'shop_domain' => $store->shopify_domain,
            'access_token_encrypted' => 'sync-test-token',
            'token_type' => 'offline',
            'scopes' => ['read_products'],
            'api_version' => '2026-07',
            'status' => 'connected',
        ]);
        $installation = AppInstallation::query()->create([
            'app_id' => $app->id,
            'store_id' => $store->id,
            'shopify_connection_id' => $connection->id,
            'installed_by' => $user->id,
            'status' => 'active',
            'granted_scopes' => ['read_products'],
            'installed_at' => now(),
        ]);

        return [$user, $organization, $store, $installation];
    }

    private function syncJob(
        Organization $organization,
        Store $store,
        ?AppInstallation $installation,
        string $status,
        string $type = 'products',
    ): SyncJob {
        return SyncJob::query()->create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'app_id' => $installation?->app_id,
            'app_installation_id' => $installation?->id,
            'type' => $type,
            'direction' => 'pull',
            'status' => $status,
            'logs' => [],
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
