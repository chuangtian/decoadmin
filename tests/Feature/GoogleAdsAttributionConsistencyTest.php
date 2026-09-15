<?php

namespace Tests\Feature;

use App\Events\AdvertisingChannelSyncStatusChanged;
use App\Models\AdvertisingChannelAccount;
use App\Models\AdvertisingChannelDailyMetric;
use App\Models\GoogleAdsCampaignDailyMetric;
use App\Models\GoogleAdsKeywordDailyMetric;
use App\Models\GoogleAdsSearchTermDailyMetric;
use App\Models\Organization;
use App\Models\Store;
use App\Models\StoreBusinessCredential;
use App\Models\SyncJob;
use App\Models\User;
use App\Services\Advertising\AdvertisingChannelApiService;
use App\Services\Advertising\AdvertisingChannelSyncService;
use App\Services\Advertising\GoogleAdsOverviewService;
use App\Services\Advertising\GoogleAdsPerformanceTableService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use JsonException;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class GoogleAdsAttributionConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private const METRICS = [AdvertisingChannelDailyMetric::class, GoogleAdsCampaignDailyMetric::class, GoogleAdsSearchTermDailyMetric::class, GoogleAdsKeywordDailyMetric::class];

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-09-15 18:37:00 UTC');
        config()->set('services.advertising_sync.google_attribution_days', 30);
        config()->set('services.advertising_sync.chunk_days', 31);
        Event::fake([AdvertisingChannelSyncStatusChanged::class]);
        Queue::fake();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_attribution_refreshes_all_views_idempotently_without_touching_other_scopes(): void
    {
        $store = $this->store();
        $api = Mockery::mock(AdvertisingChannelApiService::class);
        $api->shouldReceive('syncPayload')->times(3)->with($store, 'google', '2026-08-17', '2026-09-15')
            ->andReturn($this->payload(100), $this->payload(1493.18), $this->payload(1493.18));
        $this->app->instance(AdvertisingChannelApiService::class, $api);
        $sync = app(AdvertisingChannelSyncService::class);
        $sync->sync($store, 'google', 'attribution');

        $otherStore = $this->store($store->organization);
        $otherOrganizationStore = $this->store();
        $guardRows = [];
        $staleDetails = [];
        foreach (self::METRICS as $model) {
            $row = $model::query()->where('store_id', $store->id)->whereDate('metric_date', '2026-09-14')->sole();
            $guardRows[] = $row->replicate()->fill(['metric_date' => '2026-08-16']);
            foreach ([$otherStore, $otherOrganizationStore] as $other) {
                $account = AdvertisingChannelAccount::query()->firstOrCreate([
                    'organization_id' => $other->organization_id, 'store_id' => $other->id,
                    'provider' => 'google', 'external_account_id' => '1234567890',
                ], ['raw_payload' => [], 'last_seen_at' => now(), 'synced_at' => now()]);
                $guardRows[] = $row->replicate()->fill([
                    'organization_id' => $other->organization_id, 'store_id' => $other->id,
                    'advertising_channel_account_id' => $account->id,
                ]);
            }
            $otherAccount = AdvertisingChannelAccount::query()->firstOrCreate([
                'organization_id' => $store->organization_id, 'store_id' => $store->id,
                'provider' => 'google', 'external_account_id' => '9999999999',
            ], ['raw_payload' => [], 'last_seen_at' => now(), 'synced_at' => now()]);
            $guardRows[] = $row->replicate()->fill([
                'advertising_channel_account_id' => $otherAccount->id, 'external_account_id' => '9999999999',
            ]);
            if ($model !== AdvertisingChannelDailyMetric::class) {
                $stale = $row->replicate()->fill($model === GoogleAdsCampaignDailyMetric::class
                    ? ['campaign_id' => 'obsolete'] : ['dimension_key' => 'obsolete']);
                $stale->save();
                $staleDetails[] = $stale;
            }
        }
        foreach ($guardRows as $guard) {
            $guard->save();
            $guard->refresh();
        }

        $sync->sync($store, 'google', 'attribution');
        $filters = ['account' => '1234567890', 'date_from' => '2026-09-01', 'date_to' => '2026-09-14'];
        $overview = app(GoogleAdsOverviewService::class)->forStore($store, $filters);
        $this->assertEquals(1513.18, $overview['current']['revenue']);
        $this->assertEquals(1513.18, $overview['campaigns'][0]['revenue']);
        $this->assertCount(2, $overview['trend']);
        foreach (['search-terms', 'keywords'] as $view) {
            $table = app(GoogleAdsPerformanceTableService::class)->forStore($store, [...$filters, 'view' => $view]);
            $this->assertSame(1, $table['pagination']['total']);
            $this->assertEquals(1513.18, $table['rows'][0]['revenue']);
        }
        foreach ($guardRows as $guard) {
            $this->assertSame($guard->getAttributes(), $guard->fresh()?->getAttributes());
        }
        foreach ($staleDetails as $stale) {
            $this->assertModelMissing($stale);
        }
        $beforeRepeat = $this->snapshot($store);
        $sync->sync($store, 'google', 'attribution');
        // Detail replacement may allocate new IDs, but values and counts stay stable.
        $this->assertSame($beforeRepeat, $this->snapshot($store));
        Event::assertDispatched(AdvertisingChannelSyncStatusChanged::class, fn ($event) => $event->storeId === $store->id
            && $event->state === 'completed' && $event->mode === 'attribution'
            && $event->views === ['overview', 'trend', 'campaigns', 'search-terms', 'keywords']);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_large_attribution_details_use_bounded_mysql_statements(): void
    {
        $store = $this->store();
        $payload = $this->largePayload(3500);
        $api = Mockery::mock(AdvertisingChannelApiService::class);
        $api->shouldReceive('syncPayload')->once()->with($store, 'google', '2026-08-17', '2026-09-15')->andReturn($payload);
        $this->app->instance(AdvertisingChannelApiService::class, $api);
        $bindings = [];
        DB::listen(function ($query) use (&$bindings) {
            if (preg_match('/^insert into [`"](google_ads_(?:campaign|search_term|keyword)_daily_metrics)[`"]/', $query->sql, $match)) {
                $bindings[$match[1]][] = count($query->bindings);
            }
        });
        app(AdvertisingChannelSyncService::class)->sync($store, 'google', 'attribution');
        foreach ([GoogleAdsCampaignDailyMetric::class, GoogleAdsSearchTermDailyMetric::class, GoogleAdsKeywordDailyMetric::class] as $model) {
            $table = (new $model)->getTable();
            $this->assertDatabaseCount($table, 3500);
            $this->assertCount(7, $bindings[$table]);
            $this->assertLessThanOrEqual(20000, max($bindings[$table]));
        }
    }

    public function test_later_batch_failure_rolls_back_earlier_detail_batches_and_core(): void
    {
        $store = $this->store();
        $api = Mockery::mock(AdvertisingChannelApiService::class);
        $api->shouldReceive('syncPayload')->twice()->with($store, 'google', '2026-08-17', '2026-09-15')
            ->andReturn($this->payload(100), $this->largePayload(601));
        $this->app->instance(AdvertisingChannelApiService::class, $api);
        $sync = app(AdvertisingChannelSyncService::class);
        $sync->sync($store, 'google', 'attribution');
        $before = $this->snapshot($store);
        $batches = 0;
        DB::listen(function ($query) use (&$batches) {
            if (str_starts_with($query->sql, 'insert into `google_ads_search_term_daily_metrics`') && ++$batches === 2) {
                throw new RuntimeException('Simulated later batch failure.');
            }
        });
        try {
            $sync->sync($store, 'google', 'attribution');
            $this->fail('The second batch must fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated later batch failure.', $exception->getMessage());
            $this->assertSame(2, $batches);
            $this->assertSame($before, $this->snapshot($store));
        }
    }

    private function largePayload(int $count): array
    {
        $payload = $this->payload(1493.18);
        foreach (['campaign_daily_metrics', 'search_term_daily_metrics', 'keyword_daily_metrics'] as $key) {
            $sample = $payload[$key][0];
            $payload[$key] = [];
            for ($index = 0; $index < $count; $index++) {
                $payload[$key][] = [...$sample, ...match ($key) {
                    'campaign_daily_metrics' => ['campaign_id' => 'campaign-'.$index],
                    'search_term_daily_metrics' => ['dimension_key' => 'term-'.$index, 'search_term' => 'term '.$index],
                    default => ['dimension_key' => 'keyword-'.$index, 'keyword' => 'keyword '.$index],
                }];
            }
        }

        return $payload;
    }

    public function test_failed_detail_write_rolls_back_core_and_all_existing_details(): void
    {
        $store = $this->store();
        $broken = $this->payload(1493.18);
        $broken['keyword_daily_metrics'][0]['raw_payload'] = ['invalid' => "\xB1\x31"];
        $api = Mockery::mock(AdvertisingChannelApiService::class);
        $api->shouldReceive('syncPayload')->twice()->with($store, 'google', '2026-08-17', '2026-09-15')
            ->andReturn($this->payload(100), $broken);
        $this->app->instance(AdvertisingChannelApiService::class, $api);
        $sync = app(AdvertisingChannelSyncService::class);
        $sync->sync($store, 'google', 'attribution');
        $before = $this->snapshot($store);
        Event::fake([AdvertisingChannelSyncStatusChanged::class]);
        try {
            $sync->sync($store, 'google', 'attribution');
            $this->fail('Invalid detail payload must fail the whole reporting snapshot.');
        } catch (JsonException) {
            $this->assertSame($before, $this->snapshot($store));
        }
        $this->assertSame('failed', SyncJob::query()->latest('id')->first()->status);
        Event::assertNotDispatched(AdvertisingChannelSyncStatusChanged::class, fn ($event) => $event->state === 'completed');
    }

    public function test_failed_full_fetch_leaves_the_previous_snapshot_available(): void
    {
        $store = $this->store();
        $api = Mockery::mock(AdvertisingChannelApiService::class);
        $api->shouldReceive('syncPayload')->once()->with($store, 'google', '2026-08-17', '2026-09-15')->andReturn($this->payload(100));
        $this->app->instance(AdvertisingChannelApiService::class, $api);
        app(AdvertisingChannelSyncService::class)->sync($store, 'google', 'attribution');
        $before = $this->snapshot($store);
        $api->shouldReceive('syncPayload')->once()->with($store, 'google', '2026-08-17', '2026-09-15')->andThrow(new RuntimeException('Detail report unavailable.'));
        Event::fake([AdvertisingChannelSyncStatusChanged::class]);
        try {
            app(AdvertisingChannelSyncService::class)->sync($store, 'google', 'attribution');
            $this->fail('An incomplete fetch must not replace the reporting snapshot.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Detail report unavailable.', $exception->getMessage());
            $this->assertSame($before, $this->snapshot($store));
        }
        Event::assertNotDispatched(AdvertisingChannelSyncStatusChanged::class, fn ($event) => $event->state === 'completed');
    }

    private function snapshot(Store $store): array
    {
        $result = [];
        foreach (self::METRICS as $model) {
            $result[$model] = $model::query()->where('organization_id', $store->organization_id)->where('store_id', $store->id)
                ->orderBy('external_account_id')->orderBy('metric_date')->get()->map(fn ($row) => $row->makeHidden(['id', 'created_at', 'updated_at'])->toArray())->all();
        }

        return $result;
    }

    private function store(?Organization $organization = null): Store
    {
        $organization ??= Organization::query()->create(['name' => 'Attribution test', 'code' => uniqid('attribution-')]);
        $store = $organization->stores()->create(['name' => 'Attribution test', 'shopify_domain' => uniqid('attribution-').'.myshopify.com', 'status' => 'active', 'timezone' => 'America/Los_Angeles']);
        $user = User::factory()->create();
        foreach (['client_id', 'client_secret', 'refresh_token', 'developer_token', 'customer_id'] as $key) {
            StoreBusinessCredential::query()->create(['organization_id' => $organization->id, 'store_id' => $store->id, 'provider' => 'google_ads', 'credential_key' => $key, 'credential_value' => 'fixture', 'updated_by' => $user->id]);
        }

        return $store;
    }

    private function payload(float $latestRevenue): array
    {
        $payload = ['accounts' => [['external_account_id' => '1234567890', 'currency' => 'USD', 'timezone' => 'America/Los_Angeles']], 'daily_metrics' => [], 'campaign_daily_metrics' => [], 'search_term_daily_metrics' => [], 'keyword_daily_metrics' => []];
        foreach (['2026-09-01' => 20, '2026-09-14' => $latestRevenue] as $date => $revenue) {
            $common = ['external_account_id' => '1234567890', 'date' => $date, 'spend' => 10, 'impressions' => 100, 'clicks' => 5, 'conversions' => 1];
            $payload['daily_metrics'][] = [...$common, 'attributed_sales' => $revenue, 'conversion_value_by_conversion_date' => $revenue + 10];
            $payload['campaign_daily_metrics'][] = [...$common, 'campaign_id' => 'campaign-1', 'campaign_name' => 'Test campaign', 'campaign_status' => 'ENABLED', 'conversions_value' => $revenue];
            $detail = [...$common, 'dimension_key' => 'resource-1', 'campaign_id' => 'campaign-1', 'campaign_name' => 'Test campaign', 'revenue' => $revenue, 'match_type' => 'EXACT'];
            $payload['search_term_daily_metrics'][] = [...$detail, 'search_term' => 'test term'];
            $payload['keyword_daily_metrics'][] = [...$detail, 'keyword' => 'test keyword'];
        }

        return $payload;
    }
}
