<?php

namespace Tests\Feature;

use App\Jobs\ProcessSyncJob;
use App\Models\App;
use App\Models\AppInstallation;
use App\Models\Organization;
use App\Models\ShopifyConnection;
use App\Models\StoreAlert;
use App\Models\StoreSyncState;
use App\Models\SyncJob;
use App\Models\User;
use App\Services\Shopify\Sync\ScheduledShopifySyncService;
use App\Services\Shopify\Sync\SyncDataConsistencyService;
use App\Services\Shopify\Sync\SyncResult;
use App\Services\Sync\SyncJobService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class ShopifySyncReliabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_first_incremental_request_bootstraps_full_then_uses_watermark_window(): void
    {
        Queue::fake();
        CarbonImmutable::setTestNow('2026-08-19 12:00:00 UTC');
        $installation = $this->installation(['read_customers']);
        $scheduled = app(ScheduledShopifySyncService::class);
        $jobs = app(SyncJobService::class);

        $first = $scheduled->dispatch($installation->store_id, 'incremental');
        $fullJob = SyncJob::query()->sole();

        $this->assertSame(1, $first['created']);
        $this->assertSame('full', $fullJob->mode);
        $this->assertNull($fullJob->since_at);
        $this->assertSame([], $fullJob->payload['filters']);
        $this->assertSame('queued', StoreSyncState::query()->sole()->status);

        $running = $jobs->markRunning($fullJob->id);
        $this->assertNotNull($running);
        $jobs->markCompleted($running, SyncResult::successful('首次全量同步完成。'));
        $state = StoreSyncState::query()->sole();

        $this->assertSame('idle', $state->status);
        $this->assertNotNull($state->last_full_sync_at);
        $this->assertTrue($state->watermark_at->equalTo($fullJob->until_at));

        CarbonImmutable::setTestNow('2026-08-19 12:16:00 UTC');
        $second = $scheduled->dispatch($installation->store_id, 'incremental');
        $incrementalJob = SyncJob::query()->latest('id')->firstOrFail();

        $this->assertSame(1, $second['created']);
        $this->assertSame('incremental', $incrementalJob->mode);
        $this->assertTrue($incrementalJob->since_at->equalTo($state->watermark_at->copy()->subMinutes(5)));
        $this->assertSame(
            $incrementalJob->since_at->utc()->toIso8601String(),
            $incrementalJob->payload['filters']['updated_at_from'],
        );
        $this->assertSame(
            $incrementalJob->until_at->utc()->toIso8601String(),
            $incrementalJob->payload['filters']['updated_at_to'],
        );

        $duplicate = $scheduled->dispatch($installation->store_id, 'incremental');
        $this->assertSame(0, $duplicate['created']);
        $this->assertSame(1, $duplicate['duplicate']);
        $this->assertSame(2, SyncJob::query()->count());
        Queue::assertPushed(ProcessSyncJob::class, 2);
    }

    public function test_failure_updates_store_state_with_stable_code_and_redacted_error(): void
    {
        Queue::fake();
        $installation = $this->installation(['read_products', 'read_inventory']);
        $actor = User::query()->findOrFail($installation->installed_by);
        $service = app(SyncJobService::class);
        $job = $service->createAndDispatch($installation->store, 'products', $actor, $installation);
        $running = $service->markRunning($job->id);

        $this->assertNotNull($running);
        $service->markFailed(
            $running,
            'Remote failure access_token=secret-token client_secret=secret-value',
            errorCode: 'shopify_auth_invalid',
        );

        $job->refresh();
        $state = StoreSyncState::query()->sole();

        $this->assertSame('failed', $job->status);
        $this->assertSame('shopify_auth_invalid', $job->error_code);
        $this->assertStringNotContainsString('secret-token', $job->last_error);
        $this->assertStringNotContainsString('secret-value', $job->last_error);
        $this->assertSame('failed', $state->status);
        $this->assertSame(1, $state->consecutive_failures);
        $this->assertSame('shopify_auth_invalid', $state->last_error_code);
        $this->assertTrue($state->next_sync_at->isFuture());
    }

    public function test_full_sync_count_mismatch_creates_sanitized_linked_alert(): void
    {
        Queue::fake();
        $installation = $this->installation(['read_products', 'read_inventory']);
        $actor = User::query()->findOrFail($installation->installed_by);
        $job = app(SyncJobService::class)->createAndDispatch(
            $installation->store,
            'products',
            $actor,
            $installation,
        );

        $result = app(SyncDataConsistencyService::class)->inspect(
            $job->load('store'),
            SyncResult::successful('Shopify 返回 3 条商品。', 3),
        );
        $alert = StoreAlert::query()->sole();

        $this->assertFalse($result->metadata['consistency']['consistent']);
        $this->assertSame(3, $result->metadata['consistency']['difference']);
        $this->assertSame($installation->store_id, $alert->store_id);
        $this->assertSame($job->id, $alert->source_id);
        $this->assertSame('data_count_mismatch', $alert->code);
        $this->assertSame($job->uuid, $alert->context['job_uuid']);
        $this->assertSame($job->correlation_id, $alert->context['correlation_id']);
        $this->assertStringNotContainsString('token', mb_strtolower(json_encode($alert->toArray()) ?: ''));
    }

    /** @param list<string> $scopes */
    private function installation(array $scopes): AppInstallation
    {
        $user = User::factory()->create();
        $organization = Organization::query()->create(['name' => 'Macfox', 'code' => 'macfox']);
        $store = $organization->stores()->create([
            'name' => 'Macfox US',
            'shopify_domain' => 'macfox-us.myshopify.com',
            'status' => 'active',
        ]);
        $app = App::query()->create([
            'name' => 'Shopify Commerce Hub',
            'handle' => 'shopify-commerce-hub',
            'client_id' => Str::uuid()->toString(),
            'client_secret_encrypted' => 'test-secret',
            'distribution' => 'custom',
            'status' => 'active',
        ]);
        $connection = ShopifyConnection::query()->create([
            'store_id' => $store->id,
            'shop_domain' => $store->shopify_domain,
            'access_token_encrypted' => 'test-token',
            'token_type' => 'offline',
            'scopes' => $scopes,
            'api_version' => '2026-07',
            'status' => 'connected',
        ]);

        return AppInstallation::query()->create([
            'app_id' => $app->id,
            'store_id' => $store->id,
            'shopify_connection_id' => $connection->id,
            'installed_by' => $user->id,
            'status' => 'active',
            'granted_scopes' => $scopes,
            'installed_at' => now(),
        ]);
    }
}
