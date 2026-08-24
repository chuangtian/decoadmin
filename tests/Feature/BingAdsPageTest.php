<?php

namespace Tests\Feature;

use App\Models\AdvertisingChannelAccount;
use App\Models\AdvertisingChannelDailyMetric;
use App\Models\BingAdsCampaignDailyMetric;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreBusinessCredential;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BingAdsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_bing_overview_uses_scoped_mysql_metrics_and_requested_formulas(): void
    {
        [$user, $organization, $store] = $this->context();
        $otherStore = $this->addStore($user, $organization, 'Other Bing Store', 'other-bing.myshopify.com');
        $this->configureBing($organization, $store);
        $account = $this->account($organization, $store, '187016548', 'Macfox Bing Ads');
        $otherAccount = $this->account($organization, $otherStore, '999999999', 'Other Bing Ads');
        $this->metric($organization, $store, $account, '2026-08-20', 2055.82, 15265.22, 65555, 1254, 15, 118, 89);
        $this->metric($organization, $store, $account, '2026-08-13', 1000, 5000, 40000, 800, 10, 50, 40);
        $this->metric($organization, $otherStore, $otherAccount, '2026-08-20', 9000, 90000, 900000, 90000, 900, 900, 900);
        $this->campaign($organization, $store, $account, 'campaign-brand', 'Search Brand', '2026-08-20', 1200, 8000);
        $this->campaign($organization, $store, $account, 'campaign-pmax', 'PMax Bikes', '2026-08-20', 855.82, 7265.22);
        $this->campaign($organization, $store, $account, 'campaign-enabled', 'Enabled Campaign', '2026-08-20', 0, 0, 'Enabled');
        $this->campaign($organization, $store, $account, 'campaign-paused', 'Paused Campaign', '2026-08-20', 9999, 99999, 'Paused');
        $this->campaign($organization, $otherStore, $otherAccount, 'other-campaign', 'Other Campaign', '2026-08-20', 9000, 90000);

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->get(route('paid-advertising.bing', [
                'account' => '187016548',
                'date_from' => '2026-08-17',
                'date_to' => '2026-08-23',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('PaidAdvertising/Bing')
                ->where('bingOverview.schema', 'bing-ads-overview-v1')
                ->where('bingOverview.filters.account', '187016548')
                ->where('bingOverview.comparison_period.date_from', '2026-08-10')
                ->where('bingOverview.comparison_period.date_to', '2026-08-16')
                ->where('bingOverview.current.spend', 2055.82)
                ->where('bingOverview.current.revenue', 15265.22)
                ->where('bingOverview.current.roas', 7.43)
                ->where('bingOverview.current.conversions', 15)
                ->where('bingOverview.current.impressions', 65555)
                ->where('bingOverview.current.clicks', 1254)
                ->where('bingOverview.current.ctr', 1.91)
                ->where('bingOverview.current.cpc', 1.64)
                ->where('bingOverview.current.cpa', 137.05)
                ->where('bingOverview.current.add_to_cart', 118)
                ->where('bingOverview.current.checkout', 89)
                ->where('bingOverview.current.add_to_cart_cost', 17.42)
                ->where('bingOverview.current.checkout_cost', 23.1)
                ->where('bingOverview.previous.spend', 1000)
                ->where('bingOverview.deltas.spend', 105.6)
                ->has('bingOverview.trend', 7)
                ->where('bingOverview.trend.3.date', '2026-08-20')
                ->where('bingOverview.trend.3.spend', 2055.82)
                ->where('bingOverview.trend.3.revenue', 15265.22)
                ->where('bingOverview.trend.3.roas', 7.43)
                ->where('bingOverview.trend.3.impressions', 65555)
                ->where('bingOverview.trend.3.clicks', 1254)
                ->where('bingOverview.trend.3.ctr', 1.91)
                ->where('bingOverview.trend.3.cpc', 1.64)
                ->where('bingOverview.trend.3.conversions', 15)
                ->where('bingOverview.trend.3.add_to_cart', 118)
                ->where('bingOverview.trend.3.checkout', 89)
                ->where('bingOverview.trend.3.cpa', 137.05)
                ->has('bingOverview.previous_trend', 7)
                ->where('bingOverview.previous_trend.3.date', '2026-08-13')
                ->where('bingOverview.previous_trend.3.spend', 1000)
                ->where('bingOverview.previous_trend.3.roas', 5)
                ->has('bingOverview.campaigns', 3)
                ->where('bingOverview.campaigns.0.id', 'campaign-brand')
                ->where('bingOverview.campaigns.0.name', 'Search Brand')
                ->where('bingOverview.campaigns.0.spend', 1200)
                ->where('bingOverview.campaigns.0.cpc', 12)
                ->where('bingOverview.campaigns.0.share', 58.37)
                ->where('bingOverview.campaigns.1.id', 'campaign-pmax')
                ->where('bingOverview.campaigns.2.id', 'campaign-enabled')
                ->where('bingOverview.campaigns.2.status', 'Enabled')
                ->has('bingOverview.accounts', 1));

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->getJson(route('paid-advertising.bing.data', [
                'account' => '187016548',
                'date_from' => '2026-08-17',
                'date_to' => '2026-08-23',
            ]))
            ->assertOk()
            ->assertJsonPath('data.current.roas', 7.43)
            ->assertJsonPath('data.current.add_to_cart_cost', 17.42)
            ->assertJsonPath('data.trend.3.date', '2026-08-20')
            ->assertJsonPath('data.previous_trend.3.roas', 5)
            ->assertJsonPath('data.campaigns.0.name', 'Search Brand')
            ->assertJsonMissing(['external_account_id' => '999999999']);
    }

    public function test_bing_campaign_shares_use_only_the_six_highest_spend_campaigns(): void
    {
        [$user, $organization, $store] = $this->context();
        $this->configureBing($organization, $store);
        $account = $this->account($organization, $store, '187016548', 'Macfox Bing Ads');

        foreach ([700, 600, 500, 400, 300, 200, 100] as $index => $spend) {
            $this->campaign(
                $organization,
                $store,
                $account,
                'campaign-'.($index + 1),
                'Campaign '.($index + 1),
                '2026-08-20',
                $spend,
                $spend * 5,
            );
        }

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->getJson(route('paid-advertising.bing.data', [
                'account' => '187016548',
                'date_from' => '2026-08-17',
                'date_to' => '2026-08-23',
            ]))
            ->assertOk()
            ->assertJsonCount(7, 'data.campaigns')
            ->assertJsonPath('data.campaigns.0.id', 'campaign-1')
            ->assertJsonPath('data.campaigns.0.share', 25.93)
            ->assertJsonPath('data.campaigns.5.share', 7.41)
            ->assertJsonPath('data.campaigns.6.share', 0);
    }

    /** @return array{User, Organization, Store} */
    private function context(): array
    {
        $this->seed(PermissionSeeder::class);
        $organization = Organization::query()->create(['name' => 'Bing Ads', 'code' => 'bing-ads']);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $organization->users()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $store = $this->addStore($user, $organization, 'Bing Ads Store', 'bing-ads.myshopify.com');
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
        ]);
        $store->members()->attach($user, ['status' => 'active', 'joined_at' => now()]);

        return $store;
    }

    private function configureBing(Organization $organization, Store $store): void
    {
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
            ]);
        }
    }

    private function account(Organization $organization, Store $store, string $id, string $name): AdvertisingChannelAccount
    {
        return AdvertisingChannelAccount::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'provider' => 'bing',
            'external_account_id' => $id,
            'name' => $name,
            'status' => 'active',
            'currency' => 'USD',
            'raw_payload' => [],
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
        float $purchases,
        float $addToCart,
        float $checkout,
    ): void {
        AdvertisingChannelDailyMetric::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'advertising_channel_account_id' => $account->id,
            'provider' => 'bing',
            'external_account_id' => $account->external_account_id,
            'metric_date' => $date,
            'spend' => $spend,
            'attributed_sales' => $revenue,
            'impressions' => $impressions,
            'clicks' => $clicks,
            'conversions' => $purchases,
            'add_to_cart' => $addToCart,
            'initiate_checkout' => $checkout,
            'raw_payload' => [],
            'synced_at' => now(),
        ]);
    }

    private function campaign(
        Organization $organization,
        Store $store,
        AdvertisingChannelAccount $account,
        string $campaignId,
        string $campaignName,
        string $date,
        float $spend,
        float $revenue,
        string $status = 'Active',
    ): void {
        BingAdsCampaignDailyMetric::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'advertising_channel_account_id' => $account->id,
            'external_account_id' => $account->external_account_id,
            'campaign_id' => $campaignId,
            'campaign_name' => $campaignName,
            'campaign_status' => $status,
            'campaign_type' => str_contains($campaignName, 'PMax') ? 'Performance max' : 'Search & content',
            'metric_date' => $date,
            'spend' => $spend,
            'attributed_sales' => $revenue,
            'impressions' => 1000,
            'clicks' => 100,
            'conversions' => 5,
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
