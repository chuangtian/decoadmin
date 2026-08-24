<?php

namespace Tests\Feature;

use App\Models\AdvertisingChannelAccount;
use App\Models\AdvertisingChannelDailyMetric;
use App\Models\CriteoCampaignDailyMetric;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreBusinessCredential;
use App\Models\User;
use App\Services\Advertising\AdvertisingChannelApiService;
use App\Services\Advertising\AdvertisingChannelSyncService;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Tests\TestCase;

class CriteoAdsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_criteo_overview_uses_latest_fixed_week_and_scoped_api_metrics(): void
    {
        [$user, $organization, $store] = $this->context();
        $otherStore = $this->addStore($user, $organization, 'Other Criteo Store', 'other-criteo.myshopify.com');
        $this->configureCriteo($organization, $store);
        $account = $this->account($organization, $store, '117187', 'macfoxbike_US');
        $otherAccount = $this->account($organization, $otherStore, '999999', 'other_advertiser');
        $this->metric($organization, $store, $account, '2026-08-14', 300, 1200, 12000, 240, 5);
        $this->metric($organization, $store, $account, '2026-08-21', 100, 1000, 10000, 100, 10);
        $this->metric($organization, $store, $account, '2026-08-22', 200, 1500, 20000, 300, 20);
        $this->metric($organization, $store, $account, '2026-08-23', 300, 2500, 30000, 600, 30);
        $this->metric($organization, $otherStore, $otherAccount, '2026-08-23', 9000, 90000, 900000, 90000, 900);
        $this->campaignMetric($organization, $store, $account, 'campaign-a', '用户获取', '2026-08-22', 100, 400, 10000, 100, 5);
        $this->campaignMetric($organization, $store, $account, 'campaign-a', '用户获取', '2026-08-23', 300, 1200, 30000, 300, 15);
        $this->campaignMetric($organization, $store, $account, 'campaign-b', '客户留存', '2026-08-23', 200, 2000, 10000, 200, 10);
        $this->campaignMetric($organization, $otherStore, $otherAccount, 'other-campaign', '其他店铺', '2026-08-23', 9000);

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->get(route('paid-advertising.criteo', [
                'date_from' => '2026-01-01',
                'date_to' => '2026-01-02',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('PaidAdvertising/Criteo')
                ->where('criteoOverview.schema', 'criteo-ads-overview-v3')
                ->where('criteoOverview.accounts.0.id', '117187')
                ->where('criteoOverview.accounts.0.name', 'macfoxbike_US')
                ->where('criteoOverview.period.label', '最新 7 天')
                ->where('criteoOverview.period.date_from', '2026-08-17')
                ->where('criteoOverview.period.date_to', '2026-08-23')
                ->where('criteoOverview.period.days_expected', 7)
                ->where('criteoOverview.period.days_available', 3)
                ->where('criteoOverview.comparison_period.date_from', '2026-08-10')
                ->where('criteoOverview.comparison_period.date_to', '2026-08-16')
                ->where('criteoOverview.attribution.label', '点击后 7 天 + 展示后 24 小时')
                ->where('criteoOverview.report_timezone.id', 'UTC')
                ->where('criteoOverview.report_timezone.label', 'Criteo 系统时间 (UTC)')
                ->where('criteoOverview.current.spend', 600)
                ->where('criteoOverview.current.revenue', 5000)
                ->where('criteoOverview.current.conversions', 60)
                ->where('criteoOverview.current.roas', 8.33)
                ->where('criteoOverview.current.impressions', 60000)
                ->where('criteoOverview.current.clicks', 1000)
                ->where('criteoOverview.current.ctr', 1.67)
                ->where('criteoOverview.current.cpc', 0.6)
                ->where('criteoOverview.current.cpa', 10)
                ->where('criteoOverview.previous.spend', 300)
                ->where('criteoOverview.previous.conversions', 5)
                ->where('criteoOverview.previous.roas', 4)
                ->where('criteoOverview.deltas.revenue', 316.7)
                ->where('criteoOverview.daily.0.date', '2026-08-17')
                ->where('criteoOverview.daily.0.spend', 0)
                ->where('criteoOverview.daily.4.date', '2026-08-21')
                ->where('criteoOverview.daily.4.revenue', 1000)
                ->where('criteoOverview.daily.4.conversions', 10)
                ->where('criteoOverview.daily.6.date', '2026-08-23')
                ->where('criteoOverview.daily.6.roas', 8.33)
                ->where('criteoOverview.daily.6.cpa', 10)
                ->where('criteoOverview.campaign_spend_share.available', true)
                ->where('criteoOverview.campaign_spend_share.total_spend', 600)
                ->where('criteoOverview.campaign_spend_share.items.0.id', 'campaign-a')
                ->where('criteoOverview.campaign_spend_share.items.0.share', 66.67)
                ->where('criteoOverview.campaign_spend_share.items.1.id', 'campaign-b')
                ->where('criteoOverview.campaigns.0.id', 'campaign-a')
                ->where('criteoOverview.campaigns.0.spend', 400)
                ->where('criteoOverview.campaigns.0.revenue', 1600)
                ->where('criteoOverview.campaigns.0.roas', 4)
                ->where('criteoOverview.campaigns.0.impressions', 40000)
                ->where('criteoOverview.campaigns.0.clicks', 400)
                ->where('criteoOverview.campaigns.0.ctr', 1)
                ->where('criteoOverview.campaigns.0.cpc', 1)
                ->where('criteoOverview.campaigns.0.conversions', 20)
                ->where('criteoOverview.campaigns.0.cpa', 20)
                ->where('criteoOverview.campaigns.1.id', 'campaign-b')
                ->where('criteoOverview.campaigns.1.roas', 10)
                ->has('criteoOverview.campaigns', 2)
                ->has('criteoOverview.daily', 7)
                ->has('criteoOverview.accounts', 1));

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->getJson(route('paid-advertising.criteo.data', [
                'date_from' => '2025-01-01',
                'date_to' => '2025-01-02',
            ]))
            ->assertOk()
            ->assertJsonPath('data.period.date_from', '2026-08-17')
            ->assertJsonPath('data.current.revenue', 5000)
            ->assertJsonPath('data.daily.6.clicks', 600)
            ->assertJsonPath('data.campaign_spend_share.items.1.share', 33.33)
            ->assertJsonPath('data.campaigns.1.ctr', 2)
            ->assertJsonMissing(['id' => '999999']);
    }

    public function test_criteo_api_discovers_advertiser_name_and_normalizes_supported_report_fields(): void
    {
        [, $organization, $store] = $this->context();
        $this->configureCriteo($organization, $store);
        Http::fake(function (Request $request) {
            if ($request->url() === 'https://api.criteo.com/oauth2/token') {
                return Http::response(['access_token' => 'test-access-token'], 200);
            }
            if ($request->url() === 'https://api.criteo.com/2026-01/advertisers/me') {
                return Http::response(['data' => [[
                    'type' => 'advertiser',
                    'id' => '117187',
                    'attributes' => ['advertiserName' => 'macfoxbike_US'],
                ]]], 200);
            }
            if (in_array('CampaignId', (array) $request['dimensions'], true)) {
                return Http::response(
                    "CampaignId;Campaign;Day;Currency;AdvertiserCost;RevenueGeneratedPc7d;RevenueGeneratedPv24h;Displays;Clicks;SalesPc7d;SalesPv24h\n830518;用户获取;2026-08-23;USD;300.50;1200.25;800.25;30000;600;4;2\n",
                    200,
                    ['Content-Type' => 'text/csv'],
                );
            }

            return Http::response(
                "Day,Currency,AdvertiserCost,RevenueGeneratedPc7d,RevenueGeneratedPv24h,Displays,Clicks,SalesPc7d,SalesPv24h\n2026-08-23,USD,300.50,1200.25,800.25,30000,600,4,2\n",
                200,
                ['Content-Type' => 'text/csv'],
            );
        });

        $payload = app(AdvertisingChannelApiService::class)
            ->syncPayload($store, 'criteo', '2026-08-17', '2026-08-23');

        $this->assertSame('117187', $payload['accounts'][0]['external_account_id']);
        $this->assertSame('macfoxbike_US', $payload['accounts'][0]['name']);
        $this->assertSame('2026-08-23', $payload['daily_metrics'][0]['date']);
        $this->assertSame(300.5, $payload['daily_metrics'][0]['spend']);
        $this->assertSame(2000.5, $payload['daily_metrics'][0]['attributed_sales']);
        $this->assertSame(30000, $payload['daily_metrics'][0]['impressions']);
        $this->assertSame(600, $payload['daily_metrics'][0]['clicks']);
        $this->assertSame(6.0, $payload['daily_metrics'][0]['conversions']);
        $this->assertSame('830518', $payload['campaign_daily_metrics'][0]['campaign_id']);
        $this->assertSame('用户获取', $payload['campaign_daily_metrics'][0]['campaign_name']);
        $this->assertSame(2000.5, $payload['campaign_daily_metrics'][0]['attributed_sales']);
        $this->assertSame('UTC', $payload['accounts'][0]['timezone']);
        $this->assertArrayNotHasKey('client_secret', $payload['accounts'][0]['raw_payload']);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.criteo.com/2026-01/statistics/report'
            && $request['timezone'] === 'UTC'
            && in_array('SalesPc7d', (array) $request['metrics'], true)
            && in_array('SalesPv24h', (array) $request['metrics'], true));
    }

    public function test_criteo_priority_window_uses_criteo_utc_calendar_date(): void
    {
        [, $organization, $store] = $this->context();
        $this->configureCriteo($organization, $store);
        config()->set('services.advertising_sync.priority_days', 7);
        Queue::fake();
        CarbonImmutable::setTestNow('2026-08-23 18:30:00 UTC');

        try {
            $api = Mockery::mock(AdvertisingChannelApiService::class);
            $api->shouldReceive('syncPayload')
                ->once()
                ->with($store, 'criteo', '2026-08-17', '2026-08-23')
                ->andReturn([
                    'accounts' => [[
                        'external_account_id' => '117187',
                        'name' => 'macfoxbike_US',
                        'currency' => 'USD',
                        'timezone' => 'UTC',
                    ]],
                    'daily_metrics' => [],
                    'campaign_daily_metrics' => [[
                        'external_account_id' => '117187',
                        'campaign_id' => '830518',
                        'campaign_name' => '用户获取',
                        'date' => '2026-08-23',
                        'spend' => 157.99,
                        'attributed_sales' => 3422.15,
                        'impressions' => 1623788,
                        'clicks' => 4698,
                        'conversions' => 4,
                    ]],
                ]);

            (new AdvertisingChannelSyncService($api))->sync($store, 'criteo', 'priority');

            $this->assertDatabaseHas('criteo_campaign_daily_metrics', [
                'organization_id' => $organization->id,
                'store_id' => $store->id,
                'external_account_id' => '117187',
                'campaign_id' => '830518',
                'metric_date' => '2026-08-23',
            ]);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    private function campaignMetric(
        Organization $organization,
        Store $store,
        AdvertisingChannelAccount $account,
        string $campaignId,
        string $campaignName,
        string $date,
        float $spend,
        float $revenue = 0,
        int $impressions = 0,
        int $clicks = 0,
        float $conversions = 0,
    ): void {
        CriteoCampaignDailyMetric::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'advertising_channel_account_id' => $account->id,
            'external_account_id' => $account->external_account_id,
            'campaign_id' => $campaignId,
            'campaign_name' => $campaignName,
            'metric_date' => $date,
            'spend' => $spend,
            'attributed_sales' => $revenue,
            'impressions' => $impressions,
            'clicks' => $clicks,
            'conversions' => $conversions,
            'raw_payload' => [],
            'synced_at' => now(),
        ]);
    }

    /** @return array{User, Organization, Store} */
    private function context(): array
    {
        $this->seed(PermissionSeeder::class);
        $organization = Organization::query()->create(['name' => 'Criteo Ads', 'code' => 'criteo-ads']);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $organization->users()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $store = $this->addStore($user, $organization, 'Criteo Ads Store', 'criteo-ads.myshopify.com');
        $this->seed(RoleSeeder::class);
        $role = Role::query()->whereBelongsTo($organization)->where('slug', 'store-admin')->firstOrFail();
        $user->roles()->attach($role, ['organization_id' => $organization->id, 'store_id' => null]);

        return [$user, $organization, $store];
    }

    private function addStore(User $user, Organization $organization, string $name, string $domain): Store
    {
        $store = $organization->stores()->create([
            'name' => $name,
            'shopify_domain' => $domain,
            'status' => 'active',
            'timezone' => 'America/Los_Angeles',
        ]);
        $store->members()->attach($user, ['status' => 'active', 'joined_at' => now()]);

        return $store;
    }

    private function configureCriteo(Organization $organization, Store $store): void
    {
        foreach (['api_key' => 'client-id', 'client_secret' => 'client-secret'] as $key => $value) {
            StoreBusinessCredential::query()->create([
                'organization_id' => $organization->id,
                'store_id' => $store->id,
                'provider' => 'criteo',
                'credential_key' => $key,
                'credential_value' => $value,
            ]);
        }
    }

    private function account(Organization $organization, Store $store, string $id, string $name): AdvertisingChannelAccount
    {
        return AdvertisingChannelAccount::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'provider' => 'criteo',
            'external_account_id' => $id,
            'name' => $name,
            'status' => 'active',
            'currency' => 'USD',
            'timezone' => 'UTC',
            'raw_payload' => ['advertiser_id' => $id],
            'last_seen_at' => now(),
            'synced_at' => now(),
        ]);
    }

    private function metric(
        Organization $organization,
        Store $store,
        AdvertisingChannelAccount $account,
        string $date,
        float $spend,
        float $revenue,
        int $impressions,
        int $clicks,
        float $conversions = 0,
    ): void {
        AdvertisingChannelDailyMetric::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'advertising_channel_account_id' => $account->id,
            'provider' => 'criteo',
            'external_account_id' => $account->external_account_id,
            'metric_date' => $date,
            'spend' => $spend,
            'attributed_sales' => $revenue,
            'impressions' => $impressions,
            'clicks' => $clicks,
            'conversions' => $conversions,
            'raw_payload' => [],
            'synced_at' => now(),
        ]);
    }

    /** @return array<string, int> */
    private function contextSession(Organization $organization, Store $store): array
    {
        return ['current_organization_id' => $organization->id, 'current_store_id' => $store->id];
    }
}
