<?php

namespace Tests\Feature;

use App\Jobs\SyncAdvertisingChannelForStore;
use App\Models\AdvertisingChannelAccount;
use App\Models\AdvertisingChannelDailyMetric;
use App\Models\BingAdsCampaignDailyMetric;
use App\Models\GoogleAdsCampaignDailyMetric;
use App\Models\GoogleAdsKeywordDailyMetric;
use App\Models\GoogleAdsSearchTermDailyMetric;
use App\Models\Organization;
use App\Models\Store;
use App\Models\StoreBusinessCredential;
use App\Models\SyncJob;
use App\Models\TikTokAdsAdDailyMetric;
use App\Models\TikTokAdsCampaignDailyMetric;
use App\Models\User;
use App\Services\Advertising\AdvertisingChannelApiService;
use App\Services\Advertising\AdvertisingChannelLifecycleService;
use App\Services\Advertising\AdvertisingChannelStatusService;
use App\Services\Advertising\AdvertisingChannelSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class AdvertisingChannelSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_google_incremental_sync_is_registered_hourly_and_covers_local_performance_tables(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains((string) $event->command, 'advertising-channels:sync google --mode=incremental'));

        $this->assertNotNull($event);
        $this->assertSame('17 * * * *', $event->expression);
    }

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-08-23 08:30:00 UTC');
        config()->set('services.advertising_sync.priority_days', 7);
        config()->set('services.advertising_sync.history_months', 6);
        config()->set('services.advertising_sync.rolling_days', 3);
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/campaign/get/')) {
                return Http::response([
                    'code' => 0,
                    'data' => ['list' => [[
                        'campaign_id' => '445566',
                        'campaign_name' => 'TikTok Summer Bikes',
                        'operation_status' => 'ENABLE',
                        'objective_type' => 'WEB_CONVERSIONS',
                    ]]],
                ]);
            }

            if (str_contains($request->url(), '/ad/get/')) {
                return Http::response([
                    'code' => 0,
                    'data' => ['list' => [[
                        'ad_id' => '778899',
                        'ad_name' => 'TikTok X7 creator video',
                        'ad_text' => 'Freedom on two wheels.',
                        'ad_texts' => ['Freedom on two wheels.', 'Built for every ride.'],
                        'campaign_id' => '445566',
                        'adgroup_id' => 'adgroup-11',
                        'ad_format' => 'VIDEO',
                        'video_id' => 'video-22',
                    ]]],
                ]);
            }

            if (str_contains($request->url(), '/report/integrated/get/')) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $requestData = array_merge($query, $request->data());
                $dataLevel = (string) ($requestData['data_level'] ?? '');
                $campaignLevel = $dataLevel === 'AUCTION_CAMPAIGN';
                $adLevel = $dataLevel === 'AUCTION_AD';

                return Http::response([
                    'code' => 0,
                    'data' => [
                        'list' => [[
                            'dimensions' => $campaignLevel
                                ? ['campaign_id' => '445566', 'stat_time_day' => '2026-08-22']
                                : ($adLevel
                                    ? ['ad_id' => '778899', 'stat_time_day' => '2026-08-22']
                                    : ['stat_time_day' => '2026-08-22']),
                            'metrics' => [
                                'spend' => $adLevel ? '40.20' : ($campaignLevel ? '60.25' : '100.50'),
                                'impressions' => $adLevel ? '400' : ($campaignLevel ? '700' : '1000'),
                                'clicks' => $adLevel ? '20' : ($campaignLevel ? '55' : '80'),
                                'complete_payment' => $adLevel ? '3' : ($campaignLevel ? '4' : '8'),
                                'complete_payment_roas' => $adLevel ? '6.5' : ($campaignLevel ? '4.2' : '5.5'),
                                'web_event_add_to_cart' => $campaignLevel ? '12' : '20',
                                'initiate_checkout' => $campaignLevel ? '9' : '15',
                                'video_play_actions' => $adLevel ? '300' : '0',
                                'video_watched_2s' => $adLevel ? '120' : '0',
                                'average_video_play' => $adLevel ? '2.4' : '0',
                            ],
                        ]],
                        'page_info' => ['total_page' => 1],
                    ],
                ]);
            }

            if ($request->url() === 'https://oauth2.googleapis.com/token') {
                return Http::response(['access_token' => 'google-access-token']);
            }

            $query = (string) ($request->data()['query'] ?? '');

            if (str_contains($query, 'segments.conversion_action_name')) {
                return Http::response(['results' => [
                    [
                        'segments' => ['date' => '2026-08-22', 'conversionActionName' => 'Google Shopping App Add To Cart'],
                        'metrics' => ['allConversions' => 16],
                    ],
                    [
                        'segments' => ['date' => '2026-08-22', 'conversionActionName' => 'Google Shopping App Begin Checkout'],
                        'metrics' => ['allConversions' => 9],
                    ],
                ]]);
            }

            if (str_contains($query, 'FROM search_term_view')) {
                return Http::response(['results' => [[
                    'customer' => ['id' => '1234567890'],
                    'campaign' => [
                        'id' => 'search-campaign',
                        'name' => 'Search Bikes',
                        'advertisingChannelType' => 'SEARCH',
                    ],
                    'adGroup' => ['id' => 'brand-ad-group', 'name' => 'Brand'],
                    'searchTermView' => ['searchTerm' => 'macfox ebike', 'status' => 'ADDED'],
                    'segments' => [
                        'date' => '2026-08-22',
                        'keyword' => ['info' => ['text' => 'macfox', 'matchType' => 'EXACT']],
                    ],
                    'metrics' => [
                        'costMicros' => '2500000',
                        'impressions' => 100,
                        'clicks' => 20,
                        'conversions' => 2,
                        'conversionsValue' => 25,
                    ],
                ]]]);
            }

            if (str_contains($query, 'FROM campaign_search_term_view')) {
                return Http::response(['results' => [[
                    'customer' => ['id' => '1234567890'],
                    'campaign' => [
                        'id' => 'pmax-campaign',
                        'name' => 'PMax Bikes',
                        'advertisingChannelType' => 'PERFORMANCE_MAX',
                    ],
                    'campaignSearchTermView' => ['searchTerm' => 'fat tire bike'],
                    'segments' => ['date' => '2026-08-22'],
                    'metrics' => [
                        'costMicros' => '1000000',
                        'impressions' => 50,
                        'clicks' => 5,
                        'conversions' => 1,
                        'conversionsValue' => 10,
                    ],
                ]]]);
            }

            if (str_contains($query, 'FROM keyword_view')) {
                return Http::response(['results' => [[
                    'customer' => ['id' => '1234567890'],
                    'campaign' => ['id' => 'search-campaign', 'name' => 'Search Bikes'],
                    'adGroup' => ['id' => 'brand-ad-group', 'name' => 'Brand'],
                    'adGroupCriterion' => [
                        'criterionId' => 'keyword-1',
                        'keyword' => ['text' => 'macfox ebike', 'matchType' => 'EXACT'],
                        'status' => 'ENABLED',
                    ],
                    'segments' => ['date' => '2026-08-22'],
                    'metrics' => [
                        'costMicros' => '3000000',
                        'impressions' => 120,
                        'clicks' => 24,
                        'conversions' => 3,
                        'conversionsValue' => 36,
                    ],
                ]]]);
            }

            if (str_contains($query, 'FROM campaign')) {
                return Http::response(['results' => [[
                    'customer' => ['id' => '1234567890'],
                    'campaign' => [
                        'id' => '99887766',
                        'name' => 'PMax Bikes',
                        'status' => 'ENABLED',
                        'advertisingChannelType' => 'PERFORMANCE_MAX',
                    ],
                    'segments' => ['date' => '2026-08-22'],
                    'metrics' => [
                        'costMicros' => '7500000',
                        'impressions' => 700,
                        'clicks' => 55,
                        'conversions' => 3,
                        'conversionsValue' => 31.5,
                        'conversionsValueByConversionDate' => 34.25,
                        'allConversions' => 4,
                        'allConversionsValue' => 36,
                        'allConversionsValueByConversionDate' => 38,
                    ],
                ]]]);
            }

            return Http::response(['results' => [[
                'customer' => [
                    'id' => '1234567890',
                    'descriptiveName' => 'Macfox Google',
                    'currencyCode' => 'USD',
                    'timeZone' => 'America/Los_Angeles',
                ],
                'segments' => ['date' => '2026-08-22'],
                'metrics' => [
                    'costMicros' => '12500000',
                    'conversionsValue' => 48.5,
                    'conversionsValueByConversionDate' => 52.25,
                    'allConversions' => 5,
                    'allConversionsValue' => 55.5,
                    'allConversionsValueByConversionDate' => 58.75,
                    'impressions' => 1000,
                    'clicks' => 80,
                    'conversions' => 4,
                ],
            ]]]);
        });
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_google_priority_sync_makes_seven_day_data_available_then_queues_six_month_backfill(): void
    {
        Queue::fake();
        [$store] = $this->googleStore();
        $sync = app(AdvertisingChannelSyncService::class);
        $version = $sync->credentialVersion($store, 'google');

        $sync->sync($store, 'google', 'priority', $version);

        $this->assertDatabaseHas('advertising_channel_accounts', [
            'organization_id' => $store->organization_id,
            'store_id' => $store->id,
            'provider' => 'google',
            'external_account_id' => '1234567890',
            'name' => 'Macfox Google',
        ]);
        $this->assertDatabaseHas('advertising_channel_daily_metrics', [
            'organization_id' => $store->organization_id,
            'store_id' => $store->id,
            'provider' => 'google',
            'metric_date' => '2026-08-22',
            'impressions' => 1000,
            'clicks' => 80,
        ]);
        $metric = AdvertisingChannelDailyMetric::query()->sole();
        $this->assertSame('12.500000', $metric->spend);
        $this->assertSame('48.500000', $metric->attributed_sales);
        $this->assertSame('52.250000', $metric->conversion_value_by_conversion_date);
        $this->assertSame('5.000000', $metric->all_conversions);
        $this->assertSame('55.500000', $metric->all_conversions_value);
        $this->assertSame('58.750000', $metric->all_conversions_value_by_conversion_date);
        $this->assertSame('16.000000', $metric->add_to_cart);
        $this->assertSame('9.000000', $metric->initiate_checkout);
        $campaign = GoogleAdsCampaignDailyMetric::query()->sole();
        $this->assertSame('99887766', $campaign->campaign_id);
        $this->assertSame('PMax Bikes', $campaign->campaign_name);
        $this->assertSame('7.500000', $campaign->spend);
        $this->assertSame('34.250000', $campaign->conversion_value_by_conversion_date);
        $standardSearchTerm = GoogleAdsSearchTermDailyMetric::query()->where('source_type', 'STANDARD')->sole();
        $this->assertSame('macfox ebike', $standardSearchTerm->search_term);
        $this->assertSame('macfox', $standardSearchTerm->matched_keyword);
        $this->assertSame('EXACT', $standardSearchTerm->match_type);
        $this->assertSame('2.500000', $standardSearchTerm->spend);
        $this->assertSame('25.000000', $standardSearchTerm->revenue);
        $pmaxSearchTerm = GoogleAdsSearchTermDailyMetric::query()->where('source_type', 'PERFORMANCE_MAX')->sole();
        $this->assertSame('fat tire bike', $pmaxSearchTerm->search_term);
        $this->assertSame('PERFORMANCE_MAX', $pmaxSearchTerm->status);
        $this->assertSame('1.000000', $pmaxSearchTerm->spend);
        $keyword = GoogleAdsKeywordDailyMetric::query()->sole();
        $this->assertSame('keyword-1', $keyword->criterion_id);
        $this->assertSame('macfox ebike', $keyword->keyword);
        $this->assertSame('ENABLED', $keyword->status);
        $this->assertSame('3.000000', $keyword->spend);
        $this->assertSame('36.000000', $keyword->revenue);
        $this->assertSame('ready', $store->syncStates()->where('sync_type', 'advertising_channel:google')->sole()->status);
        $this->assertSame('priority', $store->syncJobs()->where('type', 'advertising_channel:google')->sole()->mode);
        Queue::assertPushed(SyncAdvertisingChannelForStore::class, fn (SyncAdvertisingChannelForStore $job): bool => $job->channel === 'google'
            && $job->mode === 'backfill'
            && $job->storeId === $store->id);

        $status = app(AdvertisingChannelStatusService::class)->forStore($store, 'google');
        $this->assertTrue($status['data_ready']);
        $this->assertSame('backfilling', $status['state']);
        $this->assertSame('backfill', $status['mode']);
        $this->assertSame('2026-08-22', $status['last_metric_date']);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/customers/1234567890/googleAds:search')
            && str_contains((string) $request['query'], "segments.date BETWEEN '2026-08-17' AND '2026-08-23'"));
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/customers/1234567890/googleAds:search')
            && str_contains((string) $request['query'], 'metrics.all_conversions')
            && str_contains((string) $request['query'], 'Google Shopping App Add To Cart')
            && str_contains((string) $request['query'], 'Google Shopping App Begin Checkout'));
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/customers/1234567890/googleAds:search')
            && str_contains((string) $request['query'], 'FROM campaign')
            && str_contains((string) $request['query'], "campaign.status = 'ENABLED'"));
        Http::assertSent(fn (Request $request): bool => str_contains((string) ($request->data()['query'] ?? ''), 'FROM search_term_view')
            && str_contains((string) ($request->data()['query'] ?? ''), 'metrics.conversions_value > 0')
            && ! array_key_exists('pageSize', $request->data()));
        Http::assertSent(fn (Request $request): bool => str_contains((string) ($request->data()['query'] ?? ''), 'FROM campaign_search_term_view')
            && str_contains((string) ($request->data()['query'] ?? ''), "campaign.advertising_channel_type = 'PERFORMANCE_MAX'")
            && ! array_key_exists('pageSize', $request->data()));
        Http::assertSent(fn (Request $request): bool => str_contains((string) ($request->data()['query'] ?? ''), 'FROM keyword_view')
            && str_contains((string) ($request->data()['query'] ?? ''), "ad_group_criterion.status != 'REMOVED'")
            && ! array_key_exists('pageSize', $request->data()));
    }

    public function test_google_backfill_never_requests_dates_before_current_year(): void
    {
        CarbonImmutable::setTestNow('2026-02-10 08:30:00 UTC');
        config()->set('services.advertising_sync.chunk_days', 366);
        [$store] = $this->googleStore();
        $api = Mockery::mock(AdvertisingChannelApiService::class);
        $api->shouldReceive('syncPayload')
            ->once()
            ->with($store, 'google', '2026-01-01', '2026-02-03')
            ->andReturn([
                'accounts' => [],
                'daily_metrics' => [],
                'campaign_daily_metrics' => [],
                'ad_daily_metrics' => [],
                'search_term_daily_metrics' => [],
                'keyword_daily_metrics' => [],
            ]);
        $this->app->instance(AdvertisingChannelApiService::class, $api);

        $sync = app(AdvertisingChannelSyncService::class);
        $sync->sync($store, 'google', 'backfill', $sync->credentialVersion($store, 'google'));

        $job = SyncJob::query()->where('type', 'advertising_channel:google')->sole();
        $this->assertSame('2026-01-01 00:00:00', $job->since_at?->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-02-03 23:59:59', $job->until_at?->utc()->format('Y-m-d H:i:s'));
    }

    public function test_channel_records_are_store_isolated_and_clear_removes_only_target_store_data_and_jobs(): void
    {
        Queue::fake();
        [$first, $user] = $this->googleStore();
        [$second] = $this->googleStore('Second Org', 'second-google.myshopify.com');
        $sync = app(AdvertisingChannelSyncService::class);
        $sync->sync($first, 'google', 'priority', $sync->credentialVersion($first, 'google'));
        $sync->sync($second, 'google', 'priority', $sync->credentialVersion($second, 'google'));

        $this->assertSame(2, AdvertisingChannelAccount::query()->count());
        app(AdvertisingChannelLifecycleService::class)->clearCredential($first, 'google_ads', 'refresh_token', $user);

        $this->assertDatabaseMissing('advertising_channel_accounts', ['store_id' => $first->id, 'provider' => 'google']);
        $this->assertDatabaseHas('advertising_channel_accounts', ['store_id' => $second->id, 'provider' => 'google']);
        $this->assertDatabaseMissing('store_sync_states', ['store_id' => $first->id, 'sync_type' => 'advertising_channel:google']);
        $this->assertDatabaseMissing('sync_jobs', ['store_id' => $first->id, 'type' => 'advertising_channel:google']);
        $this->assertDatabaseMissing('store_business_credentials', [
            'store_id' => $first->id,
            'provider' => 'google_ads',
            'credential_key' => 'refresh_token',
        ]);
    }

    public function test_tiktok_priority_sync_persists_full_daily_and_campaign_metrics(): void
    {
        Queue::fake();
        [$store] = $this->tiktokStore();
        $sync = app(AdvertisingChannelSyncService::class);

        $sync->sync($store, 'tiktok', 'priority', $sync->credentialVersion($store, 'tiktok'));

        $metric = AdvertisingChannelDailyMetric::query()->where('provider', 'tiktok')->sole();
        $this->assertSame('100.500000', $metric->spend);
        $this->assertSame('552.750000', $metric->attributed_sales);
        $this->assertSame(1000, $metric->impressions);
        $this->assertSame(80, $metric->clicks);
        $this->assertSame('8.000000', $metric->conversions);
        $this->assertSame('20.000000', $metric->add_to_cart);
        $this->assertSame('15.000000', $metric->initiate_checkout);

        $campaign = TikTokAdsCampaignDailyMetric::query()->sole();
        $this->assertSame('445566', $campaign->campaign_id);
        $this->assertSame('TikTok Summer Bikes', $campaign->campaign_name);
        $this->assertSame('60.250000', $campaign->spend);
        $this->assertSame('253.050000', $campaign->attributed_sales);

        $ad = TikTokAdsAdDailyMetric::query()->sole();
        $this->assertSame('778899', $ad->ad_id);
        $this->assertSame('TikTok X7 creator video', $ad->ad_name);
        $this->assertSame(['Freedom on two wheels.', 'Built for every ride.'], $ad->ad_texts);
        $this->assertSame('40.200000', $ad->spend);
        $this->assertSame('261.300000', $ad->attributed_sales);
        $this->assertSame(300, $ad->video_play_actions);
        $this->assertSame(120, $ad->video_watched_2s);
        $this->assertSame('2.400000', $ad->average_video_play);
        Queue::assertPushed(SyncAdvertisingChannelForStore::class, fn (SyncAdvertisingChannelForStore $job): bool => $job->channel === 'tiktok'
            && $job->mode === 'backfill'
            && $job->storeId === $store->id);
    }

    public function test_bing_priority_sync_persists_scoped_campaign_daily_metrics(): void
    {
        Queue::fake();
        [$store] = $this->bingStore();
        $api = Mockery::mock(AdvertisingChannelApiService::class);
        $api->shouldReceive('syncPayload')->once()->with($store, 'bing', '2026-08-17', '2026-08-23')->andReturn([
            'accounts' => [[
                'external_account_id' => '187016548', 'name' => 'Macfox Bing Ads', 'currency' => 'USD',
            ]],
            'daily_metrics' => [[
                'external_account_id' => '187016548', 'date' => '2026-08-22', 'spend' => 329.12,
                'attributed_sales' => 3225.38, 'impressions' => 9482, 'clicks' => 166, 'conversions' => 2,
            ]],
            'campaign_daily_metrics' => [[
                'external_account_id' => '187016548', 'campaign_id' => 'campaign-brand',
                'campaign_name' => 'Search Brand', 'campaign_status' => 'Active', 'campaign_type' => 'Search & content',
                'date' => '2026-08-22', 'spend' => 170.25, 'attributed_sales' => 1800,
                'impressions' => 5000, 'clicks' => 90, 'conversions' => 1,
            ]],
            'ad_daily_metrics' => [],
            'search_term_daily_metrics' => [],
            'keyword_daily_metrics' => [],
        ]);
        $this->app->instance(AdvertisingChannelApiService::class, $api);
        $sync = app(AdvertisingChannelSyncService::class);

        $sync->sync($store, 'bing', 'priority', $sync->credentialVersion($store, 'bing'));

        $campaign = BingAdsCampaignDailyMetric::query()->sole();
        $this->assertSame((int) $store->organization_id, $campaign->organization_id);
        $this->assertSame((int) $store->id, $campaign->store_id);
        $this->assertSame('187016548', $campaign->external_account_id);
        $this->assertSame('campaign-brand', $campaign->campaign_id);
        $this->assertSame('Search Brand', $campaign->campaign_name);
        $this->assertSame('Search & content', $campaign->campaign_type);
        $this->assertSame('170.250000', $campaign->spend);
        $this->assertSame('1800.000000', $campaign->attributed_sales);
        $this->assertSame(5000, $campaign->impressions);
        $this->assertSame(90, $campaign->clicks);
        Queue::assertPushed(SyncAdvertisingChannelForStore::class, fn (SyncAdvertisingChannelForStore $job): bool => $job->channel === 'bing'
            && $job->mode === 'backfill'
            && $job->storeId === $store->id);
    }

    /** @return array{Store, User} */
    private function googleStore(string $organizationName = 'Google Org', string $domain = 'google.myshopify.com'): array
    {
        $organization = Organization::query()->create([
            'name' => $organizationName,
            'code' => strtolower(str_replace(' ', '-', $organizationName)).'-'.uniqid(),
        ]);
        $store = $organization->stores()->create([
            'name' => $organizationName.' Store',
            'shopify_domain' => $domain,
            'status' => 'active',
            'timezone' => 'UTC',
        ]);
        $user = User::factory()->create();
        foreach ([
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
            'refresh_token' => 'refresh-token',
            'developer_token' => 'developer-token',
            'customer_id' => '123-456-7890',
        ] as $key => $value) {
            StoreBusinessCredential::query()->create([
                'organization_id' => $organization->id,
                'store_id' => $store->id,
                'provider' => 'google_ads',
                'credential_key' => $key,
                'credential_value' => $value,
                'updated_by' => $user->id,
            ]);
        }

        return [$store, $user];
    }

    /** @return array{Store, User} */
    private function tiktokStore(): array
    {
        $organization = Organization::query()->create([
            'name' => 'TikTok Org',
            'code' => 'tiktok-org-'.uniqid(),
        ]);
        $store = $organization->stores()->create([
            'name' => 'TikTok Store',
            'shopify_domain' => 'tiktok.myshopify.com',
            'status' => 'active',
            'timezone' => 'UTC',
        ]);
        $user = User::factory()->create();
        foreach (['access_token' => 'tiktok-token', 'advertiser_ids' => '7623302290988089361'] as $key => $value) {
            StoreBusinessCredential::query()->create([
                'organization_id' => $organization->id,
                'store_id' => $store->id,
                'provider' => 'tiktok_ads',
                'credential_key' => $key,
                'credential_value' => $value,
                'updated_by' => $user->id,
            ]);
        }

        return [$store, $user];
    }

    /** @return array{Store, User} */
    private function bingStore(): array
    {
        $organization = Organization::query()->create([
            'name' => 'Bing Org',
            'code' => 'bing-org-'.uniqid(),
        ]);
        $store = $organization->stores()->create([
            'name' => 'Bing Store',
            'shopify_domain' => 'bing.myshopify.com',
            'status' => 'active',
            'timezone' => 'UTC',
        ]);
        $user = User::factory()->create();
        foreach ([
            'client_id' => 'client-id', 'client_secret' => 'client-secret', 'refresh_token' => 'refresh-token',
            'developer_token' => 'developer-token', 'account_id' => '187016548',
        ] as $key => $value) {
            StoreBusinessCredential::query()->create([
                'organization_id' => $organization->id,
                'store_id' => $store->id,
                'provider' => 'bing_ads',
                'credential_key' => $key,
                'credential_value' => $value,
                'updated_by' => $user->id,
            ]);
        }

        return [$store, $user];
    }
}
