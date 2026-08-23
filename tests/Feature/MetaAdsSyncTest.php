<?php

namespace Tests\Feature;

use App\Jobs\SyncMetaAdsForStore;
use App\Models\MetaAd;
use App\Models\MetaAdAccount;
use App\Models\MetaAdCampaign;
use App\Models\MetaAdCreative;
use App\Models\MetaAdInsight;
use App\Models\MetaAdSet;
use App\Models\MetaAdSyncShard;
use App\Models\Organization;
use App\Models\Store;
use App\Models\StoreBusinessCredential;
use App\Models\StoreSyncState;
use App\Models\SyncJob;
use App\Services\MetaAds\MetaAdsApiClient;
use App\Services\MetaAds\MetaAdsRateLimitService;
use App\Services\MetaAds\MetaAdsSyncService;
use App\Services\StoreBusinessCredentialService;
use Carbon\CarbonImmutable;
use Illuminate\Bus\UniqueLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MetaAdsSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.meta_ads.api_base', 'https://graph.test/v21.0');
        config()->set('services.meta_ads.page_size', 2);
        config()->set('services.meta_ads.max_pages', 10);
        config()->set('services.meta_ads.retry_delays_ms', [0, 0]);
        config()->set('services.meta_ads.insight_window_days', 31);
        config()->set('services.meta_ads.history_months', 12);
        config()->set('services.meta_ads.sync_enabled', true);
        CarbonImmutable::setTestNow('2026-08-22 12:30:00 UTC');
        $this->fakeMetaApi();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_full_then_incremental_sync_persists_paginated_meta_data_without_secrets(): void
    {
        $store = $this->configuredStore('Meta Org', 'meta-org', 'Meta Store', 'meta-store.myshopify.com', 'secret-token');
        $service = app(MetaAdsSyncService::class);

        $full = $service->sync($store, 'incremental');

        $this->assertSame('full', $full['mode']);
        $this->assertSame(1, $full['accounts']);
        $this->assertSame(2, $full['campaigns']);
        $this->assertSame(1, $full['ad_sets']);
        $this->assertSame(1, $full['ads']);
        $this->assertSame(1, $full['creatives']);
        $this->assertSame(4, $full['insights']);
        $this->assertDatabaseCount('meta_ad_accounts', 1);
        $this->assertDatabaseCount('meta_ad_campaigns', 2);
        $this->assertDatabaseCount('meta_ad_sets', 1);
        $this->assertDatabaseCount('meta_ads', 1);
        $this->assertDatabaseCount('meta_ad_creatives', 1);
        $this->assertDatabaseCount('meta_ad_insights', 4);

        $account = MetaAdAccount::query()->sole();
        $this->assertSame($store->organization_id, $account->organization_id);
        $this->assertSame($store->id, $account->store_id);
        $this->assertSame('act_100', $account->meta_account_id);
        $this->assertSame('USD', $account->raw_payload['currency']);
        $this->assertSame('Campaign page two', MetaAdCampaign::query()->where('meta_campaign_id', 'cmp_2')->value('name'));
        $this->assertDatabaseMissing('meta_ad_campaigns', ['meta_campaign_id' => 'cmp_current_partial_hour']);
        $this->assertNull(MetaAdCampaign::query()->where('meta_campaign_id', 'cmp_1')->value('start_time'));
        $this->assertSame(
            '1969-12-31T23:59:59+00:00',
            MetaAdCampaign::query()->where('meta_campaign_id', 'cmp_1')->sole()->raw_payload['start_time'],
        );
        $this->assertSame('Creative headline', MetaAdCreative::query()->sole()->title);
        $this->assertSame('act_100', MetaAdInsight::query()->where('level', 'account')->value('account_external_id'));
        $this->assertEquals(3, MetaAdInsight::query()->where('level', 'account')->value('purchases'));
        $this->assertEquals(150, MetaAdInsight::query()->where('level', 'account')->value('purchase_value'));

        $job = SyncJob::query()->sole();
        $this->assertSame('completed', $job->status);
        $this->assertSame('full', $job->mode);
        $this->assertSame('2025-08-22 00:00:00', $job->since_at?->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-22 12:00:00', $job->until_at?->utc()->format('Y-m-d H:i:s'));
        $this->assertStringNotContainsString('secret-token', json_encode($job->toArray(), JSON_THROW_ON_ERROR));
        $state = StoreSyncState::query()->sole();
        $this->assertNotNull($state->last_full_sync_at);
        $this->assertNull($state->last_incremental_sync_at);
        $this->assertSame(
            (string) MetaAdInsight::query()
                ->where('level', 'account')
                ->where('granularity', 'day')
                ->latest('date_stop')
                ->value('date_stop'),
            $state->last_metric_date?->toDateString(),
        );
        $this->assertNotNull($state->data_synced_at);

        $incremental = $service->sync($store, 'incremental');

        $this->assertSame('incremental', $incremental['mode']);
        $this->assertDatabaseCount('meta_ad_campaigns', 2);
        $this->assertDatabaseCount('meta_ad_insights', 8);
        $this->assertDatabaseCount('sync_jobs', 2);
        $this->assertDatabaseHas('meta_ad_insights', [
            'level' => 'account',
            'granularity' => 'hour',
            'hourly_range' => '04:00:00 - 04:59:59',
            'hour_start_at' => '2026-08-22 11:00:00',
            'hour_end_at' => '2026-08-22 11:59:59',
        ]);
        $incrementalJob = SyncJob::query()->latest('id')->firstOrFail();
        $this->assertSame('2026-08-22 11:00:00', $incrementalJob->since_at?->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-22 12:00:00', $incrementalJob->until_at?->utc()->format('Y-m-d H:i:s'));
        $this->assertNotNull(StoreSyncState::query()->sole()->last_incremental_sync_at);
        $this->assertTrue(Http::recorded()->contains(function (array $entry): bool {
            /** @var Request $request */
            $request = $entry[0];
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/insights')
                && isset($query['time_range'])
                && ($query['breakdowns'] ?? null) === 'hourly_stats_aggregated_by_advertiser_time_zone';
        }));
        $this->assertTrue(Http::recorded()->contains(function (array $entry): bool {
            /** @var Request $request */
            $request = $entry[0];
            if (! str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/campaigns')) {
                return false;
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $filtering = json_decode((string) ($query['filtering'] ?? ''), true);

            return ($filtering[0]['field'] ?? null) === 'updated_time'
                && ($filtering[0]['operator'] ?? null) === 'GREATER_THAN';
        }));

        Http::assertSent(function (Request $request): bool {
            $this->assertStringNotContainsString('access_token', $request->url());
            $this->assertSame('Bearer secret-token', $request->header('Authorization')[0] ?? null);

            return true;
        });
    }

    public function test_identical_meta_ids_are_isolated_by_organization_and_store(): void
    {
        $first = $this->configuredStore('First Org', 'first-meta-org', 'First Store', 'first-meta.myshopify.com', 'first-token');
        $second = $this->configuredStore('Second Org', 'second-meta-org', 'Second Store', 'second-meta.myshopify.com', 'second-token');
        $service = app(MetaAdsSyncService::class);

        $service->sync($first, 'full');
        $service->sync($second, 'full');

        $this->assertDatabaseCount('meta_ad_accounts', 2);
        $this->assertDatabaseCount('meta_ad_campaigns', 4);
        $this->assertDatabaseCount('meta_ad_sets', 2);
        $this->assertDatabaseCount('meta_ads', 2);
        $this->assertDatabaseCount('meta_ad_creatives', 2);
        $this->assertDatabaseCount('meta_ad_insights', 8);

        $expectedCounts = [
            MetaAdAccount::class => 1,
            MetaAdCampaign::class => 2,
            MetaAdSet::class => 1,
            MetaAd::class => 1,
            MetaAdCreative::class => 1,
            MetaAdInsight::class => 4,
        ];
        foreach ($expectedCounts as $model => $expectedCount) {
            $this->assertSame($expectedCount, $model::query()->forOrganization($first->organization_id)->forStore($first)->count());
            $this->assertSame($expectedCount, $model::query()->forOrganization($second->organization_id)->forStore($second)->count());
        }

        $this->assertNotSame(
            MetaAdAccount::query()->forStore($first)->sole()->id,
            MetaAdAccount::query()->forStore($second)->sole()->id,
        );
    }

    public function test_campaign_period_snapshot_is_stored_without_daily_time_increment(): void
    {
        $store = $this->configuredStore(
            'Period Org',
            'period-org',
            'Period Store',
            'period-store.myshopify.com',
            'period-token',
        );
        MetaAdAccount::query()->create([
            'organization_id' => $store->organization_id,
            'store_id' => $store->id,
            'meta_account_id' => 'act_100',
            'name' => 'Meta Account',
            'account_status' => 1,
            'currency' => 'USD',
            'raw_payload' => [],
            'last_seen_at' => now(),
            'synced_at' => now(),
        ]);

        $count = app(MetaAdsSyncService::class)->syncCampaignPeriodSnapshot(
            $store,
            'act_100',
            '2026-08-22',
            '2026-08-22',
        );

        $this->assertSame(1, $count);
        $this->assertDatabaseHas('meta_ad_insights', [
            'organization_id' => $store->organization_id,
            'store_id' => $store->id,
            'level' => 'campaign',
            'entity_id' => 'cmp_1',
            'account_external_id' => 'act_100',
            'date_start' => '2026-08-22',
            'date_stop' => '2026-08-22',
            'granularity' => 'period',
            'frequency' => 5.8,
        ]);
        $this->assertTrue(Http::recorded()->contains(function (array $entry): bool {
            /** @var Request $request */
            $request = $entry[0];
            if (! str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/act_100/insights')) {
                return false;
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $request->method() === 'GET'
                && ($query['level'] ?? null) === 'campaign'
                && ! array_key_exists('time_increment', $query);
        }));
    }

    public function test_account_pages_are_fetched_concurrently_and_rate_limits_are_retried(): void
    {
        $store = $this->configuredStore(
            'Concurrent Org',
            'concurrent-meta-org',
            'Concurrent Store',
            'concurrent-meta.myshopify.com',
            'concurrent-token',
        );
        config()->set('services.meta_ads.concurrency', 2);
        $attempts = ['act_100' => 0, 'act_200' => 0];

        $http = new HttpFactory;
        $http->fake(function (Request $request) use (&$attempts) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $accountId = str_contains($path, '/act_200/') ? 'act_200' : 'act_100';
            $attempts[$accountId]++;

            if ($accountId === 'act_100' && $attempts[$accountId] === 1) {
                return Http::response(['error' => ['code' => 4]], 403);
            }
            if ($accountId === 'act_100' && $attempts[$accountId] === 2) {
                return Http::response(['error' => ['code' => 80004]], 400);
            }

            return Http::response(['data' => [[
                'id' => "campaign-{$accountId}",
                'account_id' => $accountId,
                'name' => "Campaign {$accountId}",
            ]]]);
        });

        $received = [];
        (new MetaAdsApiClient($http, app(StoreBusinessCredentialService::class)))->eachCampaignPageForAccounts(
            $store,
            ['act_100', 'act_200'],
            function (string $accountId, array $rows) use (&$received): void {
                $received[$accountId] = $rows[0]['account_id'] ?? null;
            },
        );

        ksort($received);
        $this->assertSame(['act_100' => 'act_100', 'act_200' => 'act_200'], $received);
        $this->assertSame(['act_100' => 3, 'act_200' => 1], $attempts);
        $http->assertSentCount(4);
    }

    public function test_server_errors_reduce_only_the_failed_page_size(): void
    {
        $store = $this->configuredStore(
            'Adaptive Org',
            'adaptive-meta-org',
            'Adaptive Store',
            'adaptive-meta.myshopify.com',
            'adaptive-token',
        );
        config()->set('services.meta_ads.page_size', 500);
        config()->set('services.meta_ads.ad_set_page_size', 500);
        config()->set('services.meta_ads.min_page_size', 25);
        $limits = [];
        $http = new HttpFactory;
        $http->fake(function (Request $request) use (&$limits) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $limit = (int) ($query['limit'] ?? 0);
            $limits[] = $limit;

            if ($limit > 100) {
                return Http::response(['error' => ['code' => 1]], 500);
            }

            return Http::response(['data' => [[
                'id' => 'set-adaptive',
                'account_id' => 'act_adaptive',
                'name' => 'Adaptive ad set',
            ]]]);
        });

        $received = [];
        (new MetaAdsApiClient($http, app(StoreBusinessCredentialService::class)))->eachAdSetPageForAccounts(
            $store,
            ['act_adaptive'],
            function (string $accountId, array $rows) use (&$received): void {
                $received[$accountId] = $rows[0]['id'] ?? null;
            },
        );

        $this->assertSame([500, 100], $limits);
        $this->assertSame(['act_adaptive' => 'set-adaptive'], $received);
    }

    public function test_command_queues_only_active_configured_stores_and_schedules_hourly_insights_and_daily_structure(): void
    {
        Queue::fake();
        $configured = $this->configuredStore('Queue Org', 'queue-meta-org', 'Configured Store', 'configured-meta.myshopify.com', 'queue-token');
        $missing = $configured->organization->stores()->create([
            'name' => 'Missing Store',
            'shopify_domain' => 'missing-meta.myshopify.com',
            'status' => 'active',
        ]);
        $inactive = $this->configuredStore('Inactive Org', 'inactive-meta-org', 'Inactive Store', 'inactive-meta.myshopify.com', 'inactive-token');
        $inactive->update(['status' => 'inactive']);

        $this->artisan('meta-ads:sync --mode=incremental')
            ->expectsOutputToContain('已提交 1 个店铺')
            ->assertSuccessful();

        Queue::assertPushed(SyncMetaAdsForStore::class, 1);
        Queue::assertPushed(fn (SyncMetaAdsForStore $job): bool => $job->organizationId === $configured->organization_id
            && $job->storeId === $configured->id
            && $job->mode === 'incremental');
        Queue::assertNotPushed(
            SyncMetaAdsForStore::class,
            fn (SyncMetaAdsForStore $job): bool => in_array($job->storeId, [$missing->id, $inactive->id], true),
        );

        // Queue::fake does not release ShouldBeUnique locks because the first
        // job is never handled. Release that exact test job lock before
        // exercising the independent daily command.
        app(UniqueLock::class)->release(Queue::pushed(SyncMetaAdsForStore::class)->first());
        $this->artisan('meta-ads:sync --mode=structure')
            ->expectsOutputToContain('已提交 1 个店铺')
            ->assertSuccessful();

        Queue::assertPushed(fn (SyncMetaAdsForStore $job): bool => $job->organizationId === $configured->organization_id
            && $job->storeId === $configured->id
            && $job->mode === 'structure');

        $this->artisan('schedule:list')
            ->expectsOutputToContain('meta-ads:sync --mode=incremental')
            ->expectsOutputToContain('meta-ads:sync --mode=structure')
            ->assertSuccessful();
    }

    public function test_sharded_full_sync_resumes_without_repeating_completed_checkpoints(): void
    {
        $store = $this->configuredStore(
            'Sharded Org',
            'sharded-meta-org',
            'Sharded Store',
            'sharded-meta.myshopify.com',
            'sharded-token',
        );
        $service = app(MetaAdsSyncService::class);

        $run = $service->orchestrate($store, 'full');

        $this->assertFalse($run['resumed']);
        $this->assertSame('full', $run['mode']);
        $this->assertSame(7, count($run['shard_ids']));
        $this->assertDatabaseCount('meta_ad_sync_shards', count($run['shard_ids']));
        $campaignShard = MetaAdSyncShard::query()->where('kind', 'campaigns')->firstOrFail();
        $service->runShard($campaignShard);

        $resumed = $service->orchestrate($store, 'full');

        $this->assertTrue($resumed['resumed']);
        $this->assertSame($run['sync_job_id'], $resumed['sync_job_id']);
        $this->assertNotContains($campaignShard->id, $resumed['shard_ids']);
        $this->assertDatabaseHas('meta_ad_sync_shards', [
            'id' => $campaignShard->id,
            'status' => 'completed',
        ]);

        MetaAdSyncShard::query()
            ->where('sync_job_id', $run['sync_job_id'])
            ->where('status', 'queued')
            ->each(function (MetaAdSyncShard $shard) use ($service): void {
                $result = $service->runShard($shard);
                if ((int) ($result['async_pending'] ?? 0) === 1) {
                    $service->runShard($shard->fresh());
                }
            });

        $this->assertTrue($service->finalizeShardedSync($run['sync_job_id']));
        $this->assertSame('completed', SyncJob::query()->findOrFail($run['sync_job_id'])->status);
        $this->assertNotNull(StoreSyncState::query()->sole()->last_full_sync_at);
        $this->assertTrue(Http::recorded()->contains(function (array $entry): bool {
            /** @var Request $request */
            $request = $entry[0];

            return $request->method() === 'POST'
                && str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/act_100/insights');
        }));
    }

    public function test_priority_sync_makes_seven_days_ready_then_queues_backfill_and_hourly_run_reconciles_three_days(): void
    {
        Queue::fake();
        config()->set('services.meta_ads.history_months', 6);
        config()->set('services.meta_ads.priority_days', 7);
        config()->set('services.meta_ads.rolling_days', 3);
        $store = $this->configuredStore(
            'Phased Org',
            'phased-meta-org',
            'Phased Store',
            'phased-meta.myshopify.com',
            'phased-token',
        );
        $service = app(MetaAdsSyncService::class);
        $priority = $service->orchestrate($store, 'priority');
        $priorityJob = SyncJob::query()->findOrFail($priority['sync_job_id']);

        $this->assertSame('2026-08-16 00:00:00', $priorityJob->since_at?->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-22 12:00:00', $priorityJob->until_at?->utc()->format('Y-m-d H:i:s'));
        MetaAdSyncShard::query()->where('sync_job_id', $priorityJob->id)->each(function (MetaAdSyncShard $shard) use ($service): void {
            $result = $service->runShard($shard);
            if ((int) ($result['async_pending'] ?? 0) === 1) {
                $service->runShard($shard->fresh());
            }
        });
        $this->assertTrue($service->finalizeShardedSync($priorityJob->id));
        Queue::assertPushed(SyncMetaAdsForStore::class, fn (SyncMetaAdsForStore $job): bool => $job->mode === 'backfill'
            && $job->storeId === $store->id);
        $this->assertNull(StoreSyncState::query()->sole()->last_full_sync_at);
        $this->assertNotNull(StoreSyncState::query()->sole()->last_success_at);

        $incremental = $service->orchestrate($store, 'incremental');
        $incrementalJob = SyncJob::query()->findOrFail($incremental['sync_job_id']);
        $this->assertSame('2026-08-20 00:00:00', $incrementalJob->since_at?->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(8, MetaAdSyncShard::query()->where('sync_job_id', $incrementalJob->id)->count());
        $this->assertSame(0, MetaAdSyncShard::query()
            ->where('sync_job_id', $incrementalJob->id)
            ->whereIn('kind', ['campaigns', 'ad_sets', 'ads'])
            ->count());
        $this->assertSame(4, MetaAdSyncShard::query()
            ->where('sync_job_id', $incrementalJob->id)
            ->where('mode', 'reconcile')
            ->count());
        $this->assertTrue(MetaAdSyncShard::query()
            ->where('sync_job_id', $incrementalJob->id)
            ->where('mode', 'incremental')
            ->where('kind', 'insights')
            ->get()
            ->every(fn (MetaAdSyncShard $shard): bool => $shard->since_at?->utc()->format('Y-m-d H:i:s') === '2026-08-22 11:00:00'));

        $structure = $service->orchestrate($store, 'structure');
        $structureJob = SyncJob::query()->findOrFail($structure['sync_job_id']);
        $this->assertSame('2026-08-20 00:00:00', $structureJob->since_at?->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(3, MetaAdSyncShard::query()->where('sync_job_id', $structureJob->id)->count());
        $this->assertSame(0, MetaAdSyncShard::query()
            ->where('sync_job_id', $structureJob->id)
            ->where('kind', 'insights')
            ->count());
        $this->assertEqualsCanonicalizing(
            ['campaigns', 'ad_sets', 'ads'],
            MetaAdSyncShard::query()
                ->where('sync_job_id', $structureJob->id)
                ->pluck('kind')
                ->all(),
        );
    }

    public function test_manual_incremental_sync_only_queues_the_previous_hour_without_reconciliation(): void
    {
        $store = $this->configuredStore(
            'Manual Hour Org',
            'manual-hour-meta-org',
            'Manual Hour Store',
            'manual-hour-meta.myshopify.com',
            'manual-hour-token',
        );
        StoreSyncState::query()->create([
            'organization_id' => $store->organization_id,
            'store_id' => $store->id,
            'sync_type' => MetaAdsSyncService::SYNC_TYPE,
            'status' => 'completed',
            'last_success_at' => now()->subHour(),
            'next_sync_at' => now()->addHour(),
        ]);
        MetaAdAccount::query()->create([
            'organization_id' => $store->organization_id,
            'store_id' => $store->id,
            'meta_account_id' => 'act_manual_hour',
            'name' => 'Manual Hour Account',
            'raw_payload' => [],
            'last_seen_at' => now(),
            'synced_at' => now(),
        ]);

        $run = app(MetaAdsSyncService::class)->orchestrate(
            $store,
            'incremental',
            null,
            false,
        );
        $job = SyncJob::query()->findOrFail($run['sync_job_id']);

        $this->assertSame('2026-08-22 11:00:00', $job->since_at?->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-22 12:00:00', $job->until_at?->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(4, MetaAdSyncShard::query()->where('sync_job_id', $job->id)->count());
        $this->assertSame(0, MetaAdSyncShard::query()
            ->where('sync_job_id', $job->id)
            ->whereIn('kind', ['campaigns', 'ad_sets', 'ads'])
            ->count());
        $this->assertSame(0, MetaAdSyncShard::query()
            ->where('sync_job_id', $job->id)
            ->where('mode', 'reconcile')
            ->count());
        $this->assertSame(4, MetaAdSyncShard::query()
            ->where('sync_job_id', $job->id)
            ->where('mode', 'incremental')
            ->where('kind', 'insights')
            ->count());
    }

    public function test_failed_large_async_report_is_replaced_with_bounded_date_shards(): void
    {
        $store = $this->configuredStore(
            'Recovery Org',
            'recovery-meta-org',
            'Recovery Store',
            'recovery-meta.myshopify.com',
            'recovery-token',
        );
        $service = app(MetaAdsSyncService::class);
        $run = $service->orchestrate($store, 'full');
        $shard = MetaAdSyncShard::query()
            ->where('kind', 'insights')
            ->where('level', 'account')
            ->sole();

        $pending = $service->runShard($shard);
        $this->assertSame(1, $pending['async_pending']);

        $http = new HttpFactory;
        $http->fake(fn () => Http::response([
            'async_status' => 'Job Failed',
            'async_percent_completion' => 0,
        ]));
        $recoveryService = new MetaAdsSyncService(new MetaAdsApiClient(
            $http,
            app(StoreBusinessCredentialService::class),
        ));
        $recovered = $recoveryService->runShard($shard->fresh());
        $replacementIds = $recovered['replacement_shard_ids'] ?? [];

        $this->assertCount(12, $replacementIds);
        $this->assertSame('completed', $shard->fresh()->status);
        $this->assertTrue((bool) data_get($shard->fresh()->result, 'async_recovered_by_split'));
        $this->assertSame(19, SyncJob::query()->findOrFail($run['sync_job_id'])->total_items);
        $ranges = MetaAdSyncShard::query()
            ->whereIn('id', $replacementIds)
            ->orderBy('since_date')
            ->get();
        $this->assertSame('2025-08-22', $ranges->first()?->since_date?->toDateString());
        $this->assertSame('2026-08-22', $ranges->last()?->until_date?->toDateString());
        $this->assertTrue($ranges->every(
            fn (MetaAdSyncShard $range): bool => $range->since_date?->diffInDays($range->until_date) < 31,
        ));
    }

    public function test_small_failed_async_report_has_bounded_resubmissions(): void
    {
        config()->set('services.meta_ads.async_recovery_window_days', 31);
        config()->set('services.meta_ads.async_min_window_days', 7);
        config()->set('services.meta_ads.async_max_resubmissions', 1);
        $store = $this->configuredStore(
            'Terminal Org',
            'terminal-meta-org',
            'Terminal Store',
            'terminal-meta.myshopify.com',
            'terminal-token',
        );
        $service = app(MetaAdsSyncService::class);
        $service->orchestrate($store, 'full');
        $shard = MetaAdSyncShard::query()
            ->where('kind', 'insights')
            ->where('level', 'account')
            ->sole();
        $shard->forceFill([
            'since_date' => '2026-08-22',
            'until_date' => '2026-08-22',
            'result' => ['async_report_id' => '7001'],
        ])->save();
        $http = new HttpFactory;
        $http->fake(function (Request $request) {
            if ($request->method() === 'POST') {
                return Http::response(['report_run_id' => '7002']);
            }

            return Http::response([
                'async_status' => 'Job Failed',
                'async_percent_completion' => 0,
            ]);
        });
        $recoveryService = new MetaAdsSyncService(new MetaAdsApiClient(
            $http,
            app(StoreBusinessCredentialService::class),
        ));

        $retry = $recoveryService->runShard($shard->fresh());
        $this->assertSame(1, $retry['async_pending']);
        $this->assertSame(1, data_get($shard->fresh()->result, 'async_failure_count'));
        $this->assertNull(data_get($shard->fresh()->result, 'async_report_id'));

        $pending = $recoveryService->runShard($shard->fresh());
        $this->assertSame(1, $pending['async_pending']);
        $terminal = $recoveryService->runShard($shard->fresh());
        $this->assertSame(1, $terminal['terminal_failed']);
        $this->assertSame('failed', $shard->fresh()->status);
        $this->assertSame('meta_ads_async_report_terminal_failed', $shard->fresh()->error_code);
    }

    public function test_structure_shard_resumes_from_last_successful_page_cursor(): void
    {
        $store = $this->configuredStore(
            'Cursor Org',
            'cursor-meta-org',
            'Cursor Store',
            'cursor-meta.myshopify.com',
            'cursor-token',
        );
        $service = app(MetaAdsSyncService::class);
        $service->orchestrate($store, 'full');
        $shard = MetaAdSyncShard::query()->where('kind', 'ads')->sole();
        $firstHttp = new HttpFactory;
        $firstHttp->fake(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            if (($query['after'] ?? null) === 'ads-page-2') {
                return Http::response(['error' => ['code' => 1]], 500);
            }

            return Http::response([
                'data' => [[
                    'id' => 'ad_cursor_1',
                    'account_id' => 'act_100',
                    'name' => 'Cursor ad one',
                    'updated_time' => '2026-08-01T01:00:00+0000',
                ]],
                'paging' => [
                    'cursors' => ['after' => 'ads-page-2'],
                    'next' => 'https://graph.test/next',
                ],
            ]);
        });
        $firstAttemptService = new MetaAdsSyncService(new MetaAdsApiClient(
            $firstHttp,
            app(StoreBusinessCredentialService::class),
        ));

        try {
            $firstAttemptService->runShard($shard);
            $this->fail('The second page should fail and preserve the cursor.');
        } catch (\Throwable) {
            $this->assertSame('ads-page-2', data_get($shard->fresh()->result, 'pagination_after'));
            $this->assertSame(1, data_get($shard->fresh()->result, 'partial_counts.ads'));
        }

        $seenAfter = [];
        $resumeHttp = new HttpFactory;
        $resumeHttp->fake(function (Request $request) use (&$seenAfter) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $seenAfter[] = $query['after'] ?? null;

            return Http::response(['data' => [[
                'id' => 'ad_cursor_2',
                'account_id' => 'act_100',
                'name' => 'Cursor ad two',
                'updated_time' => '2026-08-02T01:00:00+0000',
            ]]]);
        });
        $resumeService = new MetaAdsSyncService(new MetaAdsApiClient(
            $resumeHttp,
            app(StoreBusinessCredentialService::class),
        ));

        $completed = $resumeService->runShard($shard->fresh());
        $this->assertSame(['ads-page-2'], $seenAfter);
        $this->assertSame(2, $completed['ads']);
        $this->assertDatabaseHas('meta_ads', ['meta_ad_id' => 'ad_cursor_1']);
        $this->assertDatabaseHas('meta_ads', ['meta_ad_id' => 'ad_cursor_2']);
        $this->assertSame('completed', $shard->fresh()->status);
    }

    public function test_meta_usage_headers_create_a_shared_backpressure_checkpoint(): void
    {
        Cache::forget('meta-ads:usage:app:blocked-until');
        $store = $this->configuredStore(
            'Usage Org',
            'usage-meta-org',
            'Usage Store',
            'usage-meta.myshopify.com',
            'usage-token',
        );
        $rateLimits = app(MetaAdsRateLimitService::class);
        $storeKey = "meta-ads:usage:{$store->organization_id}:{$store->id}:blocked-until";
        Cache::forget($storeKey);

        $rateLimits->observe($store, new Response(new \GuzzleHttp\Psr7\Response(
            200,
            ['X-Business-Use-Case-Usage' => json_encode([
                'business' => [[
                    'call_count' => 5,
                    'estimated_time_to_regain_access' => 300,
                ]],
            ], JSON_THROW_ON_ERROR)],
            json_encode(['data' => []], JSON_THROW_ON_ERROR),
        )));

        $this->assertNull(Cache::get($storeKey));

        $rateLimits->observe($store, new Response(new \GuzzleHttp\Psr7\Response(
            200,
            ['X-App-Usage' => json_encode(['call_count' => 95], JSON_THROW_ON_ERROR)],
            json_encode(['data' => []], JSON_THROW_ON_ERROR),
        )));

        $this->assertGreaterThan(
            time(),
            (int) Cache::get('meta-ads:usage:app:blocked-until'),
        );

        Cache::forget($storeKey);
        $rateLimits->observe($store, new Response(new \GuzzleHttp\Psr7\Response(
            400,
            [],
            json_encode(['error' => ['code' => 80004]], JSON_THROW_ON_ERROR),
        )));
        $this->assertGreaterThan(time(), (int) Cache::get($storeKey));
    }

    private function configuredStore(
        string $organizationName,
        string $organizationCode,
        string $storeName,
        string $domain,
        string $token,
    ): Store {
        $organization = Organization::query()->create([
            'name' => $organizationName,
            'code' => $organizationCode,
        ]);
        $store = $organization->stores()->create([
            'name' => $storeName,
            'shopify_domain' => $domain,
            'status' => 'active',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);
        StoreBusinessCredential::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'provider' => 'meta_ads',
            'credential_key' => 'access_token',
            'credential_value' => $token,
        ]);

        return $store;
    }

    private function fakeMetaApi(): void
    {
        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            if (str_ends_with($path, '/me/adaccounts')) {
                return Http::response(['data' => [[
                    'id' => 'act_100',
                    'name' => 'Meta Account',
                    'account_status' => 1,
                    'currency' => 'USD',
                    'timezone_name' => 'America/Los_Angeles',
                    'timezone_offset_hours_utc' => -8,
                    'business_name' => 'Example Business',
                    'amount_spent' => '1000.00',
                ]]]);
            }

            if (str_ends_with($path, '/act_100/campaigns')) {
                if (($query['after'] ?? null) === 'campaign-page-2') {
                    return Http::response(['data' => [[
                        'id' => 'cmp_2',
                        'account_id' => 'act_100',
                        'name' => 'Campaign page two',
                        'status' => 'PAUSED',
                        'effective_status' => 'PAUSED',
                        'objective' => 'OUTCOME_SALES',
                        'created_time' => '2026-08-02T01:00:00+0000',
                        'updated_time' => '2026-08-03T01:00:00+0000',
                    ]]]);
                }

                return Http::response([
                    'data' => [
                        [
                            'id' => 'cmp_1',
                            'account_id' => 'act_100',
                            'name' => 'Campaign page one',
                            'status' => 'ACTIVE',
                            'effective_status' => 'ACTIVE',
                            'objective' => 'OUTCOME_SALES',
                            'daily_budget' => '5000',
                            'start_time' => '1969-12-31T23:59:59+00:00',
                            'created_time' => '2026-08-01T01:00:00+0000',
                            'updated_time' => '2026-08-02T01:00:00+0000',
                        ],
                        [
                            'id' => 'cmp_current_partial_hour',
                            'account_id' => 'act_100',
                            'name' => 'Current partial hour must wait',
                            'status' => 'ACTIVE',
                            'effective_status' => 'ACTIVE',
                            'created_time' => '2026-08-22T12:01:00+0000',
                            'updated_time' => '2026-08-22T12:15:00+0000',
                        ],
                    ],
                    'paging' => [
                        'cursors' => ['after' => 'campaign-page-2'],
                        'next' => 'https://graph.test/v21.0/act_100/campaigns?after=campaign-page-2&access_token=must-not-be-followed',
                    ],
                ]);
            }

            if (str_ends_with($path, '/act_100/adsets')) {
                return Http::response(['data' => [[
                    'id' => 'set_1',
                    'account_id' => 'act_100',
                    'campaign_id' => 'cmp_1',
                    'name' => 'Ad set',
                    'status' => 'ACTIVE',
                    'effective_status' => 'ACTIVE',
                    'optimization_goal' => 'OFFSITE_CONVERSIONS',
                    'billing_event' => 'IMPRESSIONS',
                    'created_time' => '2026-08-01T01:00:00+0000',
                    'updated_time' => '2026-08-02T01:00:00+0000',
                    'targeting' => ['geo_locations' => ['countries' => ['US']]],
                ]]]);
            }

            if (str_ends_with($path, '/act_100/ads')) {
                return Http::response(['data' => [[
                    'id' => 'ad_1',
                    'account_id' => 'act_100',
                    'campaign_id' => 'cmp_1',
                    'adset_id' => 'set_1',
                    'name' => 'Ad one',
                    'status' => 'ACTIVE',
                    'effective_status' => 'ACTIVE',
                    'created_time' => '2026-08-01T01:00:00+0000',
                    'updated_time' => '2026-08-02T01:00:00+0000',
                    'creative' => [
                        'id' => 'creative_1',
                        'name' => 'Creative one',
                        'title' => 'Creative headline',
                        'body' => 'Creative copy',
                        'call_to_action_type' => 'SHOP_NOW',
                    ],
                ]]]);
            }

            if (str_ends_with($path, '/act_100/insights')) {
                $level = (string) ($query['level'] ?? 'account');
                if ($request->method() === 'POST') {
                    $body = $request->data();
                    $level = (string) ($body['level'] ?? 'account');
                    $reportId = match ($level) {
                        'campaign' => '1002',
                        'adset' => '1003',
                        'ad' => '1004',
                        default => '1001',
                    };

                    return Http::response(['report_run_id' => $reportId]);
                }
                $timeRange = json_decode((string) ($query['time_range'] ?? ''), true);
                if (is_array($timeRange)
                    && ('2026-08-22' < ($timeRange['since'] ?? ''))
                    && ! isset($query['breakdowns'])) {
                    return Http::response(['data' => []]);
                }
                if (is_array($timeRange)
                    && ('2026-08-22' > ($timeRange['until'] ?? ''))
                    && ! isset($query['breakdowns'])) {
                    return Http::response(['data' => []]);
                }
                $ids = match ($level) {
                    'campaign' => ['campaign_id' => 'cmp_1', 'campaign_name' => 'Campaign page one'],
                    'adset' => ['adset_id' => 'set_1', 'adset_name' => 'Ad set'],
                    'ad' => ['ad_id' => 'ad_1', 'ad_name' => 'Ad one'],
                    default => [],
                };

                return Http::response(['data' => [[
                    'date_start' => '2026-08-22',
                    'date_stop' => '2026-08-22',
                    ...(isset($query['breakdowns'])
                        ? ['hourly_stats_aggregated_by_advertiser_time_zone' => '04:00:00 - 04:59:59']
                        : []),
                    'account_id' => '100',
                    'account_name' => 'Meta Account',
                    ...$ids,
                    'spend' => '25.50',
                    'impressions' => '1000',
                    'reach' => '800',
                    'clicks' => '40',
                    'unique_clicks' => '35',
                    'inline_link_clicks' => '30',
                    'ctr' => '4.0',
                    'cpc' => '0.6375',
                    'frequency' => '5.8',
                    'actions' => [
                        ['action_type' => 'omni_purchase', 'value' => '3'],
                        ['action_type' => 'landing_page_view', 'value' => '20'],
                    ],
                    'action_values' => [
                        ['action_type' => 'omni_purchase', 'value' => '150'],
                    ],
                    'cost_per_action_type' => [
                        ['action_type' => 'omni_purchase', 'value' => '8.5'],
                    ],
                    'purchase_roas' => [
                        ['action_type' => 'omni_purchase', 'value' => '5.88'],
                    ],
                ]]]);
            }

            if (preg_match('#/(100[1-4])$#', $path) === 1) {
                return Http::response([
                    'async_status' => 'Job Completed',
                    'async_percent_completion' => 100,
                ]);
            }

            if (preg_match('#/(100[1-4])/insights$#', $path, $matches) === 1) {
                $level = match ($matches[1]) {
                    '1002' => 'campaign',
                    '1003' => 'adset',
                    '1004' => 'ad',
                    default => 'account',
                };
                $ids = match ($level) {
                    'campaign' => ['campaign_id' => 'cmp_1', 'campaign_name' => 'Campaign page one'],
                    'adset' => ['adset_id' => 'set_1', 'adset_name' => 'Ad set'],
                    'ad' => ['ad_id' => 'ad_1', 'ad_name' => 'Ad one'],
                    default => [],
                };

                return Http::response(['data' => [[
                    'date_start' => '2026-08-22',
                    'date_stop' => '2026-08-22',
                    'account_id' => '100',
                    'account_name' => 'Meta Account',
                    ...$ids,
                    'spend' => '25.50',
                    'impressions' => '1000',
                    'reach' => '800',
                    'clicks' => '40',
                    'actions' => [['action_type' => 'omni_purchase', 'value' => '3']],
                    'action_values' => [['action_type' => 'omni_purchase', 'value' => '150']],
                ]]]);
            }

            return Http::response(['error' => ['code' => 100]], 400);
        });
    }
}
