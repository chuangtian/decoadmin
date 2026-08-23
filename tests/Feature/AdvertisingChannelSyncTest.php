<?php

namespace Tests\Feature;

use App\Jobs\SyncAdvertisingChannelForStore;
use App\Models\AdvertisingChannelAccount;
use App\Models\AdvertisingChannelDailyMetric;
use App\Models\Organization;
use App\Models\Store;
use App\Models\StoreBusinessCredential;
use App\Models\User;
use App\Services\Advertising\AdvertisingChannelLifecycleService;
use App\Services\Advertising\AdvertisingChannelStatusService;
use App\Services\Advertising\AdvertisingChannelSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdvertisingChannelSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-08-23 08:30:00 UTC');
        config()->set('services.advertising_sync.priority_days', 7);
        config()->set('services.advertising_sync.history_months', 6);
        config()->set('services.advertising_sync.rolling_days', 3);
        Http::fake(function (Request $request) {
            if ($request->url() === 'https://oauth2.googleapis.com/token') {
                return Http::response(['access_token' => 'google-access-token']);
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
}
