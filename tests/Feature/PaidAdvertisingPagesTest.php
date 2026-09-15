<?php

namespace Tests\Feature;

use App\Jobs\SyncAdvertisingChannelForStore;
use App\Jobs\SyncMetaAdsCampaignPeriod;
use App\Jobs\SyncMetaAdsForStore;
use App\Jobs\SyncPaidAdvertisingGoalTarget;
use App\Models\AdvertisingChannelAccount;
use App\Models\AdvertisingChannelDailyMetric;
use App\Models\AuditLog;
use App\Models\FeishuBitableField;
use App\Models\FeishuBitableRecord;
use App\Models\FeishuBitableTable;
use App\Models\GoogleAdsCampaignDailyMetric;
use App\Models\GoogleAdsKeywordDailyMetric;
use App\Models\GoogleAdsSearchTermDailyMetric;
use App\Models\MetaAd;
use App\Models\MetaAdAccount;
use App\Models\MetaAdCreative;
use App\Models\MetaAdInsight;
use App\Models\Organization;
use App\Models\PaidAdvertisingGoalBoard;
use App\Models\PaidAdvertisingGoalField;
use App\Models\PaidAdvertisingGoalRecord;
use App\Models\PaidAdvertisingGoalSyncRun;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreBusinessCredential;
use App\Models\SyncJob;
use App\Models\TikTokAdsAdDailyMetric;
use App\Models\TikTokAdsCampaignDailyMetric;
use App\Models\User;
use App\Services\Feishu\PaidAdvertisingGoalSyncService;
use App\Services\PaidAdvertisingGoalRefreshService;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Tests\TestCase;

class PaidAdvertisingPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_with_reports_permission_can_open_each_advertising_channel_status_page(): void
    {
        [$user, $organization, $store] = $this->context('operator');

        foreach ($this->emptyPages() as $routeName => $title) {
            $component = match ($routeName) {
                'paid-advertising.tiktok' => 'PaidAdvertising/TikTok',
                'paid-advertising.bing' => 'PaidAdvertising/Bing',
                'paid-advertising.criteo' => 'PaidAdvertising/Criteo',
                default => 'PaidAdvertising/Channel',
            };
            $this->actingAs($user)
                ->withSession($this->contextSession($organization, $store))
                ->get(route($routeName))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component($component)
                    ->where('channelStatus.label', $title)
                    ->where('channelStatus.configured', false)
                    ->where('channelStatus.state', 'not_configured'));
        }
    }

    public function test_google_date_filters_use_store_today_across_all_metric_tabs(): void
    {
        [$user, $organization, $store] = $this->context('store-admin');
        $store->update(['timezone' => 'America/Los_Angeles']);
        CarbonImmutable::setTestNow('2026-09-15T05:50:00Z');
        try {
            $account = AdvertisingChannelAccount::create([
                'organization_id' => $organization->id, 'store_id' => $store->id,
                'provider' => 'google', 'external_account_id' => '6442213333',
                'timezone' => 'America/Los_Angeles', 'status' => 'active',
                'raw_payload' => [],
                'last_seen_at' => now(), 'synced_at' => now(),
            ]);
            $this->createGoogleMetric($organization, $store, $account, '2026-09-14', 100, 500, 10, 20, 15);
            $this->createGoogleMetric($organization, $store, $account, '2026-09-15', 999, 999, 1, 1, 1);
            // SQLite preserves Eloquent's midnight suffix; production uses a DATE column.
            foreach (['2026-09-14', '2026-09-15'] as $date) {
                DB::table('advertising_channel_daily_metrics')->where('advertising_channel_account_id', $account->id)
                    ->whereDate('metric_date', $date)->update(['metric_date' => $date]);
            }
            $this->actingAs($user)->withSession($this->contextSession($organization, $store));
            foreach ([[], ['view' => 'search-terms'], ['view' => 'keywords']] as $view) {
                $this->getJson(route('paid-advertising.google.data', [
                    ...$view, 'account' => '6442213333', 'date_from' => '2026-09-01', 'date_to' => '2026-09-15',
                ]))->assertOk()->assertJsonPath('data.filters.date_from', '2026-09-01')
                    ->assertJsonPath('data.filters.date_to', '2026-09-14');
                $this->getJson(route('paid-advertising.google.data', $view))->assertOk()
                    ->assertJsonPath('data.filters.date_from', '2026-09-08')
                    ->assertJsonPath('data.filters.date_to', '2026-09-14');
            }
            $this->getJson(route('paid-advertising.google.data'))
                ->assertJsonPath('data.current.spend', 100)
                ->assertJsonPath('data.store_today', '2026-09-14')
                ->assertJsonPath('data.timezone', 'America/Los_Angeles');
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_google_ads_overview_reads_scoped_mysql_metrics_and_compares_previous_equal_period(): void
    {
        [$user, $organization, $store] = $this->context('store-admin');
        $otherStore = $this->addStore($user, $organization, 'Other Google Store', 'other-google.myshopify.com');
        $this->configureGoogle($organization, $store);
        $account = AdvertisingChannelAccount::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'provider' => 'google',
            'external_account_id' => '6442213333',
            'name' => 'Macfox Google Ads account',
            'status' => 'active',
            'currency' => 'USD',
            'timezone' => 'America/Los_Angeles',
            'raw_payload' => [],
            'last_seen_at' => now(),
            'synced_at' => now(),
        ]);
        $otherAccount = AdvertisingChannelAccount::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $otherStore->id,
            'provider' => 'google',
            'external_account_id' => '9999999999',
            'name' => 'Other Google Account',
            'status' => 'active',
            'currency' => 'USD',
            'raw_payload' => [],
            'last_seen_at' => now(),
            'synced_at' => now(),
        ]);
        $this->createGoogleMetric($organization, $store, $account, '2026-08-20', 100, 500, 10, 20, 15, 550);
        $this->createGoogleMetric($organization, $store, $account, '2026-08-13', 80, 320, 8, 10, 8);
        $this->createGoogleMetric($organization, $otherStore, $otherAccount, '2026-08-20', 9000, 90000, 900, 900, 900);
        $this->createGoogleCampaignMetric($organization, $store, $account, '2026-08-20', 'campaign-a', 'PMax Bikes', 60);
        $this->createGoogleCampaignMetric($organization, $store, $account, '2026-08-20', 'campaign-b', 'Search Brand', 40);
        $this->createGoogleCampaignMetric($organization, $otherStore, $otherAccount, '2026-08-20', 'other-campaign', 'Other Store Campaign', 9000);

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->get(route('paid-advertising.google', [
                'account' => '6442213333',
                'date_from' => '2026-08-17',
                'date_to' => '2026-08-23',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('PaidAdvertising/Channel')
                ->where('googleOverview.schema', 'google-ads-overview-v2')
                ->where('googleOverview.filters.account', '6442213333')
                ->where('googleOverview.comparison_period.date_from', '2026-08-10')
                ->where('googleOverview.comparison_period.date_to', '2026-08-16')
                ->where('googleOverview.current.spend', 100)
                ->where('googleOverview.current.revenue', 500)
                ->where('googleOverview.current.roas', 5)
                ->where('googleOverview.current.cpa', 10)
                ->where('googleOverview.current.add_to_cart', 20)
                ->where('googleOverview.current.checkout', 15)
                ->where('googleOverview.current.add_to_cart_cost', 5)
                ->where('googleOverview.current.checkout_cost', 6.67)
                ->where('googleOverview.current.impressions', 1000)
                ->where('googleOverview.current.clicks', 100)
                ->where('googleOverview.current.conversions', 10)
                ->where('googleOverview.current.ctr', 10)
                ->where('googleOverview.current.cpc', 1)
                ->where('googleOverview.previous.spend', 80)
                ->where('googleOverview.deltas.spend', 25)
                ->where('googleOverview.deltas.cpc', 25)
                ->has('googleOverview.trend', 1)
                ->where('googleOverview.trend.0.date', '2026-08-20')
                ->where('googleOverview.trend.0.spend', 100)
                ->where('googleOverview.trend.0.revenue', 500)
                ->where('googleOverview.trend.0.conversion_value', 550)
                ->where('googleOverview.trend.0.roas', 5)
                ->where('googleOverview.trend.0.roi', 5.5)
                ->where('googleOverview.trend.0.ctr', 10)
                ->where('googleOverview.trend.0.cpc', 1)
                ->has('googleOverview.campaign_spend', 2)
                ->where('googleOverview.campaign_spend.0.id', 'campaign-a')
                ->where('googleOverview.campaign_spend.0.spend', 60)
                ->where('googleOverview.campaign_spend.0.percentage', 60)
                ->where('googleOverview.campaign_spend.1.id', 'campaign-b')
                ->where('googleOverview.campaign_spend.1.percentage', 40)
                ->has('googleOverview.campaigns', 2)
                ->where('googleOverview.campaigns.0.id', 'campaign-a')
                ->where('googleOverview.campaigns.0.name', 'PMax Bikes')
                ->where('googleOverview.campaigns.0.channel_type', 'PMax')
                ->where('googleOverview.campaigns.0.status', '运行中')
                ->where('googleOverview.campaigns.0.spend', 60)
                ->where('googleOverview.campaigns.0.revenue', 300)
                ->where('googleOverview.campaigns.0.roas', 5)
                ->where('googleOverview.campaigns.0.impressions', 1000)
                ->where('googleOverview.campaigns.0.clicks', 100)
                ->where('googleOverview.campaigns.0.ctr', 10)
                ->where('googleOverview.campaigns.0.cpc', 0.6)
                ->where('googleOverview.campaigns.0.conversions', 10)
                ->where('googleOverview.campaigns.1.id', 'campaign-b')
                ->where('googleOverview.funnel.0.value', 1000)
                ->where('googleOverview.funnel.1.value', 100)
                ->where('googleOverview.funnel.2.value', 10)
                ->has('googleOverview.accounts', 1));
    }

    public function test_google_ads_goal_feishu_token_is_store_scoped_sanitized_and_clearable(): void
    {
        Queue::fake();
        [$user, $organization, $store] = $this->context('organization-admin');
        $otherStore = $this->addStore($user, $organization, 'Other Google Goal Store', 'other-google-goal.myshopify.com');
        $secret = 'sensitive-google-goal-app-token';

        StoreBusinessCredential::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $otherStore->id,
            'provider' => 'feishu_data_links',
            'credential_key' => 'advertising_goals_app_token',
            'credential_value' => 'other-store-goal-token',
        ]);

        $save = $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->putJson(route('paid-advertising.google.goals.feishu.update'), [
                'app_token' => "  {$secret}  ",
            ]);

        $save->assertAccepted()
            ->assertJsonPath('data.section', 'advertising_goals')
            ->assertJsonPath('data.configured', true)
            ->assertJsonPath('data.has_configuration', true)
            ->assertJsonPath('data.missing_fields', []);
        $run = PaidAdvertisingGoalSyncRun::query()
            ->where('organization_id', $organization->id)
            ->where('store_id', $store->id)
            ->where('target_key', PaidAdvertisingGoalRefreshService::TARGET_OVERALL)
            ->sole();
        $this->assertSame(PaidAdvertisingGoalRefreshService::SOURCE_OVERALL_CONFIGURED, $run->trigger);
        $this->assertSame(PaidAdvertisingGoalRefreshService::STATUS_QUEUED, $run->status);
        Queue::assertPushed(
            SyncPaidAdvertisingGoalTarget::class,
            fn (SyncPaidAdvertisingGoalTarget $job): bool => $job->runId === $run->id
                && $job->organizationId === $organization->id
                && $job->storeId === $store->id
                && $job->trigger === PaidAdvertisingGoalRefreshService::SOURCE_OVERALL_CONFIGURED
                && $job->target === PaidAdvertisingGoalRefreshService::TARGET_OVERALL,
        );
        $this->assertStringNotContainsString($secret, $save->getContent());
        $credential = StoreBusinessCredential::query()
            ->where('organization_id', $organization->id)
            ->where('store_id', $store->id)
            ->where('provider', 'feishu_data_links')
            ->where('credential_key', 'advertising_goals_app_token')
            ->sole();
        $this->assertSame($secret, $credential->credential_value);
        $this->assertStringNotContainsString(
            $secret,
            (string) DB::table('store_business_credentials')->where('id', $credential->id)->value('credential_value'),
        );

        $sourceKey = 'overall:google:sheet:sales-target';
        $googleFields = [
            '记录日期',
            '今日销售额($)',
            '本月已完成销售额($)',
            '本月销售额目标($)',
            '日均还需完成($)',
            '日均达成率',
            '月目标当前完成率',
            '月时间对比完成度',
            '月时间进度',
            '当前 ROAS',
            '目标 ROAS',
            '本月花费($)',
        ];
        foreach ($googleFields as $position => $name) {
            PaidAdvertisingGoalField::query()->create([
                'organization_id' => $organization->id,
                'store_id' => $store->id,
                'goal_board_id' => null,
                'source_key' => $sourceKey,
                'source_field_id' => 'overall-google-sales-'.$position,
                'name' => $name,
                'type' => $position === 0 ? 1 : 2,
                'field_order' => $position,
                'is_primary' => $position === 0,
                'property_encrypted' => [
                    'source' => 'spreadsheet',
                    'sheet_title' => '销售目标',
                ],
                'synced_at' => now(),
            ]);
        }
        foreach ([
            ['2026/08/22', '31,000.00', '798,655.00', '1,500,000.00', '75,000.00', '41.33%', '53.24%', '-14.50%', '67.74%', '3.30', '3.70', '33,000.00'],
            ['2026/08/23', '33,596.19', '832,251.63', '1,500,000.00', '74,194.26', '45.28%', '55.48%', '-15.49%', '70.97%', '3.50', '3.70', '34,727.53'],
        ] as $index => $values) {
            PaidAdvertisingGoalRecord::query()->create([
                'organization_id' => $organization->id,
                'store_id' => $store->id,
                'goal_board_id' => null,
                'source_key' => $sourceKey,
                'source_record_id' => 'overall-google-sales-row-'.$index,
                'fields_encrypted' => array_combine($googleFields, $values),
                'synced_at' => now(),
            ]);
        }

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->get(route('paid-advertising.google'))
            ->assertOk()
            ->assertDontSee($secret)
            ->assertInertia(fn (Assert $page) => $page
                ->where('googleGoalFeishu.schema', 'feishu-data-link-status-v1')
                ->where('googleGoalFeishu.section', 'advertising_goals')
                ->where('googleGoalFeishu.configured', true)
                ->where('googleGoalFeishu.has_configuration', true)
                ->where('googleGoalFeishu.missing_fields', [])
                ->where('googleGoalSummary.schema', 'paid-advertising-google-ads-summary-v1')
                ->where('googleGoalSummary.available', true)
                ->where('googleGoalSummary.source', 'database_sync')
                ->where('googleGoalSummary.source_sheet', '销售目标')
                ->where('googleGoalSummary.as_of_date', '2026-08-23')
                ->where('googleGoalSummary.values.daily_sales', 33596.19)
                ->where('googleGoalSummary.values.monthly_sales', 832251.63)
                ->where('googleGoalSummary.values.monthly_target', 1500000)
                ->where('googleGoalSummary.values.daily_needed', 74194.26)
                ->where('googleGoalSummary.values.daily_achievement_rate', 45.28)
                ->where('googleGoalSummary.values.completion_rate', 55.48)
                ->where('googleGoalSummary.values.time_variance', -15.49)
                ->where('googleGoalSummary.values.time_progress', 70.97)
                ->where('googleGoalSummary.pace_status', 'behind')
                ->where('googleGoalSummary.efficiency.values.current_roas', 3.5)
                ->where('googleGoalSummary.efficiency.values.target_roas', 3.7)
                ->where('googleGoalSummary.efficiency.values.achievement_rate', 94.59)
                ->where('googleGoalSummary.efficiency.values.monthly_spend', 34727.53)
                ->where('googleGoalSummary.details.total', 2)
                ->where('googleGoalSummary.details.rows.0.date', '2026-08-23')
                ->where('googleGoalSummary.details.rows.1.date', '2026-08-22')
                ->where('canManageGoogleGoalFeishu', true));

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->deleteJson(route('paid-advertising.google.goals.feishu.clear'))
            ->assertUnprocessable();

        $clear = $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->deleteJson(route('paid-advertising.google.goals.feishu.clear'), ['confirmed' => true]);

        $clear->assertOk()
            ->assertJsonPath('data.configured', false)
            ->assertJsonPath('data.has_configuration', false)
            ->assertJsonPath('data.missing_fields', ['advertising_goals_app_token']);
        $this->assertFalse(StoreBusinessCredential::query()
            ->where('store_id', $store->id)
            ->where('provider', 'feishu_data_links')
            ->where('credential_key', 'advertising_goals_app_token')
            ->exists());
        $this->assertTrue(StoreBusinessCredential::query()
            ->where('store_id', $otherStore->id)
            ->where('provider', 'feishu_data_links')
            ->where('credential_key', 'advertising_goals_app_token')
            ->exists());
        $this->assertFalse(PaidAdvertisingGoalField::query()
            ->where('organization_id', $organization->id)
            ->where('store_id', $store->id)
            ->whereNull('goal_board_id')
            ->where('source_key', 'like', 'overall:google:%')
            ->exists());
        $this->assertFalse(PaidAdvertisingGoalRecord::query()
            ->where('organization_id', $organization->id)
            ->where('store_id', $store->id)
            ->whereNull('goal_board_id')
            ->where('source_key', 'like', 'overall:google:%')
            ->exists());
    }

    public function test_google_ads_weekly_report_reads_google_week_table_independently_from_top_date_filters(): void
    {
        [$user, $organization, $store] = $this->context('operator');
        $otherStore = $this->addStore($user, $organization, 'Other Weekly Store', 'other-weekly.myshopify.com');
        $table = FeishuBitableTable::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'source_section' => 'paid-ad-goals:overall',
            'source_table_id' => 'tbl-google-weekly',
            'name' => 'Google周数据',
            'synced_at' => CarbonImmutable::parse('2026-08-24 03:40:00'),
        ]);
        $otherTable = FeishuBitableTable::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $otherStore->id,
            'source_section' => 'paid-ad-goals:overall',
            'source_table_id' => 'tbl-other-google-weekly',
            'name' => 'Google周数据',
            'synced_at' => CarbonImmutable::parse('2026-08-24 03:40:00'),
        ]);
        $createWeek = function (FeishuBitableTable $source, Store $sourceStore, string $start, string $label, array $metrics, string $recordId) use ($organization): void {
            FeishuBitableRecord::query()->create([
                'organization_id' => $organization->id,
                'store_id' => $sourceStore->id,
                'feishu_bitable_table_id' => $source->id,
                'source_record_id' => $recordId,
                'fields_encrypted' => [
                    '记录日期' => CarbonImmutable::parse($start, 'Asia/Shanghai')->getTimestamp() * 1000,
                    '周' => [['text' => $label, 'type' => 'text']],
                    '费用' => $metrics[0],
                    '转化价值' => $metrics[1],
                    'ROI' => $metrics[2],
                    '加购数' => $metrics[3],
                    '结账数' => $metrics[4],
                    '单次加购成本' => $metrics[5],
                    '单次结账成本' => $metrics[6],
                    '成交数' => $metrics[7],
                    '单次转化成本' => $metrics[8],
                ],
                'synced_at' => now(),
            ]);
        };

        $createWeek($table, $store, '2026-07-27', '31周 07.27~08.02', [30967.33, 250867.12, 8.101025, 1736.57, 1165.17, 17.832469, 26.577521, 166.18, 186.348117], 'rec-week-31');
        $createWeek($table, $store, '2026-08-03', '32周 08.03~08.09', [33631.83, 221563.04, 6.587897, 1564.01, 954.19, 21.50359, 35.246471, 140.64, 239.134172], 'rec-week-32');
        $createWeek($table, $store, '2026-08-10', '33周 08.10~08.16', [34727.53, 235311.42, 6.775933, 1887.49, 1094.91, 18.398789, 31.717246, 202.21, 171.739924], 'rec-week-33');
        $createWeek($otherTable, $otherStore, '2026-08-17', '34周 08.17~08.23', [999999, 999999, 1, 999999, 999999, 1, 1, 999999, 1], 'rec-other-week-34');

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->getJson(route('paid-advertising.google.data', [
                'view' => 'weekly',
                'week' => '2026-08-10',
                'date_from' => '2026-08-17',
                'date_to' => '2026-08-23',
            ]))
            ->assertOk()
            ->assertJsonPath('data.schema', 'google-ads-weekly-report-v1')
            ->assertJsonPath('data.source_table', 'Google周数据')
            ->assertJsonCount(3, 'data.weeks')
            ->assertJsonPath('data.weeks.0.label', '33周 08.10~08.16')
            ->assertJsonPath('data.selected_week.key', '2026-08-10')
            ->assertJsonPath('data.previous_week.key', '2026-08-03')
            ->assertJsonPath('data.values.spend', 34727.53)
            ->assertJsonPath('data.values.revenue', 235311.42)
            ->assertJsonPath('data.values.roi', 6.78)
            ->assertJsonPath('data.values.add_to_cart', 1887.49)
            ->assertJsonPath('data.values.checkout', 1094.91)
            ->assertJsonPath('data.values.conversions', 202.21)
            ->assertJsonPath('data.values.cpa', 171.74)
            ->assertJsonPath('data.changes.spend', 3.3)
            ->assertJsonPath('data.changes.revenue', 6.2)
            ->assertJsonPath('data.changes.roi', 2.9)
            ->assertJsonPath('data.changes.add_to_cart', 20.7)
            ->assertJsonPath('data.changes.checkout', 14.7)
            ->assertJsonPath('data.changes.add_to_cart_cost', -14.4)
            ->assertJsonPath('data.changes.checkout_cost', -10)
            ->assertJsonPath('data.changes.conversions', 43.8)
            ->assertJsonCount(3, 'data.history')
            ->assertJsonPath('data.history.0.label', '31周 07.27~08.02')
            ->assertJsonPath('data.history.2.label', '33周 08.10~08.16')
            ->assertJsonPath('data.history.2.values.cpa', 171.74);

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->getJson(route('paid-advertising.google.data', ['view' => 'weekly', 'week' => '2026-08-03']))
            ->assertOk()
            ->assertJsonPath('data.selected_week.label', '32周 08.03~08.09')
            ->assertJsonPath('data.previous_week.label', '31周 07.27~08.02')
            ->assertJsonPath('data.values.spend', 33631.83)
            ->assertJsonCount(2, 'data.history')
            ->assertJsonPath('data.history.1.label', '32周 08.03~08.09');
    }

    public function test_google_ads_manual_sync_queues_only_incremental_pipeline(): void
    {
        Queue::fake();
        [$user, $organization, $store] = $this->context('store-admin');
        $this->configureGoogle($organization, $store);

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->postJson(route('paid-advertising.google.sync'))
            ->assertAccepted()
            ->assertJsonPath('sync.mode', 'incremental')
            ->assertJsonPath('sync.queued', true);

        Queue::assertPushed(SyncAdvertisingChannelForStore::class, fn (SyncAdvertisingChannelForStore $job): bool => $job->organizationId === $organization->id
            && $job->storeId === $store->id
            && $job->channel === 'google'
            && $job->mode === 'incremental');
    }

    public function test_google_search_terms_and_keywords_read_scoped_mysql_with_search_sort_and_pagination(): void
    {
        [$user, $organization, $store] = $this->context('store-admin');
        $otherStore = $this->addStore($user, $organization, 'Other Google Tables Store', 'other-google-tables.myshopify.com');
        $this->configureGoogle($organization, $store);
        $account = AdvertisingChannelAccount::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'provider' => 'google',
            'external_account_id' => '6442213333',
            'name' => 'Macfox Google Ads account',
            'status' => 'active',
            'currency' => 'USD',
            'timezone' => 'America/Los_Angeles',
            'raw_payload' => [],
            'last_seen_at' => now(),
            'synced_at' => now(),
        ]);
        $otherAccount = AdvertisingChannelAccount::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $otherStore->id,
            'provider' => 'google',
            'external_account_id' => '9999999999',
            'name' => 'Other Google Ads account',
            'status' => 'active',
            'currency' => 'USD',
            'raw_payload' => [],
            'last_seen_at' => now(),
            'synced_at' => now(),
        ]);

        $this->createGoogleSearchTermMetric($organization, $store, $account, '2026-08-20', 'Macfox Ebike', 2, 20, 100, 10, 2);
        $this->createGoogleSearchTermMetric($organization, $store, $account, '2026-08-21', 'macfox ebike', 3, 30, 200, 20, 3);
        $this->createGoogleSearchTermMetric($organization, $store, $account, '2026-08-22', 'macfox ebike', 1, 10, 50, 5, 1);
        GoogleAdsSearchTermDailyMetric::query()
            ->where('advertising_channel_account_id', $account->id)
            ->whereDate('metric_date', '2026-08-22')
            ->update([
                'source_type' => 'PERFORMANCE_MAX',
                'matched_keyword' => null,
                'match_type' => 'PERFORMANCE_MAX',
                'status' => 'PERFORMANCE_MAX',
            ]);
        $this->createGoogleSearchTermMetric($organization, $store, $account, '2026-08-21', 'No Revenue', 5, 0, 50, 5, 0);
        $this->createGoogleSearchTermMetric($organization, $store, $account, '2026-08-19', 'macfox ebike', 4, 0, 150, 15, 0);
        $this->createGoogleSearchTermMetric($organization, $otherStore, $otherAccount, '2026-08-20', 'Macfox Ebike', 100, 5000, 10000, 1000, 100);
        $this->createGoogleKeywordMetric($organization, $store, $account, '2026-08-20', 'macfox ebike', 4, 48, 400, 40, 4);
        $this->createGoogleKeywordMetric($organization, $store, $account, '2026-08-21', 'macfox ebike', 0, 0, 100, 0, 0);
        $this->createGoogleKeywordMetric($organization, $store, $account, '2026-08-21', 'no spend keyword', 0, 0, 20, 0, 0);
        $this->createGoogleKeywordMetric($organization, $otherStore, $otherAccount, '2026-08-20', 'macfox ebike', 999, 9999, 9999, 999, 99);

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->getJson(route('paid-advertising.google.data', [
                'account' => $account->external_account_id,
                'date_from' => '2026-08-17',
                'date_to' => '2026-08-23',
                'view' => 'search-terms',
                'search' => 'macfox',
                'sort' => 'roas',
                'direction' => 'desc',
                'page' => 1,
                'per_page' => 10,
            ]))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.schema', 'google-ads-performance-table-v1')
            ->assertJsonPath('data.view', 'search-terms')
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.rows.0.search_term', 'macfox ebike')
            ->assertJsonPath('data.rows.0.matched_keyword', 'macfox ebike')
            ->assertJsonPath('data.rows.0.match_type_label', '精确')
            ->assertJsonPath('data.rows.0.status_label', '已添加')
            ->assertJsonPath('data.rows.0.spend', 10)
            ->assertJsonPath('data.rows.0.revenue', 60)
            ->assertJsonPath('data.rows.0.roas', 6)
            ->assertJsonPath('data.rows.0.impressions', 500)
            ->assertJsonPath('data.rows.0.clicks', 50)
            ->assertJsonPath('data.rows.0.ctr', 10)
            ->assertJsonPath('data.rows.0.conversions', 6);

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->getJson(route('paid-advertising.google.data', [
                'account' => $account->external_account_id,
                'date_from' => '2026-08-17',
                'date_to' => '2026-08-23',
                'view' => 'keywords',
                'search' => 'Search Brand',
                'sort' => 'spend',
                'direction' => 'desc',
                'page' => 1,
                'per_page' => 10,
            ]))
            ->assertOk()
            ->assertJsonPath('data.view', 'keywords')
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.rows.0.keyword', 'macfox ebike')
            ->assertJsonPath('data.rows.0.match_type_label', '精确')
            ->assertJsonPath('data.rows.0.status_label', '启用')
            ->assertJsonPath('data.rows.0.spend', 4)
            ->assertJsonPath('data.rows.0.revenue', 48)
            ->assertJsonPath('data.rows.0.roas', 12)
            ->assertJsonPath('data.rows.0.impressions', 500)
            ->assertJsonPath('data.rows.0.clicks', 40)
            ->assertJsonPath('data.rows.0.ctr', 8)
            ->assertJsonPath('data.rows.0.cpc', 0.1)
            ->assertJsonPath('data.rows.0.conversions', 4);
    }

    public function test_tiktok_ads_overview_reads_scoped_mysql_metrics_and_previous_period(): void
    {
        [$user, $organization, $store] = $this->context('store-admin');
        $otherStore = $this->addStore($user, $organization, 'Other TikTok Store', 'other-tiktok.myshopify.com');
        $this->configureTikTok($organization, $store);
        $account = AdvertisingChannelAccount::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'provider' => 'tiktok',
            'external_account_id' => '7623302290988089361',
            'name' => 'Macfox TikTok advertiser',
            'status' => 'active',
            'currency' => 'USD',
            'timezone' => 'America/Los_Angeles',
            'raw_payload' => [],
            'last_seen_at' => now(),
            'synced_at' => now(),
        ]);
        $otherAccount = AdvertisingChannelAccount::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $otherStore->id,
            'provider' => 'tiktok',
            'external_account_id' => '9999999999999999999',
            'name' => 'Other TikTok advertiser',
            'status' => 'active',
            'currency' => 'USD',
            'raw_payload' => [],
            'last_seen_at' => now(),
            'synced_at' => now(),
        ]);
        $this->createTikTokMetric($organization, $store, $account, '2026-08-20', 100, 500, 10, 20, 15);
        $this->createTikTokMetric($organization, $store, $account, '2026-08-13', 80, 320, 8, 10, 8);
        $this->createTikTokMetric($organization, $otherStore, $otherAccount, '2026-08-20', 9000, 90000, 900, 900, 900);
        $this->createTikTokCampaignMetric($organization, $store, $account, '2026-08-20', 'campaign-a', 'TikTok Bikes', 60);
        $this->createTikTokCampaignMetric($organization, $store, $account, '2026-08-20', 'campaign-b', 'TikTok Retargeting', 40);
        $this->createTikTokCampaignMetric($organization, $otherStore, $otherAccount, '2026-08-20', 'other-campaign', 'Other Store Campaign', 9000);
        $this->createTikTokAdMetric($organization, $store, $account, '2026-08-20', 'ad-a', 'Freedom on two wheels', 25, 200);
        $this->createTikTokAdMetric($organization, $store, $account, '2026-08-20', 'ad-b', 'Ride every street', 30, 120);
        $this->createTikTokAdMetric($organization, $otherStore, $otherAccount, '2026-08-20', 'other-ad', 'Other Store Creative', 9999, 99999);

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->get(route('paid-advertising.tiktok', [
                'account' => '7623302290988089361',
                'date_from' => '2026-08-17',
                'date_to' => '2026-08-23',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('PaidAdvertising/TikTok')
                ->where('tiktokOverview.schema', 'tiktok-ads-overview-v1')
                ->where('tiktokOverview.filters.account', '7623302290988089361')
                ->where('tiktokOverview.comparison_period.date_from', '2026-08-10')
                ->where('tiktokOverview.comparison_period.date_to', '2026-08-16')
                ->where('tiktokOverview.current.spend', 100)
                ->where('tiktokOverview.current.revenue', 500)
                ->where('tiktokOverview.current.roas', 5)
                ->where('tiktokOverview.current.cpa', 10)
                ->where('tiktokOverview.current.add_to_cart', 20)
                ->where('tiktokOverview.current.checkout', 15)
                ->where('tiktokOverview.current.add_to_cart_cost', 5)
                ->where('tiktokOverview.current.checkout_cost', 6.67)
                ->where('tiktokOverview.current.impressions', 1000)
                ->where('tiktokOverview.current.clicks', 100)
                ->where('tiktokOverview.current.conversions', 10)
                ->where('tiktokOverview.current.ctr', 10)
                ->where('tiktokOverview.current.cpc', 1)
                ->where('tiktokOverview.previous.spend', 80)
                ->where('tiktokOverview.deltas.spend', 25)
                ->has('tiktokOverview.trend', 1)
                ->where('tiktokOverview.trend.0.date', '2026-08-20')
                ->where('tiktokOverview.trend.0.spend', 100)
                ->where('tiktokOverview.trend.0.revenue', 500)
                ->where('tiktokOverview.trend.0.roi', 5)
                ->where('tiktokOverview.trend.0.impressions', 1000)
                ->where('tiktokOverview.trend.0.clicks', 100)
                ->where('tiktokOverview.trend.0.conversions', 10)
                ->has('tiktokOverview.previous_trend', 1)
                ->where('tiktokOverview.previous_trend.0.date', '2026-08-13')
                ->where('tiktokOverview.previous_trend.0.spend', 80)
                ->where('tiktokOverview.previous_trend.0.roi', 4)
                ->has('tiktokOverview.campaigns', 2)
                ->where('tiktokOverview.campaigns.0.id', 'campaign-a')
                ->where('tiktokOverview.campaigns.0.spend', 60)
                ->where('tiktokOverview.campaigns.0.share', 60)
                ->where('tiktokOverview.campaigns.1.id', 'campaign-b')
                ->where('tiktokOverview.campaigns.1.share', 40)
                ->has('tiktokOverview.campaign_details', 2)
                ->where('tiktokOverview.campaign_details.0.id', 'campaign-a')
                ->where('tiktokOverview.campaign_details.0.status', 'ENABLE')
                ->where('tiktokOverview.campaign_details.0.spend', 60)
                ->where('tiktokOverview.campaign_details.0.revenue', 300)
                ->where('tiktokOverview.campaign_details.0.roas', 5)
                ->where('tiktokOverview.campaign_details.0.clicks', 100)
                ->where('tiktokOverview.campaign_details.0.ctr', 10)
                ->where('tiktokOverview.campaign_details.0.conversions', 10)
                ->where('tiktokOverview.campaign_details.0.cpc', 0.6)
                ->has('tiktokOverview.creatives', 2)
                ->where('tiktokOverview.creatives.0.id', 'ad-a')
                ->where('tiktokOverview.creatives.0.name', 'Freedom on two wheels')
                ->where('tiktokOverview.creatives.0.roas', 8)
                ->where('tiktokOverview.creatives.0.video_plays', 400)
                ->where('tiktokOverview.creatives.0.video_2s_rate', 40)
                ->where('tiktokOverview.creatives.0.average_play_time', 2.5)
                ->where('tiktokOverview.creatives.1.id', 'ad-b')
                ->has('tiktokOverview.accounts', 1));
    }

    public function test_facebook_page_prompts_for_configuration_and_exposes_scoped_sync_status(): void
    {
        [$user, $organization, $store] = $this->context('operator');
        $session = $this->contextSession($organization, $store);

        $this->actingAs($user)
            ->withSession($session)
            ->get(route('paid-advertising.facebook'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('PaidAdvertising/Facebook')
                ->where('store.id', $store->id)
                ->where('metaAdsStatus.schema', 'meta-ads-sync-status-v2')
                ->where('metaAdsStatus.configured', false)
                ->where('metaAdsStatus.state', 'not_configured')
                ->where('metaAdsStatus.settings_url', route('store-settings.credentials', ['provider' => 'meta_ads'])));

        StoreBusinessCredential::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'provider' => 'meta_ads',
            'credential_key' => 'access_token',
            'credential_value' => 'hidden-meta-token',
        ]);
        DB::table('store_sync_states')->insert([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'sync_type' => 'meta_ads',
            'status' => 'queued',
            'last_metric_date' => '2026-08-22',
            'data_synced_at' => '2026-08-22 12:34:56',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $statusResponse = $this->actingAs($user)
            ->withSession($session)
            ->getJson(route('paid-advertising.facebook.status'))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.configured', true)
            ->assertJsonPath('data.state', 'syncing')
            ->assertJsonPath('data.mode', 'priority')
            ->assertJsonPath('data.last_metric_date', '2026-08-22')
            ->assertJsonPath('data.data_synced_at', '2026-08-22T12:34:56+00:00')
            ->assertJsonMissing(['hidden-meta-token']);
        $statusQueries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertFalse(collect($statusQueries)->contains(
            fn (array $query): bool => str_contains(strtolower((string) $query['query']), 'meta_ad_insights'),
        ));
        $this->assertStringNotContainsString('hidden-meta-token', $statusResponse->getContent());

        $job = SyncJob::query()->create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'type' => 'meta_ads',
            'direction' => 'pull',
            'mode' => 'full',
            'status' => 'running',
            'total_items' => 100,
            'processed_items' => 20,
            'started_at' => now()->subMinutes(5),
        ]);
        DB::table('store_sync_states')->where('store_id', $store->id)->where('sync_type', 'meta_ads')->update([
            'status' => 'running',
            'last_job_id' => $job->id,
            'updated_at' => now(),
        ]);

        $this->actingAs($user)
            ->withSession($session)
            ->getJson(route('paid-advertising.facebook.status'))
            ->assertOk()
            ->assertJsonPath('data.progress_percent', 20)
            ->assertJsonPath('data.completed_shards', 20)
            ->assertJsonPath('data.total_shards', 100)
            ->assertJson(fn ($json) => $json->whereType('data.eta_seconds', 'integer')->etc());
    }

    public function test_facebook_overview_reads_only_current_stores_account_level_daily_insights(): void
    {
        [$user, $organization, $store] = $this->context('store-admin');
        $otherStore = $this->addStore($user, $organization, 'Other Store', 'other-facebook.myshopify.com');
        $session = $this->contextSession($organization, $store);
        $account = MetaAdAccount::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'meta_account_id' => 'act_current',
            'name' => 'Macfox-3',
            'account_status' => 1,
            'currency' => 'USD',
            'raw_payload' => [],
            'last_seen_at' => now(),
            'synced_at' => now(),
        ]);
        $otherAccount = MetaAdAccount::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $otherStore->id,
            'meta_account_id' => 'act_other',
            'name' => 'Other Account',
            'account_status' => 1,
            'currency' => 'USD',
            'raw_payload' => [],
            'last_seen_at' => now(),
            'synced_at' => now(),
        ]);

        $this->createMetaInsight($organization, $store, $account, '2026-08-20', 'account', 'day', 30, 60, 3, 300, 240, 30, 6);
        $this->createMetaInsight($organization, $store, $account, '2026-08-21', 'account', 'day', 50, 100, 2, 500, 400, 50, 10);
        $this->createMetaInsight($organization, $store, $account, '2026-08-22', 'account', 'day', 100, 500, 5, 1000, 800, 100, 20);
        $this->createMetaInsight($organization, $store, $account, '2026-08-22', 'campaign', 'day', 999, 9999, 99, 9999, 9999, 999, 999);
        $this->createMetaInsight($organization, $store, $account, '2026-08-22', 'campaign', 'day', 333, 3333, 33, 3333, 3333, 333, 333, 'campaign-two', 'Secondary Campaign');
        $this->createMetaInsight($organization, $store, $account, '2026-08-22', 'account', 'hour', 888, 8888, 88, 8888, 8888, 888, 888);
        $this->createMetaInsight($organization, $otherStore, $otherAccount, '2026-08-22', 'account', 'day', 777, 7777, 77, 7777, 7777, 777, 777);
        $this->createMetaInsight($organization, $otherStore, $otherAccount, '2026-08-22', 'campaign', 'day', 5000, 5000, 50, 5000, 5000, 500, 500, 'other-campaign', 'Other Store Campaign');
        MetaAdInsight::query()
            ->where('store_id', $store->id)
            ->where('meta_ad_account_id', $account->id)
            ->update(['account_external_id' => 'current']);
        MetaAdInsight::query()
            ->where('store_id', $store->id)
            ->where('level', 'account')
            ->where('granularity', 'day')
            ->whereDate('date_start', '2026-08-20')
            ->update(['add_to_cart' => 5, 'initiate_checkout' => 2]);
        MetaAdInsight::query()
            ->where('store_id', $store->id)
            ->where('level', 'account')
            ->where('granularity', 'day')
            ->whereDate('date_start', '2026-08-21')
            ->update(['add_to_cart' => 10, 'initiate_checkout' => 5]);
        MetaAdInsight::query()
            ->where('store_id', $store->id)
            ->where('level', 'account')
            ->where('granularity', 'day')
            ->whereDate('date_start', '2026-08-22')
            ->update(['add_to_cart' => 20, 'initiate_checkout' => 10]);

        $query = [
            'account' => 'act_current',
            'date_from' => '2026-08-21',
            'date_to' => '2026-08-22',
            'compare' => 'previous',
        ];

        $this->actingAs($user)
            ->withSession($session)
            ->get(route('paid-advertising.facebook', $query))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('PaidAdvertising/Facebook')
                ->where('canSync', true)
                ->has('overview.accounts', 1)
                ->where('overview.accounts.0.id', 'act_current')
                ->where('overview.summary.spend', 150)
                ->where('overview.summary.purchase_value', 600)
                ->where('overview.summary.purchases', 7)
                ->where('overview.summary.roas', 4)
                ->where('overview.summary.ctr', 10)
                ->where('overview.summary.add_to_cart', 30)
                ->where('overview.summary.initiate_checkout', 15)
                ->where('overview.summary.cost_per_add_to_cart', 5)
                ->where('overview.summary.cost_per_checkout', 10)
                ->where('overview.comparison.spend', 30)
                ->where('overview.deltas.spend', 400)
                ->has('overview.daily', 2)
                ->where('overview.daily.0.ctr', 10)
                ->where('overview.daily.0.cpc', 1)
                ->has('overview.comparison_daily', 1)
                ->where('overview.comparison_daily.0.date', '2026-08-20')
                ->where('overview.campaign_spend.total_spend', 1332)
                ->has('overview.campaign_spend.items', 2)
                ->where('overview.campaign_spend.items.0.name', 'campaign-2026-08-22')
                ->where('overview.campaign_spend.items.0.share', 75)
                ->where('overview.campaign_spend.items.1.name', 'Secondary Campaign')
                ->where('overview.campaign_spend.items.1.share', 25)
                ->where('overview.campaigns.total', 2)
                ->where('overview.campaigns.limit', 100)
                ->where('overview.campaigns.truncated', false)
                ->has('overview.campaigns.items', 2)
                ->where('overview.campaigns.items.0.spend', 999)
                ->where('overview.campaigns.items.0.roas', 10.01)
                ->where('overview.campaigns.items.0.ctr', 9.99)
                ->where('overview.campaigns.items.0.cpc', 1)
                ->where('overview.campaigns.items.0.frequency', null)
                ->where('overview.campaigns.items.0.purchases', 99));

        $this->actingAs($user)
            ->withSession($session)
            ->getJson(route('paid-advertising.facebook.data', $query))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.summary.spend', 150)
            ->assertJsonPath('data.summary.purchase_value', 600)
            ->assertJsonPath('data.summary.add_to_cart', 30)
            ->assertJsonPath('data.summary.cost_per_checkout', 10)
            ->assertJsonPath('data.daily.0.date', '2026-08-21')
            ->assertJsonPath('data.daily.0.ctr', 10)
            ->assertJsonPath('data.daily.0.cpc', 1)
            ->assertJsonPath('data.comparison_daily.0.date', '2026-08-20')
            ->assertJsonPath('data.campaign_spend.total_spend', 1332)
            ->assertJsonPath('data.campaign_spend.items.0.spend', 999)
            ->assertJsonPath('data.campaigns.total', 2)
            ->assertJsonPath('data.campaigns.items.0.name', 'campaign-2026-08-22')
            ->assertJsonPath('data.campaigns.items.1.name', 'Secondary Campaign')
            ->assertJsonCount(1, 'data.accounts')
            ->assertJsonMissing(['act_other', 7777, 'Other Store Campaign']);
    }

    public function test_facebook_campaigns_merge_legacy_account_id_variants_and_use_exact_period_frequency(): void
    {
        [$user, $organization, $store] = $this->context('store-admin');
        $account = MetaAdAccount::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'meta_account_id' => 'act_279',
            'name' => 'Macfox-3',
            'account_status' => 1,
            'currency' => 'USD',
            'raw_payload' => [],
            'last_seen_at' => now(),
            'synced_at' => now(),
        ]);

        $this->createMetaInsight($organization, $store, $account, '2026-08-20', 'campaign', 'day', 100, 600, 2, 1000, 500, 50, 40, 'campaign-shared', 'Shared Campaign');
        $this->createMetaInsight($organization, $store, $account, '2026-08-21', 'campaign', 'day', 50, 100, 1, 500, 250, 25, 20, 'campaign-shared', 'Shared Campaign');
        MetaAdInsight::query()
            ->where('entity_id', 'campaign-shared')
            ->whereDate('date_start', '2026-08-20')
            ->update(['account_external_id' => '279']);
        $this->createMetaInsight(
            $organization,
            $store,
            $account,
            '2026-08-20',
            'campaign',
            'period',
            150,
            700,
            3,
            1500,
            259,
            75,
            60,
            'campaign-shared',
            'Shared Campaign',
            '2026-08-21',
            5.8,
        );

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->getJson(route('paid-advertising.facebook.data', [
                'account' => 'act_279',
                'date_from' => '2026-08-20',
                'date_to' => '2026-08-21',
                'compare' => 'none',
            ]))
            ->assertOk()
            ->assertJsonPath('data.campaigns.total', 1)
            ->assertJsonPath('data.campaigns.items.0.id', 'campaign-shared')
            ->assertJsonPath('data.campaigns.items.0.spend', 150)
            ->assertJsonPath('data.campaigns.items.0.purchase_value', 700)
            ->assertJsonPath('data.campaigns.items.0.roas', 4.67)
            ->assertJsonPath('data.campaigns.items.0.impressions', 1500)
            ->assertJsonPath('data.campaigns.items.0.clicks', 75)
            ->assertJsonPath('data.campaigns.items.0.ctr', 5)
            ->assertJsonPath('data.campaigns.items.0.cpc', 2)
            ->assertJsonPath('data.campaigns.items.0.frequency', 5.8)
            ->assertJsonPath('data.campaigns.items.0.purchases', 3)
            ->assertJsonPath('data.campaign_spend.total_spend', 150);
    }

    public function test_facebook_all_accounts_does_not_use_a_partial_period_snapshot(): void
    {
        [$user, $organization, $store] = $this->context('store-admin');
        $firstAccount = MetaAdAccount::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'meta_account_id' => 'act_first',
            'name' => 'First Account',
            'account_status' => 1,
            'currency' => 'USD',
            'raw_payload' => [],
            'last_seen_at' => now(),
            'synced_at' => now(),
        ]);
        $secondAccount = MetaAdAccount::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'meta_account_id' => 'act_second',
            'name' => 'Second Account',
            'account_status' => 1,
            'currency' => 'USD',
            'raw_payload' => [],
            'last_seen_at' => now(),
            'synced_at' => now(),
        ]);

        $this->createMetaInsight($organization, $store, $firstAccount, '2026-08-20', 'campaign', 'day', 100, 600, 2, 1000, 500, 50, 40, 'campaign-first', 'First Campaign');
        $this->createMetaInsight($organization, $store, $secondAccount, '2026-08-20', 'campaign', 'day', 200, 800, 4, 2000, 1000, 100, 80, 'campaign-second', 'Second Campaign');
        $this->createMetaInsight(
            $organization,
            $store,
            $firstAccount,
            '2026-08-20',
            'campaign',
            'period',
            999,
            9999,
            99,
            9999,
            999,
            999,
            999,
            'campaign-first',
            'First Campaign',
            '2026-08-20',
            9.9,
        );

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->getJson(route('paid-advertising.facebook.data', [
                'account' => 'all',
                'date_from' => '2026-08-20',
                'date_to' => '2026-08-20',
                'compare' => 'none',
            ]))
            ->assertOk()
            ->assertJsonPath('data.campaigns.total', 2)
            ->assertJsonPath('data.campaigns.items.0.id', 'campaign-second')
            ->assertJsonPath('data.campaigns.items.0.spend', 200)
            ->assertJsonPath('data.campaigns.items.0.frequency', null)
            ->assertJsonPath('data.campaigns.items.1.id', 'campaign-first')
            ->assertJsonPath('data.campaigns.items.1.spend', 100)
            ->assertJsonPath('data.campaigns.items.1.frequency', null)
            ->assertJsonPath('data.campaign_spend.total_spend', 300);
    }

    public function test_facebook_manual_sync_is_scoped_and_queues_incremental_without_clearing_data(): void
    {
        Queue::fake();
        [$user, $organization, $store] = $this->context('store-admin');
        StoreBusinessCredential::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'provider' => 'meta_ads',
            'credential_key' => 'access_token',
            'credential_value' => 'manual-meta-token',
        ]);
        MetaAdAccount::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'meta_account_id' => 'act_manual',
            'name' => 'Manual Account',
            'raw_payload' => [],
            'last_seen_at' => now(),
            'synced_at' => now(),
        ]);

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->postJson(route('paid-advertising.facebook.sync'), [
                'account' => 'act_manual',
                'date_from' => '2026-08-01',
                'date_to' => '2026-08-23',
            ])
            ->assertAccepted()
            ->assertJsonPath('sync.queued', true)
            ->assertJsonPath('sync.mode', 'incremental')
            ->assertJsonPath('sync.period_queued', true);

        Queue::assertPushed(SyncMetaAdsCampaignPeriod::class, fn (SyncMetaAdsCampaignPeriod $job): bool => $job->organizationId === $organization->id
            && $job->storeId === $store->id
            && $job->account === 'act_manual'
            && $job->since === '2026-08-01'
            && $job->until === '2026-08-23');

        Queue::assertPushed(SyncMetaAdsForStore::class, fn (SyncMetaAdsForStore $job): bool => $job->organizationId === $organization->id
            && $job->storeId === $store->id
            && $job->mode === 'incremental'
            && $job->reconcileIncremental === false);
        $this->assertDatabaseHas('meta_ad_accounts', [
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'meta_account_id' => 'act_manual',
        ]);
    }

    public function test_facebook_creative_library_is_paginated_ranked_and_store_scoped(): void
    {
        [$user, $organization, $store] = $this->context('operator');
        $otherStore = $this->addStore($user, $organization, 'Other Store', 'other-creatives.myshopify.com');
        $account = MetaAdAccount::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'meta_account_id' => 'act_current',
            'name' => 'Macfox-3',
            'account_status' => 1,
            'currency' => 'USD',
            'raw_payload' => [],
            'last_seen_at' => now(),
            'synced_at' => now(),
        ]);
        $otherAccount = MetaAdAccount::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $otherStore->id,
            'meta_account_id' => 'act_other',
            'name' => 'Other Account',
            'account_status' => 1,
            'currency' => 'USD',
            'raw_payload' => [],
            'last_seen_at' => now(),
            'synced_at' => now(),
        ]);

        foreach ([
            [$store, $account, 'ad-best', 'creative-best', '最佳素材', 100, 1000, 10, 1000, 100, true],
            [$store, $account, 'ad-second', 'creative-second', '第二素材', 100, 500, 5, 500, 25, false],
            [$otherStore, $otherAccount, 'ad-other', 'creative-other', '其他店铺素材', 1, 9999, 99, 999, 999, false],
        ] as [$targetStore, $targetAccount, $adId, $creativeId, $title, $spend, $revenue, $purchases, $impressions, $clicks, $video]) {
            MetaAdCreative::query()->create([
                'organization_id' => $organization->id,
                'store_id' => $targetStore->id,
                'meta_ad_account_id' => $targetAccount->id,
                'meta_creative_id' => $creativeId,
                'name' => $title,
                'title' => $title,
                'body' => $adId === 'ad-second' ? null : '素材文案 '.$title,
                'image_url' => 'https://example.test/'.$creativeId.'.jpg',
                'thumbnail_url' => 'https://example.test/'.$creativeId.'-thumb.jpg',
                'asset_feed_spec' => $video ? ['videos' => [['video_id' => 'video-1']]] : ['images' => [['hash' => 'image-1']]],
                'raw_payload' => ['private' => 'never-return-this'],
                'last_seen_at' => now(),
                'synced_at' => now(),
            ]);
            MetaAd::query()->create([
                'organization_id' => $organization->id,
                'store_id' => $targetStore->id,
                'meta_ad_account_id' => $targetAccount->id,
                'meta_ad_id' => $adId,
                'meta_campaign_id' => 'campaign-'.$adId,
                'meta_creative_id' => $creativeId,
                'name' => '广告 '.$title,
                'raw_payload' => [],
                'last_seen_at' => now(),
                'synced_at' => now(),
            ]);
            DB::table('meta_ad_insight_entities')->insert([
                'organization_id' => $organization->id,
                'store_id' => $targetStore->id,
                'level' => 'ad',
                'entity_id' => $adId,
                'account_name' => $targetAccount->name,
                'meta_campaign_id' => 'campaign-'.$adId,
                'campaign_name' => '系列 '.$title,
                'meta_ad_id' => $adId,
                'ad_name' => '广告 '.$title,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            MetaAdInsight::query()->create([
                'organization_id' => $organization->id,
                'store_id' => $targetStore->id,
                'meta_ad_account_id' => $targetAccount->id,
                'level' => 'ad',
                'entity_id' => $adId,
                'account_external_id' => $targetAccount->meta_account_id === 'act_current' ? 'current' : 'other',
                'meta_campaign_id' => 'campaign-'.$adId,
                'meta_ad_id' => $adId,
                'date_start' => '2026-08-22',
                'date_stop' => '2026-08-22',
                'granularity' => 'day',
                'spend' => $spend,
                'purchase_value' => $revenue,
                'purchases' => $purchases,
                'impressions' => $impressions,
                'clicks' => $clicks,
                'synced_at' => now(),
            ]);
        }

        $response = $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->getJson(route('paid-advertising.facebook.creatives', [
                'account' => 'act_current',
                'date_from' => '2026-08-20',
                'date_to' => '2026-08-23',
            ]))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.schema', 'meta-ads-creative-library-v1')
            ->assertJsonPath('data.pagination.per_page', 6)
            ->assertJsonPath('data.pagination.total', 2)
            ->assertJsonPath('data.items.0.ad_id', 'ad-best')
            ->assertJsonPath('data.items.0.title', '最佳素材')
            ->assertJsonPath('data.items.0.format', '视频素材')
            ->assertJsonPath('data.items.0.roas', 10)
            ->assertJsonPath('data.items.0.ctr', 10)
            ->assertJsonPath('data.items.0.revenue', 1000)
            ->assertJsonPath('data.items.1.ad_id', 'ad-second')
            ->assertJsonMissing(['其他店铺素材', 'never-return-this']);

        $this->assertStringNotContainsString('never-return-this', $response->getContent());

        $copyResponse = $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->getJson(route('paid-advertising.facebook.copies', [
                'account' => 'act_current',
                'date_from' => '2026-08-20',
                'date_to' => '2026-08-23',
            ]))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.schema', 'meta-ads-copy-library-v1')
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.ad_id', 'ad-best')
            ->assertJsonPath('data.items.0.body', '素材文案 最佳素材')
            ->assertJsonMissing(['ad-second', '其他店铺素材', 'never-return-this']);

        $this->assertStringNotContainsString('never-return-this', $copyResponse->getContent());
    }

    public function test_user_without_sync_permission_cannot_submit_facebook_sync(): void
    {
        [$user, $organization, $store] = $this->context('operator');

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->postJson(route('paid-advertising.facebook.sync'))
            ->assertForbidden();
    }

    public function test_goals_page_always_has_the_current_stores_overall_tab_and_prompts_for_missing_feishu_configuration(): void
    {
        [$user, $organization, $store] = $this->context('operator');

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->get(route('paid-advertising.goals'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('PaidAdvertising/Goals')
                ->where('store.id', $store->id)
                ->where('store.name', $store->name)
                ->where('goalPage.schema', 'paid-advertising-goals-v1')
                ->where('goalPage.active_tab', 'overall')
                ->has('goalPage.tabs', 1)
                ->where('goalPage.tabs.0.key', 'overall')
                ->where('goalPage.tabs.0.label', '总目标')
                ->where('goalPage.tabs.0.kind', 'overall')
                ->where('goalPage.tabs.0.deletable', false)
                ->where('goalPage.tabs.0.clearable', false)
                ->has('goalPage.type_options', 2)
                ->where('goalPage.configuration.schema', 'feishu-data-link-status-v1')
                ->where('goalPage.configuration.section', 'advertising_goals')
                ->where('goalPage.configuration.configured', false)
                ->where('goalPage.configuration.has_configuration', false)
                ->where('goalPage.configuration.missing_fields', [
                    'advertising_goals_app_token',
                ])
                ->where('goalPage.period.schema', 'paid-advertising-goal-period-v1')
                ->where('goalPage.period.mode', 'month')
                ->where('goalPage.metrics.schema', 'paid-advertising-goal-metrics-v1')
                ->where('goalPage.metrics.available', false)
                ->where('goalPage.metrics.channel_performance.schema', 'paid-advertising-channel-performance-v1')
                ->where('goalPage.metrics.channel_performance.available', false)
                ->where('goalPage.metrics.progress.schema', 'paid-advertising-goal-progress-v1')
                ->where('goalPage.metrics.progress.available', false)
                ->where('canManage', false)
                ->where('canRefresh', false));
    }

    public function test_goals_time_filter_defaults_to_store_month_and_accepts_month_or_bounded_date_range(): void
    {
        [$user, $organization, $store] = $this->context('operator');
        $store->update(['timezone' => 'America/Los_Angeles']);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-22 12:00:00', 'America/Los_Angeles'));

        try {
            $this->actingAs($user)
                ->withSession($this->contextSession($organization, $store))
                ->get(route('paid-advertising.goals'))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('goalPage.period.mode', 'month')
                    ->where('goalPage.period.month', '2026-08')
                    ->where('goalPage.period.date_from', '2026-08-01')
                    ->where('goalPage.period.date_to', '2026-08-31')
                    ->where('goalPage.period.label', '2026年8月')
                    ->where('goalPage.period.timezone', 'America/Los_Angeles'));

            $this->actingAs($user)
                ->withSession($this->contextSession($organization, $store))
                ->get(route('paid-advertising.goals', [
                    'period_mode' => 'month',
                    'month' => '2025-12',
                ]))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('goalPage.period.mode', 'month')
                    ->where('goalPage.period.month', '2025-12')
                    ->where('goalPage.period.date_from', '2025-12-01')
                    ->where('goalPage.period.date_to', '2025-12-31')
                    ->where('goalPage.period.label', '2025年12月'));

            $this->actingAs($user)
                ->withSession($this->contextSession($organization, $store))
                ->get(route('paid-advertising.goals', [
                    'period_mode' => 'range',
                    'date_from' => '2026-08-05',
                    'date_to' => '2026-08-21',
                ]))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('goalPage.period.mode', 'range')
                    ->where('goalPage.period.month', null)
                    ->where('goalPage.period.date_from', '2026-08-05')
                    ->where('goalPage.period.date_to', '2026-08-21')
                    ->where('goalPage.period.label', '2026-08-05 至 2026-08-21'));

            $this->actingAs($user)
                ->withSession($this->contextSession($organization, $store))
                ->get(route('paid-advertising.goals', [
                    'period_mode' => 'range',
                    'date_from' => '2025-01-01',
                    'date_to' => '2026-08-21',
                ]))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('goalPage.period.mode', 'month')
                    ->where('goalPage.period.month', '2026-08'));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_complete_goals_configuration_is_store_scoped_and_exposes_no_feishu_values(): void
    {
        Queue::fake();
        [$user, $organization, $store] = $this->context('organization-admin');
        $otherStore = $this->addStore($user, $organization, 'Other Advertising Store', 'other-paid-advertising.myshopify.com');
        $values = [
            'feishu_app_token' => 'sensitive-goals-app-token',
        ];

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->put(route('paid-advertising.goals.overall.update'), [
                ...$values,
                'period_mode' => 'range',
                'date_from' => '2026-08-05',
                'date_to' => '2026-08-21',
            ])
            ->assertRedirect(route('paid-advertising.goals', [
                'period_mode' => 'range',
                'date_from' => '2026-08-05',
                'date_to' => '2026-08-21',
            ]))
            ->assertSessionHas('success');

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->get(route('paid-advertising.goals'))
            ->assertOk()
            ->assertDontSee($values['feishu_app_token'])
            ->assertInertia(fn (Assert $page) => $page
                ->where('store.id', $store->id)
                ->where('goalPage.configuration.configured', true)
                ->where('goalPage.configuration.has_configuration', true)
                ->where('goalPage.configuration.missing_fields', [])
                ->where('goalPage.tabs.0.deletable', false)
                ->where('goalPage.tabs.0.clearable', true)
                ->where('goalPage.sync.tab', 'overall')
                ->where('goalPage.sync.status', 'queued')
                ->where('goalPage.sync.initial_sync', true)
                ->whereType('goalPage.sync.estimated_finished_at', 'string')
                ->where('canManage', true)
                ->where('canRefresh', true));

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $otherStore))
            ->get(route('paid-advertising.goals'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('store.id', $otherStore->id)
                ->where('goalPage.configuration.configured', false)
                ->where('goalPage.configuration.has_configuration', false)
                ->where('goalPage.tabs.0.clearable', false));

        $rawCredentials = DB::table('store_business_credentials')
            ->where('store_id', $store->id)
            ->where('credential_key', 'advertising_goals_app_token')
            ->pluck('credential_value');
        $this->assertCount(1, $rawCredentials);
        foreach ($rawCredentials as $encryptedValue) {
            $this->assertStringNotContainsString('goals-current', (string) $encryptedValue);
            $this->assertStringNotContainsString('sensitive-goals-app-token', (string) $encryptedValue);
        }
    }

    public function test_goal_metrics_use_the_selected_tabs_feishu_values_and_selected_period(): void
    {
        [$user, $organization, $store] = $this->context('operator');
        $store->update(['timezone' => 'America/Los_Angeles', 'currency' => 'USD']);
        $board = PaidAdvertisingGoalBoard::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'name' => '黄智诚',
            'type' => 'personal_facebook',
            'feishu_app_token' => 'board-app-token',
            'feishu_table_id' => 'board-table-id',
            'feishu_view_id' => 'board-view-id',
            'sync_status' => 'completed',
        ]);
        $this->createMetricFields($organization, $store, 'overall');
        $this->createMetricFields($organization, $store, 'board:'.$board->id, $board);
        $this->createMetricRecord($organization, $store, 'overall', '2026-08-20', 50000, -125, 1000000, 3000000, null, [
            'X7销量' => 7,
            'X2销量' => 2,
            'X1S销量' => 1,
            'M16销量' => 3,
            'X1S x Bs.zay销量' => 4,
            '订单数' => 40,
            '整站总加购' => 400,
            '整站总结账' => 200,
        ]);
        $this->createMetricRecord($organization, $store, 'overall', '2026-08-21', 43321.85, -59, 1131897.62, 3000000, null, [
            'X7销量' => 8,
            'X2销量' => null,
            'X1S销量' => '',
            'M16销量' => 1,
            'X1S x Bs.zay销量' => null,
            '订单数' => 52,
            '整站总加购' => 451,
            '整站总结账' => 262,
            'FB花费' => [6846.94],
            'FB销售额' => [29142.28],
            'FB的ROI' => 4.2562487768259,
            'GG花费' => [5084.255195],
            'GG销售额' => [7988.410077],
            'GG的ROI' => 1.5712055690784,
            'Tiktok花费' => [789.03],
            'Tiktok销售额' => [5970.08],
            'Tiktok的ROI' => 7.5663536240701,
            'Bing花费' => [329.12],
            'Bing销售额' => [0],
            'Bing的ROI' => 0,
            'Criteo花费' => [438.734],
            'Criteo销售' => [12134],
            'Criteo的ROI' => 27.656849024694,
            '总花费' => 13488.079195,
            '总ROI' => 3.2118620727004,
            'ROI（预5%退款）' => 3.0554244903364,
            '月总花费总和' => 255848.51488504,
            '月总退款总和' => 65390.44,
        ]);
        $this->createMetricRecord($organization, $store, 'overall', '2026-08-22', 999999, -999, 9999999, 3000000);
        $this->createMetricRecord($organization, $store, 'board:'.$board->id, '2026-08-21', 100, 10, 250, 1000, $board);
        $otherStore = $this->addStore($user, $organization, 'Other Metrics Store', 'other-metrics.myshopify.com');
        $this->createMetricFields($organization, $otherStore, 'overall');
        $this->createMetricRecord($organization, $otherStore, 'overall', '2026-08-21', 888888, -888, 8888888, 10000000);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-22 12:00:00', 'America/Los_Angeles'));

        try {
            $this->actingAs($user)
                ->withSession($this->contextSession($organization, $store))
                ->get(route('paid-advertising.goals'))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('goalPage.metrics.available', true)
                    ->where('goalPage.metrics.source', 'feishu')
                    ->where('goalPage.metrics.currency', 'USD')
                    ->where('goalPage.metrics.as_of_date', '2026-08-21')
                    ->where('goalPage.metrics.message', null)
                    ->where('goalPage.metrics.cards.0.key', 'daily_sales')
                    ->where('goalPage.metrics.cards.0.label', '昨日完成')
                    ->where('goalPage.metrics.cards.0.value', 43321.85)
                    ->where('goalPage.metrics.cards.1.key', 'refunds')
                    ->where('goalPage.metrics.cards.1.label', '昨日退款')
                    ->where('goalPage.metrics.cards.1.value', -59)
                    ->where('goalPage.metrics.cards.2.key', 'monthly_sales')
                    ->where('goalPage.metrics.cards.2.label', '截止昨日累计完成')
                    ->where('goalPage.metrics.cards.2.value', 1131897.62)
                    ->where('goalPage.metrics.cards.3.key', 'completion_rate')
                    ->where('goalPage.metrics.cards.3.value', 37.7)
                    ->where('goalPage.metrics.project_sales.schema', 'paid-advertising-project-sales-v1')
                    ->where('goalPage.metrics.project_sales.available', true)
                    ->where('goalPage.metrics.project_sales.as_of_date', '2026-08-21')
                    ->where('goalPage.metrics.project_sales.title', '昨日项目销量明细（08-21）')
                    ->where('goalPage.metrics.project_sales.products.0.label', 'X7')
                    ->where('goalPage.metrics.project_sales.products.0.value', 8)
                    ->where('goalPage.metrics.project_sales.products.1.value', 0)
                    ->where('goalPage.metrics.project_sales.products.2.value', 0)
                    ->where('goalPage.metrics.project_sales.products.3.value', 1)
                    ->where('goalPage.metrics.project_sales.products.4.value', 0)
                    ->where('goalPage.metrics.project_sales.site_metrics.0.value', 52)
                    ->where('goalPage.metrics.project_sales.site_metrics.1.value', 451)
                    ->where('goalPage.metrics.project_sales.site_metrics.2.value', 262)
                    ->where('goalPage.metrics.channel_performance.schema', 'paid-advertising-channel-performance-v1')
                    ->where('goalPage.metrics.channel_performance.available', true)
                    ->where('goalPage.metrics.channel_performance.as_of_date', '2026-08-21')
                    ->where('goalPage.metrics.channel_performance.title', '昨日各渠道投放数据（08-21）')
                    ->where('goalPage.metrics.channel_performance.channels.0.label', 'Facebook')
                    ->where('goalPage.metrics.channel_performance.channels.0.spend', 6846.94)
                    ->where('goalPage.metrics.channel_performance.channels.0.sales', 29142.28)
                    ->where('goalPage.metrics.channel_performance.channels.0.roi', 4.26)
                    ->where('goalPage.metrics.channel_performance.channels.1.roi', 1.57)
                    ->where('goalPage.metrics.channel_performance.channels.2.roi', 7.57)
                    ->where('goalPage.metrics.channel_performance.channels.3.sales', 0)
                    ->where('goalPage.metrics.channel_performance.channels.3.roi', 0)
                    ->where('goalPage.metrics.channel_performance.channels.4.roi', 27.66)
                    ->where('goalPage.metrics.channel_performance.summary.0.value', 13488.08)
                    ->where('goalPage.metrics.channel_performance.summary.1.value', 3.21)
                    ->where('goalPage.metrics.channel_performance.summary.2.value', 3.06)
                    ->where('goalPage.metrics.channel_performance.summary.3.value', 255848.51)
                    ->where('goalPage.metrics.progress.schema', 'paid-advertising-goal-progress-v1')
                    ->where('goalPage.metrics.progress.available', true)
                    ->has('goalPage.metrics.progress.points', 2)
                    ->where('goalPage.metrics.progress.points.0.date', '2026-08-20')
                    ->where('goalPage.metrics.progress.points.0.daily_sales', 50000)
                    ->where('goalPage.metrics.progress.points.0.daily_refunds', 125)
                    ->where('goalPage.metrics.progress.points.0.cumulative_sales', 1000000)
                    ->where('goalPage.metrics.progress.points.1.date', '2026-08-21')
                    ->where('goalPage.metrics.progress.points.1.daily_sales', 43321.85)
                    ->where('goalPage.metrics.progress.points.1.daily_refunds', 59)
                    ->where('goalPage.metrics.progress.points.1.cumulative_sales', 1131897.62)
                    ->where('goalPage.metrics.progress.monthly_target', 3000000)
                    ->where('goalPage.metrics.progress.completion.cumulative_sales', 1131897.62)
                    ->where('goalPage.metrics.progress.completion.monthly_refunds', -65390.44)
                    ->where('goalPage.metrics.progress.completion.target', 3000000)
                    ->where('goalPage.metrics.progress.completion.rate', 37.7)
                    ->where('goalPage.metrics.progress.completion.remaining', 1868102.38));

            $this->actingAs($user)
                ->withSession($this->contextSession($organization, $store))
                ->get(route('paid-advertising.goals', [
                    'period_mode' => 'range',
                    'date_from' => '2026-08-01',
                    'date_to' => '2026-08-20',
                ]))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('goalPage.metrics.as_of_date', '2026-08-20')
                    ->where('goalPage.metrics.cards.0.label', '8月20日完成')
                    ->where('goalPage.metrics.cards.0.value', 50000)
                    ->where('goalPage.metrics.cards.1.value', -125)
                    ->where('goalPage.metrics.cards.2.label', '截止8月20日累计完成')
                    ->where('goalPage.metrics.cards.2.value', 1000000)
                    ->where('goalPage.metrics.cards.3.value', 33.3)
                    ->where('goalPage.metrics.project_sales.as_of_date', '2026-08-20')
                    ->where('goalPage.metrics.project_sales.title', '8月20日项目销量明细（08-20）')
                    ->where('goalPage.metrics.project_sales.products.0.value', 7)
                    ->where('goalPage.metrics.project_sales.site_metrics.0.value', 40)
                    ->has('goalPage.metrics.progress.points', 1)
                    ->where('goalPage.metrics.progress.points.0.date', '2026-08-20')
                    ->where('goalPage.metrics.progress.completion.rate', 33.3));

            $this->actingAs($user)
                ->withSession($this->contextSession($organization, $store))
                ->get(route('paid-advertising.goals', ['tab' => 'board-'.$board->id]))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('goalPage.active_tab', 'board-'.$board->id)
                    ->where('goalPage.metrics.as_of_date', '2026-08-21')
                    ->where('goalPage.metrics.cards.0.value', 100)
                    ->where('goalPage.metrics.cards.1.value', -10)
                    ->where('goalPage.metrics.cards.2.value', 250)
                    ->where('goalPage.metrics.cards.3.value', 25));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_overall_goal_metrics_prefer_the_latest_scoped_mf_data_table_archive(): void
    {
        [$user, $organization, $store] = $this->context('operator');
        $store->update(['timezone' => 'America/Los_Angeles', 'currency' => 'USD']);
        $otherStore = $this->addStore($user, $organization, 'Other MF Store', 'other-mf.myshopify.com');
        $latest = FeishuBitableTable::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'source_section' => 'paid-ad-goals:archive-copy',
            'source_table_id' => 'tbl-mf-latest',
            'name' => 'MF数据表',
            'synced_at' => CarbonImmutable::parse('2026-08-30 19:42:54', 'UTC'),
        ]);
        $other = FeishuBitableTable::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $otherStore->id,
            'source_section' => 'paid-ad-goals:archive-copy',
            'source_table_id' => 'tbl-mf-other',
            'name' => 'MF数据表',
            'synced_at' => CarbonImmutable::parse('2026-08-30 20:00:00', 'UTC'),
        ]);

        foreach (['日期', '总销售额', '退款', '月销售额总和', '月度目标销售额'] as $position => $name) {
            foreach ([[$latest, $store], [$other, $otherStore]] as [$table, $sourceStore]) {
                FeishuBitableField::query()->create([
                    'organization_id' => $organization->id,
                    'store_id' => $sourceStore->id,
                    'feishu_bitable_table_id' => $table->id,
                    'source_field_id' => 'field-'.$position,
                    'name' => $name,
                    'type' => $position === 0 ? 5 : 2,
                    'field_order' => $position,
                    'is_primary' => $position === 0,
                    'synced_at' => now(),
                ]);
            }
        }

        FeishuBitableRecord::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'feishu_bitable_table_id' => $latest->id,
            'source_record_id' => 'mf-2026-08-29',
            'fields_encrypted' => [
                '日期' => CarbonImmutable::parse('2026-08-29', 'Asia/Shanghai')->getTimestampMs(),
                '总销售额' => '53178.34',
                '退款' => '0',
                '月销售额总和' => 1531452.77,
                '月度目标销售额' => '3000000',
                'X7销量' => 9,
                '订单数' => 52,
                'FB花费' => [7209.38],
                'FB销售额' => [19949.97],
                'FB的ROI' => 2.766,
                '总花费' => 13782.926634,
                '总ROI' => 3.86,
                'ROI（预5%退款）' => 3.66536254175348,
                '月总花费总和' => 357279.558574039,
                '月总退款总和' => 79026.98,
            ],
            'synced_at' => $latest->synced_at,
        ]);
        FeishuBitableRecord::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $otherStore->id,
            'feishu_bitable_table_id' => $other->id,
            'source_record_id' => 'other-mf-2026-08-29',
            'fields_encrypted' => [
                '日期' => CarbonImmutable::parse('2026-08-29', 'Asia/Shanghai')->getTimestampMs(),
                '总销售额' => 999999,
                '退款' => 999,
                '月销售额总和' => 9999999,
                '月度目标销售额' => 10000000,
            ],
            'synced_at' => $other->synced_at,
        ]);
        $this->createMetricFields($organization, $store, 'overall');
        $this->createMetricRecord($organization, $store, 'overall', '2026-08-29', 1, -1, 1, 100);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-30 12:00:00', 'America/Los_Angeles'));

        try {
            $this->actingAs($user)
                ->withSession($this->contextSession($organization, $store))
                ->get(route('paid-advertising.goals', ['period_mode' => 'month', 'month' => '2026-08']))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('goalPage.metrics.available', true)
                    ->where('goalPage.metrics.as_of_date', '2026-08-29')
                    ->where('goalPage.metrics.synced_at', '2026-08-30T19:42:54+00:00')
                    ->where('goalPage.metrics.cards.0.value', 53178.34)
                    ->where('goalPage.metrics.cards.1.value', null)
                    ->where('goalPage.metrics.cards.2.value', 1531452.77)
                    ->where('goalPage.metrics.cards.3.value', 51)
                    ->where('goalPage.metrics.project_sales.products.0.value', 9)
                    ->where('goalPage.metrics.project_sales.site_metrics.0.value', 52)
                    ->where('goalPage.metrics.channel_performance.channels.0.spend', 7209.38)
                    ->where('goalPage.metrics.channel_performance.summary.0.value', 13782.93)
                    ->where('goalPage.metrics.progress.completion.monthly_refunds', -79026.98));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_personal_facebook_summary_uses_synced_personal_values_and_store_scoped_overall_spend(): void
    {
        [$user, $organization, $store] = $this->context('operator');
        $store->update(['timezone' => 'America/Los_Angeles', 'currency' => 'USD']);
        $board = PaidAdvertisingGoalBoard::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'name' => '黄智诚',
            'type' => 'personal_facebook',
            'feishu_app_token' => 'board-app-token',
            'feishu_table_id' => 'board-table-id',
            'feishu_view_id' => 'board-view-id',
            'sync_status' => 'completed',
        ]);
        foreach (['日期', '月份', '今日销售额', '月销售额之和', '月销售额目标', 'FB销售额-Macfox', '本月FB销售目标', 'Criteo销售额', '本月Criteo销售目标'] as $position => $name) {
            PaidAdvertisingGoalField::query()->create([
                'organization_id' => $organization->id,
                'store_id' => $store->id,
                'goal_board_id' => $board->id,
                'source_key' => 'board:'.$board->id,
                'source_field_id' => 'personal-field-'.$position,
                'name' => $name,
                'type' => $position === 0 ? 5 : 2,
                'field_order' => $position,
                'is_primary' => $position === 0,
                'synced_at' => now(),
            ]);
        }
        foreach (['日期', '周', 'FB花费', 'FB销售额', 'FB的ROI', 'FB花费-黄智诚', 'FB销售额-黄智诚', 'Criteo花费'] as $position => $name) {
            PaidAdvertisingGoalField::query()->create([
                'organization_id' => $organization->id,
                'store_id' => $store->id,
                'goal_board_id' => null,
                'source_key' => 'overall',
                'source_field_id' => 'overall-personal-field-'.$position,
                'name' => $name,
                'type' => $position === 0 ? 5 : ($position === 1 ? 1 : 2),
                'field_order' => $position,
                'is_primary' => $position === 0,
                'synced_at' => now(),
            ]);
        }
        $metaWeeklySourceKey = 'board:'.$board->id.':meta-weekly';
        foreach (['记录日期', '年度第几周', '展示次数', '链接点击率', '花费金额', '转化次数', '每次转化费用', '转化价值', 'ROI', '加购数', '结账数', 'FB花费-黄智诚', 'FB销售额-黄智诚', 'FB的ROI-黄智诚'] as $position => $name) {
            PaidAdvertisingGoalField::query()->create([
                'organization_id' => $organization->id,
                'store_id' => $store->id,
                'goal_board_id' => $board->id,
                'source_key' => $metaWeeklySourceKey,
                'source_field_id' => 'meta-weekly-field-'.$position,
                'name' => $name,
                'type' => $position === 0 ? 5 : ($position === 1 ? 1 : 2),
                'field_order' => $position,
                'is_primary' => $position === 1,
                'synced_at' => now(),
            ]);
        }
        foreach ([
            ['2026-08-20', 250, 500, 150, 90],
            ['2026-08-21', 300, 800, 200, 120],
            ['2026-08-22', 0, 800, 0, 0],
        ] as [$date, $dailySales, $monthlySales, $facebookSales, $criteoSales]) {
            PaidAdvertisingGoalRecord::query()->create([
                'organization_id' => $organization->id,
                'store_id' => $store->id,
                'goal_board_id' => $board->id,
                'source_key' => 'board:'.$board->id,
                'source_record_id' => 'personal-'.$date,
                'fields_encrypted' => [
                    '日期' => CarbonImmutable::parse($date, 'Asia/Shanghai')->getTimestampMs(),
                    '月份' => [['text' => '2026-08', 'type' => 'text']],
                    '今日销售额' => $dailySales,
                    '月销售额之和' => $monthlySales,
                    '月销售额目标' => 1000,
                    'FB销售额-Macfox' => $facebookSales,
                    '本月FB销售目标' => 600,
                    'Criteo销售额' => [$criteoSales],
                    '本月Criteo销售目标' => 200,
                ],
                'synced_at' => now(),
            ]);
        }
        foreach ([
            ['2026-08-09', '32周 08.03~08.09', 100, 20],
            ['2026-08-16', '33周 08.10~08.16', 150, 25],
        ] as [$date, $week, $facebookSales, $facebookSpend]) {
            PaidAdvertisingGoalRecord::query()->create([
                'organization_id' => $organization->id,
                'store_id' => $store->id,
                'goal_board_id' => null,
                'source_key' => 'overall',
                'source_record_id' => 'overall-week-'.$date,
                'fields_encrypted' => [
                    '日期' => CarbonImmutable::parse($date, 'Asia/Shanghai')->getTimestampMs(),
                    '周' => [['text' => $week, 'type' => 'text']],
                    'FB花费' => [$facebookSpend],
                    'FB销售额' => [$facebookSales],
                    'FB的ROI' => $facebookSales / $facebookSpend,
                    'FB花费-黄智诚' => [$facebookSpend],
                    'FB销售额-黄智诚' => [$facebookSales],
                ],
                'synced_at' => now(),
            ]);
        }
        foreach ([
            ['2026-08-03', '32周 08.03~08.09', 100000, 0.0123, 20, 10, 2, 100, 5, 20, 15, 100, 20],
            ['2026-08-10', '33周 08.10~08.16', 120000, 0.015, 25, 12, 2.08, 150, 6, 25, 18, 150, 25],
        ] as [$date, $week, $impressions, $ctr, $spend, $conversions, $cpa, $value, $roi, $carts, $checkouts, $personalSales, $personalSpend]) {
            PaidAdvertisingGoalRecord::query()->create([
                'organization_id' => $organization->id,
                'store_id' => $store->id,
                'goal_board_id' => $board->id,
                'source_key' => $metaWeeklySourceKey,
                'source_record_id' => 'meta-weekly-'.$date,
                'fields_encrypted' => [
                    '记录日期' => CarbonImmutable::parse($date, 'Asia/Shanghai')->getTimestampMs(),
                    '年度第几周' => [['text' => $week, 'type' => 'text']],
                    '展示次数' => $impressions,
                    '链接点击率' => $ctr,
                    '花费金额' => $spend,
                    '转化次数' => $conversions,
                    '每次转化费用' => $cpa,
                    '转化价值' => $value,
                    'ROI' => $roi,
                    '加购数' => $carts,
                    '结账数' => $checkouts,
                    'FB花费-黄智诚' => $personalSpend,
                    'FB销售额-黄智诚' => $personalSales,
                    'FB的ROI-黄智诚' => $roi,
                ],
                'synced_at' => now(),
            ]);
        }
        foreach ([
            ['2026-08-20', '34周 08.17~08.23', 60, 20, 210, 3.5],
            ['2026-08-21', '34周 08.17~08.23', 40, 20, 170, 4.25],
            ['2026-08-22', '34周 08.17~08.23', 900, 900, 9000, 10],
        ] as [$date, $week, $facebookSpend, $criteoSpend, $facebookSales, $facebookRoi]) {
            PaidAdvertisingGoalRecord::query()->create([
                'organization_id' => $organization->id,
                'store_id' => $store->id,
                'goal_board_id' => null,
                'source_key' => 'overall',
                'source_record_id' => 'overall-'.$date,
                'fields_encrypted' => [
                    '日期' => CarbonImmutable::parse($date, 'Asia/Shanghai')->getTimestampMs(),
                    '周' => [['text' => $week, 'type' => 'text']],
                    'FB花费' => [$facebookSpend],
                    'FB销售额' => [$facebookSales],
                    'FB的ROI' => $facebookRoi,
                    'FB花费-黄智诚' => [$facebookSpend],
                    'FB销售额-黄智诚' => [$facebookSales],
                    'Criteo花费' => [$criteoSpend],
                ],
                'synced_at' => now(),
            ]);
        }
        $otherStore = $this->addStore($user, $organization, 'Other Personal Store', 'other-personal.myshopify.com');
        PaidAdvertisingGoalRecord::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $otherStore->id,
            'goal_board_id' => null,
            'source_key' => 'overall',
            'source_record_id' => 'other-overall-2026-08-21',
            'fields_encrypted' => [
                '日期' => CarbonImmutable::parse('2026-08-21', 'Asia/Shanghai')->getTimestampMs(),
                'FB花费-黄智诚' => [99999],
                'Criteo花费' => [99999],
            ],
            'synced_at' => now(),
        ]);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-22 12:00:00', 'America/Los_Angeles'));

        try {
            $this->actingAs($user)
                ->withSession($this->contextSession($organization, $store))
                ->get(route('paid-advertising.goals', [
                    'tab' => 'board-'.$board->id,
                    'period_mode' => 'month',
                    'month' => '2026-08',
                ]))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('goalPage.template_data.personal_facebook.schema', 'paid-advertising-personal-facebook-summary-v1')
                    ->where('goalPage.template_data.personal_facebook.available', true)
                    ->where('goalPage.template_data.personal_facebook.source', 'database_sync')
                    ->where('goalPage.template_data.personal_facebook.currency', 'USD')
                    ->where('goalPage.template_data.personal_facebook.as_of_date', '2026-08-21')
                    ->where('goalPage.template_data.personal_facebook.day_label', '昨日')
                    ->where('goalPage.template_data.personal_facebook.message', null)
                    ->where('goalPage.template_data.personal_facebook.values.daily_sales', 300)
                    ->where('goalPage.template_data.personal_facebook.values.monthly_sales', 800)
                    ->where('goalPage.template_data.personal_facebook.values.monthly_target', 1000)
                    ->where('goalPage.template_data.personal_facebook.values.completion_rate', 80)
                    ->where('goalPage.template_data.personal_facebook.values.monthly_spend', 185)
                    ->where('goalPage.template_data.personal_facebook.values.roas', 4.32)
                    ->where('goalPage.template_data.personal_facebook.facebook.schema', 'paid-advertising-personal-facebook-channel-goal-v1')
                    ->where('goalPage.template_data.personal_facebook.facebook.available', true)
                    ->where('goalPage.template_data.personal_facebook.facebook.as_of_date', '2026-08-21')
                    ->where('goalPage.template_data.personal_facebook.facebook.values.daily_sales', 200)
                    ->where('goalPage.template_data.personal_facebook.facebook.values.period_sales', 350)
                    ->where('goalPage.template_data.personal_facebook.facebook.values.target', 600)
                    ->where('goalPage.template_data.personal_facebook.facebook.values.completion_rate', 58.33)
                    ->where('goalPage.template_data.personal_facebook.facebook.values.time_progress', 67.74)
                    ->where('goalPage.template_data.personal_facebook.facebook.values.time_variance', -9.41)
                    ->where('goalPage.template_data.personal_facebook.facebook.values.daily_needed', 25)
                    ->where('goalPage.template_data.personal_facebook.facebook.values.daily_achievement_rate', 86.11)
                    ->where('goalPage.template_data.personal_facebook.facebook.values.elapsed_days', 21)
                    ->where('goalPage.template_data.personal_facebook.facebook.values.remaining_days', 10)
                    ->where('goalPage.template_data.personal_facebook.facebook.source_fields.daily_sales', 'FB销售额-Macfox')
                    ->where('goalPage.template_data.personal_facebook.facebook.source_fields.target', '本月FB销售目标')
                    ->where('goalPage.template_data.personal_facebook.criteo.schema', 'paid-advertising-personal-criteo-channel-goal-v1')
                    ->where('goalPage.template_data.personal_facebook.criteo.available', true)
                    ->where('goalPage.template_data.personal_facebook.criteo.as_of_date', '2026-08-21')
                    ->where('goalPage.template_data.personal_facebook.criteo.values.daily_sales', 120)
                    ->where('goalPage.template_data.personal_facebook.criteo.values.period_sales', 210)
                    ->where('goalPage.template_data.personal_facebook.criteo.values.target', 200)
                    ->where('goalPage.template_data.personal_facebook.criteo.values.completion_rate', 105)
                    ->where('goalPage.template_data.personal_facebook.criteo.values.time_progress', 67.74)
                    ->where('goalPage.template_data.personal_facebook.criteo.values.time_variance', 37.26)
                    ->where('goalPage.template_data.personal_facebook.criteo.values.daily_needed', 0)
                    ->where('goalPage.template_data.personal_facebook.criteo.values.daily_achievement_rate', 155)
                    ->where('goalPage.template_data.personal_facebook.criteo.values.elapsed_days', 21)
                    ->where('goalPage.template_data.personal_facebook.criteo.values.remaining_days', 10)
                    ->where('goalPage.template_data.personal_facebook.criteo.source_fields.daily_sales', 'Criteo销售额')
                    ->where('goalPage.template_data.personal_facebook.criteo.source_fields.target', '本月Criteo销售目标')
                    ->where('goalPage.template_data.personal_facebook.details.schema', 'paid-advertising-personal-goal-details-v1')
                    ->where('goalPage.template_data.personal_facebook.details.available', true)
                    ->where('goalPage.template_data.personal_facebook.details.message', null)
                    ->where('goalPage.template_data.personal_facebook.details.period_label', '2026年8月')
                    ->where('goalPage.template_data.personal_facebook.details.total', 2)
                    ->where('goalPage.template_data.personal_facebook.details.columns.0.key', '月份')
                    ->where('goalPage.template_data.personal_facebook.details.columns.0.kind', 'month')
                    ->where('goalPage.template_data.personal_facebook.details.columns.1.key', '日期')
                    ->where('goalPage.template_data.personal_facebook.details.columns.1.kind', 'date')
                    ->where('goalPage.template_data.personal_facebook.details.rows.0.date', '2026-08-21')
                    ->where('goalPage.template_data.personal_facebook.details.rows.0.values.月份', '2026-08')
                    ->where('goalPage.template_data.personal_facebook.details.rows.0.values.日期', '2026-08-21')
                    ->where('goalPage.template_data.personal_facebook.details.rows.0.values.Criteo销售额', 120)
                    ->where('goalPage.template_data.personal_facebook.details.rows.1.date', '2026-08-20')
                    ->where('goalPage.template_data.personal_facebook.facebook_efficiency.schema', 'paid-advertising-facebook-efficiency-v1')
                    ->where('goalPage.template_data.personal_facebook.facebook_efficiency.available', true)
                    ->where('goalPage.template_data.personal_facebook.facebook_efficiency.as_of_date', '2026-08-21')
                    ->where('goalPage.template_data.personal_facebook.facebook_efficiency.values.current_roas', 4.25)
                    ->where('goalPage.template_data.personal_facebook.facebook_efficiency.values.target_roas', 5)
                    ->where('goalPage.template_data.personal_facebook.facebook_efficiency.values.achievement_rate', 85)
                    ->where('goalPage.template_data.personal_facebook.facebook_efficiency.values.period_spend', 145)
                    ->where('goalPage.template_data.personal_facebook.meta_weekly.schema', 'paid-advertising-meta-weekly-v1')
                    ->where('goalPage.template_data.personal_facebook.meta_weekly.available', true)
                    ->where('goalPage.template_data.personal_facebook.meta_weekly.latest_week.label', '33周 08.10~08.16')
                    ->where('goalPage.template_data.personal_facebook.meta_weekly.previous_week.label', '32周 08.03~08.09')
                    ->where('goalPage.template_data.personal_facebook.meta_weekly.values.sales', 150)
                    ->where('goalPage.template_data.personal_facebook.meta_weekly.values.spend', 25)
                    ->where('goalPage.template_data.personal_facebook.meta_weekly.values.roi', 6)
                    ->where('goalPage.template_data.personal_facebook.meta_weekly.changes.sales', 50)
                    ->where('goalPage.template_data.personal_facebook.meta_weekly.changes.spend', 25)
                    ->where('goalPage.template_data.personal_facebook.meta_weekly.changes.roi', 20)
                    ->where('goalPage.template_data.personal_facebook.meta_weekly.trend.available', true)
                    ->where('goalPage.template_data.personal_facebook.meta_weekly.trend.points.0.week', '32周 08.03~08.09')
                    ->where('goalPage.template_data.personal_facebook.meta_weekly.trend.points.1.week', '33周 08.10~08.16')
                    ->where('goalPage.template_data.personal_facebook.meta_weekly.trend.points.1.sales', 150)
                    ->where('goalPage.template_data.personal_facebook.meta_weekly.trend.points.1.spend', 25)
                    ->where('goalPage.template_data.personal_facebook.meta_weekly.trend.points.1.roi', 6)
                    ->where('goalPage.template_data.personal_facebook.meta_weekly.table.available', true)
                    ->where('goalPage.template_data.personal_facebook.meta_weekly.table.total', 2)
                    ->where('goalPage.template_data.personal_facebook.meta_weekly.table.columns.0.key', '年度第几周')
                    ->where('goalPage.template_data.personal_facebook.meta_weekly.table.columns.1.key', '展示次数')
                    ->where('goalPage.template_data.personal_facebook.meta_weekly.table.rows.1.values.年度第几周', '33周 08.10~08.16')
                    ->where('goalPage.template_data.personal_facebook.meta_weekly.table.rows.1.values.展示次数', 120000)
                    ->where('goalPage.template_data.personal_facebook.meta_weekly.table.rows.1.values.链接点击率', 0.015)
                    ->where('goalPage.template_data.personal_facebook.meta_weekly.table.rows.1.values.ROI', 6)
                    ->where('goalPage.template_data.personal_facebook.source_fields.monthly_spend', ['FB花费-黄智诚', 'Criteo花费'])
                    ->where('goalPage.template_data.google_ads', null));

            $this->actingAs($user)
                ->withSession($this->contextSession($organization, $store))
                ->get(route('paid-advertising.goals', [
                    'tab' => 'board-'.$board->id,
                    'period_mode' => 'range',
                    'date_from' => '2026-08-20',
                    'date_to' => '2026-08-20',
                ]))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('goalPage.template_data.personal_facebook.as_of_date', '2026-08-20')
                    ->where('goalPage.template_data.personal_facebook.day_label', '8月20日')
                    ->where('goalPage.template_data.personal_facebook.values.daily_sales', 250)
                    ->where('goalPage.template_data.personal_facebook.values.monthly_sales', 500)
                    ->where('goalPage.template_data.personal_facebook.values.monthly_spend', 80)
                    ->where('goalPage.template_data.personal_facebook.values.roas', 6.25)
                    ->where('goalPage.template_data.personal_facebook.facebook.values.daily_sales', 150)
                    ->where('goalPage.template_data.personal_facebook.facebook.values.period_sales', 150)
                    ->where('goalPage.template_data.personal_facebook.facebook.values.target', 600)
                    ->where('goalPage.template_data.personal_facebook.facebook.values.completion_rate', 25)
                    ->where('goalPage.template_data.personal_facebook.facebook.values.time_progress', 100)
                    ->where('goalPage.template_data.personal_facebook.facebook.values.time_variance', -75)
                    ->where('goalPage.template_data.personal_facebook.facebook.values.daily_needed', 450)
                    ->where('goalPage.template_data.personal_facebook.facebook.values.daily_achievement_rate', 25)
                    ->where('goalPage.template_data.personal_facebook.facebook.values.elapsed_days', 1)
                    ->where('goalPage.template_data.personal_facebook.facebook.values.remaining_days', 0)
                    ->where('goalPage.template_data.personal_facebook.criteo.values.daily_sales', 90)
                    ->where('goalPage.template_data.personal_facebook.criteo.values.period_sales', 90)
                    ->where('goalPage.template_data.personal_facebook.criteo.values.target', 200)
                    ->where('goalPage.template_data.personal_facebook.criteo.values.completion_rate', 45)
                    ->where('goalPage.template_data.personal_facebook.criteo.values.time_progress', 100)
                    ->where('goalPage.template_data.personal_facebook.criteo.values.time_variance', -55)
                    ->where('goalPage.template_data.personal_facebook.criteo.values.daily_needed', 110)
                    ->where('goalPage.template_data.personal_facebook.criteo.values.daily_achievement_rate', 45)
                    ->where('goalPage.template_data.personal_facebook.criteo.values.elapsed_days', 1)
                    ->where('goalPage.template_data.personal_facebook.criteo.values.remaining_days', 0)
                    ->where('goalPage.template_data.personal_facebook.details.period_label', '2026-08-20 至 2026-08-20')
                    ->where('goalPage.template_data.personal_facebook.details.total', 1)
                    ->where('goalPage.template_data.personal_facebook.details.rows.0.date', '2026-08-20')
                    ->where('goalPage.template_data.personal_facebook.details.rows.0.values.日期', '2026-08-20')
                    ->where('goalPage.template_data.personal_facebook.facebook_efficiency.values.current_roas', 3.5)
                    ->where('goalPage.template_data.personal_facebook.facebook_efficiency.values.period_spend', 60)
                    ->where('goalPage.template_data.personal_facebook.meta_weekly.available', false));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_project_sales_prefers_the_last_sales_record_and_falls_back_to_the_latest_dated_row(): void
    {
        [$user, $organization, $store] = $this->context('operator');
        $this->createMetricFields($organization, $store, 'overall');
        $this->createMetricRecord($organization, $store, 'overall', '2026-08-20', 100, 0, 1000, 3000, null, [
            'X7销量' => 5,
            '订单数' => 10,
        ]);
        $this->createMetricRecord($organization, $store, 'overall', '2026-08-21', 0, 0, 0, 3000, null, [
            'X7销量' => 99,
            '订单数' => 99,
        ]);
        $board = PaidAdvertisingGoalBoard::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'name' => '零值看板',
            'type' => 'personal_facebook',
            'feishu_app_token' => 'board-app-token',
            'feishu_table_id' => 'board-table-id',
            'feishu_view_id' => 'board-view-id',
            'sync_status' => 'completed',
        ]);
        $this->createMetricFields($organization, $store, 'board:'.$board->id, $board);
        $this->createMetricRecord($organization, $store, 'board:'.$board->id, '2026-08-21', 0, 0, 0, 3000, $board);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-22 12:00:00', $store->timezone));

        try {
            $this->actingAs($user)
                ->withSession($this->contextSession($organization, $store))
                ->get(route('paid-advertising.goals'))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('goalPage.metrics.as_of_date', '2026-08-20')
                    ->where('goalPage.metrics.project_sales.as_of_date', '2026-08-20')
                    ->where('goalPage.metrics.project_sales.products.0.value', 5)
                    ->where('goalPage.metrics.project_sales.site_metrics.0.value', 10));

            $this->actingAs($user)
                ->withSession($this->contextSession($organization, $store))
                ->get(route('paid-advertising.goals', ['tab' => 'board-'.$board->id]))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('goalPage.metrics.as_of_date', '2026-08-21')
                    ->where('goalPage.metrics.project_sales.available', true)
                    ->where('goalPage.metrics.project_sales.products.0.value', 0)
                    ->where('goalPage.metrics.project_sales.products.4.value', 0)
                    ->where('goalPage.metrics.project_sales.site_metrics.0.value', 0)
                    ->where('goalPage.metrics.project_sales.site_metrics.2.value', 0)
                    ->where('goalPage.metrics.channel_performance.available', true)
                    ->where('goalPage.metrics.channel_performance.channels.0.spend', null)
                    ->where('goalPage.metrics.channel_performance.channels.4.roi', null)
                    ->where('goalPage.metrics.channel_performance.summary.0.value', null)
                    ->has('goalPage.metrics.progress.points', 1)
                    ->where('goalPage.metrics.progress.points.0.date', '2026-08-21')
                    ->where('goalPage.metrics.progress.completion.rate', 0));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_confirmed_overall_clear_removes_data_configuration_and_daily_sync_source_but_keeps_the_tab(): void
    {
        Queue::fake();
        [$user, $organization, $store] = $this->context('organization-admin');
        $otherStore = $this->addStore($user, $organization, 'Overall Isolation Store', 'overall-isolation.myshopify.com');
        $values = [
            'feishu_app_token' => 'overall-sensitive-token',
        ];

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->put(route('paid-advertising.goals.overall.update'), $values)
            ->assertRedirect(route('paid-advertising.goals'));
        $record = PaidAdvertisingGoalRecord::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'goal_board_id' => null,
            'source_key' => 'overall',
            'source_record_id' => 'rec-overall-clear',
            'fields_encrypted' => ['月份' => '2026-08', '总目标' => 200000],
            'synced_at' => now(),
        ]);
        $field = PaidAdvertisingGoalField::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'goal_board_id' => null,
            'source_key' => 'overall',
            'source_field_id' => 'fld-overall-clear',
            'name' => '总目标',
            'type' => 2,
            'field_order' => 0,
            'is_primary' => true,
            'synced_at' => now(),
        ]);

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->deleteJson(route('paid-advertising.goals.overall.clear'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('confirmed');
        $this->assertDatabaseHas('paid_advertising_goal_records', ['id' => $record->id]);
        $this->assertDatabaseHas('paid_advertising_goal_fields', ['id' => $field->id]);

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $otherStore))
            ->delete(route('paid-advertising.goals.overall.clear'), ['confirmed' => true])
            ->assertRedirect(route('paid-advertising.goals'));
        $this->assertDatabaseHas('paid_advertising_goal_records', ['id' => $record->id]);
        $this->assertSame(1, StoreBusinessCredential::query()
            ->where('store_id', $store->id)
            ->whereIn('credential_key', [
                'advertising_goals_app_token',
                'advertising_goals_table_id',
                'advertising_goals_view_id',
            ])
            ->count());

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->delete(route('paid-advertising.goals.overall.clear'), ['confirmed' => true])
            ->assertRedirect(route('paid-advertising.goals'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('paid_advertising_goal_records', ['id' => $record->id]);
        $this->assertDatabaseMissing('paid_advertising_goal_fields', ['id' => $field->id]);
        $this->assertSame(0, StoreBusinessCredential::query()
            ->where('store_id', $store->id)
            ->whereIn('credential_key', [
                'advertising_goals_app_token',
                'advertising_goals_table_id',
                'advertising_goals_view_id',
            ])
            ->count());
        $audit = AuditLog::query()
            ->where('store_id', $store->id)
            ->where('action', 'paid_advertising_overall_goal_cleared')
            ->sole();
        $this->assertSame(1, data_get($audit->old_values, 'records_deleted'));
        $this->assertSame(1, data_get($audit->old_values, 'fields_deleted'));
        $this->assertSame(1, data_get($audit->old_values, 'credentials_deleted'));
        $this->assertTrue((bool) data_get($audit->metadata, 'daily_sync_removed'));
        $this->assertTrue((bool) data_get($audit->metadata, 'fixed_tab_preserved'));
        $this->assertStringNotContainsString($values['feishu_app_token'], $audit->toJson());

        $scheduled = app(PaidAdvertisingGoalSyncService::class)->syncConfiguredSources($store->id);
        $this->assertSame(0, $scheduled['sources']);

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->get(route('paid-advertising.goals'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('goalPage.tabs', 1)
                ->where('goalPage.tabs.0.label', '总目标')
                ->where('goalPage.tabs.0.deletable', false)
                ->where('goalPage.tabs.0.clearable', false)
                ->where('goalPage.configuration.configured', false)
                ->where('goalPage.configuration.has_configuration', false));
    }

    public function test_store_admin_can_add_a_scoped_custom_goal_tab_without_exposing_feishu_configuration(): void
    {
        [$user, $organization, $store] = $this->context('organization-admin');
        $otherStore = $this->addStore($user, $organization, 'Second Paid Store', 'second-paid.myshopify.com');
        $values = $this->boardValues('黄智诚');
        Queue::fake();

        $response = $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->post(route('paid-advertising.goals.store'), [
                ...$values,
                'period_mode' => 'month',
                'month' => '2026-08',
            ]);

        $board = PaidAdvertisingGoalBoard::query()->sole();
        $response->assertRedirect(route('paid-advertising.goals', [
            'tab' => 'board-'.$board->id,
            'period_mode' => 'month',
            'month' => '2026-08',
        ]));
        $response->assertSessionHas('success', '目标页签已添加。');
        Queue::assertPushed(SyncPaidAdvertisingGoalTarget::class, fn (SyncPaidAdvertisingGoalTarget $job): bool => $job->organizationId === $organization->id
            && $job->storeId === $store->id
            && $job->actorId === $user->id
            && $job->trigger === PaidAdvertisingGoalRefreshService::SOURCE_GOAL_BOARD_CREATED
            && $job->target === 'board-'.$board->id
            && PaidAdvertisingGoalSyncRun::query()->whereKey($job->runId)->where('status', 'queued')->exists());
        $this->assertSame($organization->id, $board->organization_id);
        $this->assertSame($store->id, $board->store_id);
        $this->assertSame($values['name'], $board->name);
        $this->assertSame($values['type'], $board->type);
        $this->assertSame($values['feishu_app_token'], $board->feishu_app_token);
        $this->assertSame($values['feishu_table_id'], $board->feishu_table_id);
        $this->assertSame($values['feishu_view_id'], $board->feishu_view_id);

        $rawBoard = DB::table('paid_advertising_goal_boards')->where('id', $board->id)->first();
        $this->assertNotNull($rawBoard);
        $this->assertStringNotContainsString($values['feishu_app_token'], (string) $rawBoard->feishu_app_token);
        $this->assertStringNotContainsString($values['feishu_table_id'], (string) $rawBoard->feishu_table_id);
        $this->assertStringNotContainsString($values['feishu_view_id'], (string) $rawBoard->feishu_view_id);

        $audit = AuditLog::query()->where('action', 'paid_advertising_goal_board_created')->sole();
        $this->assertSame(['name' => '黄智诚', 'type' => 'personal_facebook', 'configured' => true], $audit->new_values);
        $this->assertStringNotContainsString($values['feishu_app_token'], $audit->toJson());

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->get(route('paid-advertising.goals', ['tab' => 'board-'.$board->id]))
            ->assertOk()
            ->assertDontSee($values['feishu_app_token'])
            ->assertDontSee($values['feishu_table_id'])
            ->assertDontSee($values['feishu_view_id'])
            ->assertInertia(fn (Assert $page) => $page
                ->where('goalPage.active_tab', 'board-'.$board->id)
                ->has('goalPage.tabs', 2)
                ->where('goalPage.tabs.0.label', '总目标')
                ->where('goalPage.tabs.0.deletable', false)
                ->where('goalPage.tabs.0.clearable', false)
                ->where('goalPage.tabs.1.label', '黄智诚')
                ->where('goalPage.tabs.1.board_id', $board->id)
                ->where('goalPage.tabs.1.type', 'personal_facebook')
                ->where('goalPage.tabs.1.deletable', true)
                ->where('goalPage.tabs.1.clearable', false)
                ->where('goalPage.type_options.0.label', '个人目标看板＋Facebook数据')
                ->where('goalPage.type_options.1.label', 'Google广告目标')
                ->where('canManage', true));

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $otherStore))
            ->get(route('paid-advertising.goals', ['tab' => 'board-'.$board->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('goalPage.active_tab', 'overall')
                ->has('goalPage.tabs', 1));
    }

    public function test_custom_goal_tab_requires_valid_unique_values_and_manage_permission(): void
    {
        [$admin, $organization, $store] = $this->context('organization-admin');
        Queue::fake();

        $this->actingAs($admin)
            ->withSession($this->contextSession($organization, $store))
            ->from(route('paid-advertising.goals'))
            ->post(route('paid-advertising.goals.store'), [
                'name' => '缺少数据表配置',
                'type' => 'personal_facebook',
                'feishu_app_token' => 'secret-app-token',
            ])
            ->assertRedirect(route('paid-advertising.goals'))
            ->assertSessionHasErrors(['feishu_table_id', 'feishu_view_id']);

        $this->actingAs($admin)
            ->withSession($this->contextSession($organization, $store))
            ->post(route('paid-advertising.goals.store'), $this->boardValues('重复名字'))
            ->assertRedirect();

        $this->actingAs($admin)
            ->withSession($this->contextSession($organization, $store))
            ->from(route('paid-advertising.goals'))
            ->post(route('paid-advertising.goals.store'), [
                ...$this->boardValues('重复名字'),
                'type' => 'unsupported',
            ])
            ->assertRedirect(route('paid-advertising.goals'))
            ->assertSessionHasErrors(['name', 'type']);

        $operator = User::factory()->create(['email_verified_at' => now()]);
        $organization->users()->attach($operator, ['status' => 'active', 'joined_at' => now()]);
        $store->members()->attach($operator, ['status' => 'active', 'joined_at' => now()]);
        $operatorRole = Role::query()->whereBelongsTo($organization)->where('slug', 'operator')->firstOrFail();
        $operator->roles()->attach($operatorRole, [
            'organization_id' => $organization->id,
            'store_id' => null,
        ]);

        $this->actingAs($operator)
            ->withSession($this->contextSession($organization, $store))
            ->post(route('paid-advertising.goals.store'), $this->boardValues('无权限'))
            ->assertForbidden();
        $this->actingAs($operator)
            ->withSession($this->contextSession($organization, $store))
            ->put(route('paid-advertising.goals.overall.update'), [
                'feishu_app_token' => 'forbidden',
                'feishu_table_id' => 'forbidden',
                'feishu_view_id' => 'forbidden',
            ])
            ->assertForbidden();
        $this->actingAs($operator)
            ->withSession($this->contextSession($organization, $store))
            ->delete(route('paid-advertising.goals.overall.clear'), ['confirmed' => true])
            ->assertForbidden();
    }

    public function test_google_goal_tab_only_requires_and_stores_the_app_token(): void
    {
        [$user, $organization, $store] = $this->context('organization-admin');
        Queue::fake();

        $response = $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->post(route('paid-advertising.goals.store'), [
                'name' => 'Google 广告团队',
                'type' => 'google_ads',
                'feishu_app_token' => 'secret-google-app-token',
            ]);

        $board = PaidAdvertisingGoalBoard::query()->sole();
        $response->assertRedirect(route('paid-advertising.goals', ['tab' => 'board-'.$board->id]));
        $response->assertSessionHasNoErrors();
        $this->assertSame('google_ads', $board->type);
        $this->assertSame('secret-google-app-token', $board->feishu_app_token);
        $this->assertSame('', $board->feishu_table_id);
        $this->assertSame('', $board->feishu_view_id);
        Queue::assertPushed(SyncPaidAdvertisingGoalTarget::class, fn (SyncPaidAdvertisingGoalTarget $job): bool => $job->target === 'board-'.$board->id);

        $rawBoard = DB::table('paid_advertising_goal_boards')->where('id', $board->id)->first();
        $this->assertNotNull($rawBoard);
        $this->assertStringNotContainsString('secret-google-app-token', (string) $rawBoard->feishu_app_token);
        $this->assertStringNotContainsString('secret-google-app-token', AuditLog::query()
            ->where('action', 'paid_advertising_goal_board_created')
            ->sole()
            ->toJson());
    }

    public function test_google_goal_template_reads_the_latest_valid_sales_target_record_regardless_of_selected_period(): void
    {
        [$user, $organization, $store] = $this->context('operator');
        $board = PaidAdvertisingGoalBoard::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'name' => '潘舒晴',
            'type' => 'google_ads',
            'feishu_app_token' => 'spreadsheet-token',
            'feishu_table_id' => '',
            'feishu_view_id' => '',
            'sync_status' => 'completed',
            'created_by' => $user->id,
        ]);
        $sourceKey = 'board:'.$board->id.':google:sheet:sales';
        $googleFields = [
            '记录日期',
            '今日营收($)',
            '月累计销售额($)',
            '月目标($)',
            '日均需完成($)',
            '日均完成率',
            '目标完成率',
            '时间对比完成',
            '时间进度',
            '当前 ROAS',
            '目标 ROAS',
            '本月花费($)',
        ];
        foreach ($googleFields as $position => $name) {
            PaidAdvertisingGoalField::query()->create([
                'organization_id' => $organization->id,
                'store_id' => $store->id,
                'goal_board_id' => $board->id,
                'source_key' => $sourceKey,
                'source_field_id' => 'google-sales-'.$position,
                'name' => $name,
                'type' => $position === 0 ? 1 : 2,
                'field_order' => $position,
                'is_primary' => $position === 0,
                'property_encrypted' => [
                    'source' => 'spreadsheet',
                    'sheet_title' => '销售目标',
                ],
                'synced_at' => now(),
            ]);
        }
        PaidAdvertisingGoalField::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'goal_board_id' => $board->id,
            'source_key' => 'board:'.$board->id.':google:sheet:learning',
            'source_field_id' => 'google-learning-content',
            'name' => '内容',
            'type' => 1,
            'field_order' => 0,
            'is_primary' => true,
            'property_encrypted' => [
                'source' => 'spreadsheet',
                'sheet_title' => '品牌学习',
            ],
            'synced_at' => now(),
        ]);
        foreach ([
            ['2026/08/20', '36,944.91', '775,651.03', '1,500,000.00', '65,849.91', '56.10%', '51.71%', '-12.81%', '64.52%', '4.10', '3.70', '40,000.00'],
            ['2026/08/21', '18,478.09', '798,133.64', '1,500,000.00', '70,186.64', '26.33%', '53.21%', '-14.53%', '67.74%', '4.00', '3.70', '42,000.00'],
            ['2026/09/01', '999,999.00', '999,999.00', '1,500,000.00', '1.00', '99.99%', '99.99%', '99.99%', '99.99%', '4.20', '3.70', '45,678.90'],
        ] as $index => $values) {
            PaidAdvertisingGoalRecord::query()->create([
                'organization_id' => $organization->id,
                'store_id' => $store->id,
                'goal_board_id' => $board->id,
                'source_key' => $sourceKey,
                'source_record_id' => 'google-sales-row-'.$index,
                'fields_encrypted' => array_combine($googleFields, $values),
                'synced_at' => now(),
            ]);
        }

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->get(route('paid-advertising.goals', [
                'tab' => 'board-'.$board->id,
                'period_mode' => 'month',
                'month' => '2026-08',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('goalPage.template_data.personal_facebook', null)
                ->where('goalPage.template_data.google_ads.schema', 'paid-advertising-google-ads-summary-v1')
                ->where('goalPage.template_data.google_ads.available', true)
                ->where('goalPage.template_data.google_ads.source', 'database_sync')
                ->where('goalPage.template_data.google_ads.source_sheet', '销售目标')
                ->where('goalPage.template_data.google_ads.as_of_date', '2026-09-01')
                ->where('goalPage.template_data.google_ads.missing_fields', [])
                ->where('goalPage.template_data.google_ads.message', null)
                ->where('goalPage.template_data.google_ads.pace_status', 'behind')
                ->where('goalPage.template_data.google_ads.values.daily_sales', 999999)
                ->where('goalPage.template_data.google_ads.values.monthly_sales', 999999)
                ->where('goalPage.template_data.google_ads.values.monthly_target', 1500000)
                ->where('goalPage.template_data.google_ads.values.daily_needed', 1)
                ->where('goalPage.template_data.google_ads.values.daily_achievement_rate', 99.99)
                ->where('goalPage.template_data.google_ads.values.completion_rate', 99.99)
                ->where('goalPage.template_data.google_ads.values.time_variance', 99.99)
                ->where('goalPage.template_data.google_ads.values.time_progress', 99.99)
                ->where('goalPage.template_data.google_ads.source_fields.daily_sales', '今日营收($)')
                ->where('goalPage.template_data.google_ads.source_fields.monthly_sales', '月累计销售额($)')
                ->where('goalPage.template_data.google_ads.source_fields.monthly_target', '月目标($)')
                ->where('goalPage.template_data.google_ads.source_fields.daily_needed', '日均需完成($)')
                ->where('goalPage.template_data.google_ads.source_fields.daily_achievement_rate', '日均完成率')
                ->where('goalPage.template_data.google_ads.source_fields.completion_rate', '目标完成率')
                ->where('goalPage.template_data.google_ads.source_fields.time_variance', '时间对比完成')
                ->where('goalPage.template_data.google_ads.source_fields.time_progress', '时间进度')
                ->where('goalPage.template_data.google_ads.efficiency.schema', 'paid-advertising-google-efficiency-v1')
                ->where('goalPage.template_data.google_ads.efficiency.values.current_roas', 4.2)
                ->where('goalPage.template_data.google_ads.efficiency.values.target_roas', 3.7)
                ->where('goalPage.template_data.google_ads.efficiency.values.achievement_rate', 113.51)
                ->where('goalPage.template_data.google_ads.efficiency.values.monthly_spend', 45678.9)
                ->where('goalPage.template_data.google_ads.efficiency.source_fields.current_roas', '当前 ROAS')
                ->where('goalPage.template_data.google_ads.efficiency.source_fields.target_roas', '目标 ROAS')
                ->where('goalPage.template_data.google_ads.efficiency.source_fields.monthly_spend', '本月花费($)')
                ->where('goalPage.template_data.google_ads.details.schema', 'paid-advertising-google-target-details-v1')
                ->where('goalPage.template_data.google_ads.details.period_label', '全部同步记录')
                ->where('goalPage.template_data.google_ads.details.total', 3)
                ->where('goalPage.template_data.google_ads.details.columns.0.key', '记录日期')
                ->where('goalPage.template_data.google_ads.details.columns.0.kind', 'date')
                ->where('goalPage.template_data.google_ads.details.columns.1.key', '今日营收($)')
                ->where('goalPage.template_data.google_ads.details.columns.1.kind', 'currency')
                ->where('goalPage.template_data.google_ads.details.columns.5.kind', 'percentage')
                ->where('goalPage.template_data.google_ads.details.columns.9.kind', 'roas')
                ->where('goalPage.template_data.google_ads.details.rows.0.date', '2026-09-01')
                ->where('goalPage.template_data.google_ads.details.rows.0.values.记录日期', '2026-09-01')
                ->where('goalPage.template_data.google_ads.details.rows.0.values.今日营收($)', 999999)
                ->where('goalPage.template_data.google_ads.details.rows.0.values.目标完成率', 99.99)
                ->where('goalPage.template_data.google_ads.details.rows.1.date', '2026-08-21'));

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->get(route('paid-advertising.goals', [
                'tab' => 'board-'.$board->id,
                'period_mode' => 'range',
                'date_from' => '2026-08-20',
                'date_to' => '2026-08-20',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('goalPage.template_data.google_ads.as_of_date', '2026-09-01')
                ->where('goalPage.template_data.google_ads.values.daily_sales', 999999)
                ->where('goalPage.template_data.google_ads.values.completion_rate', 99.99)
                ->where('goalPage.template_data.google_ads.details.total', 3)
                ->where('goalPage.template_data.google_ads.details.rows.0.date', '2026-09-01'));
    }

    public function test_background_job_syncs_only_its_goal_board_target(): void
    {
        [$user, $organization, $store] = $this->context('organization-admin');
        $board = PaidAdvertisingGoalBoard::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            ...$this->boardValues('单页同步测试'),
            'sync_status' => 'pending',
            'created_by' => $user->id,
        ]);
        $sync = Mockery::mock(PaidAdvertisingGoalSyncService::class);
        $sync->shouldReceive('syncBoard')
            ->once()
            ->withArgs(fn (PaidAdvertisingGoalBoard $target): bool => $target->is($board))
            ->andReturn([
                'fields' => 2,
                'inserted' => 5,
                'updated' => 0,
                'deleted' => 0,
                'skipped' => 0,
                'records' => 5,
            ]);
        $sync->shouldNotReceive('syncOverall');
        $sync->shouldNotReceive('syncConfiguredSources');
        $this->app->instance(PaidAdvertisingGoalSyncService::class, $sync);

        $run = PaidAdvertisingGoalSyncRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'goal_board_id' => $board->id,
            'requested_by' => $user->id,
            'target_key' => 'board-'.$board->id,
            'trigger' => PaidAdvertisingGoalRefreshService::SOURCE_GOAL_BOARD_CREATED,
            'status' => PaidAdvertisingGoalRefreshService::STATUS_QUEUED,
        ]);

        $job = new SyncPaidAdvertisingGoalTarget(
            $run->id,
            $organization->id,
            $store->id,
            $user->id,
            PaidAdvertisingGoalRefreshService::SOURCE_GOAL_BOARD_CREATED,
            'board-'.$board->id,
        );
        $job->handle(app(PaidAdvertisingGoalRefreshService::class));

        $audit = AuditLog::query()->where('action', 'paid_advertising_goals_refreshed')->sole();
        $this->assertSame('board', data_get($audit->metadata, 'target'));
        $this->assertSame($board->id, data_get($audit->metadata, 'goal_board_id'));
        $this->assertSame(2, data_get($audit->metadata, 'fields'));
        $this->assertSame(5, data_get($audit->metadata, 'inserted'));
        $this->assertSame(PaidAdvertisingGoalRefreshService::STATUS_COMPLETED, $run->fresh()->status);
        $this->assertSame(5, data_get($run->fresh()->result, 'inserted'));
    }

    public function test_authorized_manual_refresh_silently_queues_only_the_current_tab(): void
    {
        [$user, $organization, $store] = $this->context('organization-admin');
        Queue::fake();
        $sync = Mockery::mock(PaidAdvertisingGoalSyncService::class);
        $sync->shouldReceive('syncOverall')
            ->once()
            ->withArgs(fn (Store $target): bool => $target->is($store))
            ->andReturn([
                'fields' => 45,
                'inserted' => 3,
                'updated' => 4,
                'deleted' => 1,
                'skipped' => 2,
                'records' => 7,
            ]);
        $sync->shouldNotReceive('syncBoard');
        $sync->shouldNotReceive('syncConfiguredSources');
        $this->app->instance(PaidAdvertisingGoalSyncService::class, $sync);

        $response = $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->postJson(route('paid-advertising.goals.refresh'), ['tab' => 'overall'])
            ->assertAccepted()
            ->assertJsonPath('queued', true)
            ->assertJsonPath('sync.tab', 'overall')
            ->assertJsonPath('sync.status', 'queued')
            ->assertJsonPath('sync.initial_sync', false)
            ->assertJsonPath('sync.estimated_finished_at', null)
            ->assertJsonPath('sync.result', null);
        $syncRunUuid = $response->json('sync.id');
        $this->assertTrue(Str::isUuid($syncRunUuid));

        $this->assertDatabaseMissing('audit_logs', ['action' => 'paid_advertising_goals_refreshed']);
        Queue::assertPushed(SyncPaidAdvertisingGoalTarget::class, function (SyncPaidAdvertisingGoalTarget $job) use ($organization, $store, $user, $syncRunUuid): bool {
            $this->assertSame('overall', $job->target);
            $this->assertSame($syncRunUuid, PaidAdvertisingGoalSyncRun::query()->findOrFail($job->runId)->uuid);
            $job->handle(app(PaidAdvertisingGoalRefreshService::class));

            return $job->organizationId === $organization->id
                && $job->storeId === $store->id
                && $job->actorId === $user->id
                && $job->trigger === PaidAdvertisingGoalRefreshService::SOURCE_MANUAL_REFRESH;
        });

        $audit = AuditLog::query()->where('action', 'paid_advertising_goals_refreshed')->sole();
        $this->assertSame($organization->id, $audit->organization_id);
        $this->assertSame($store->id, $audit->store_id);
        $this->assertSame($user->id, $audit->user_id);
        $this->assertSame('completed', data_get($audit->new_values, 'status'));
        $this->assertSame('store', data_get($audit->metadata, 'scope'));
        $this->assertSame('paid_advertising_goals_manual_refresh', data_get($audit->metadata, 'source'));
        $this->assertSame('overall', data_get($audit->metadata, 'target'));
        $this->assertNull(data_get($audit->metadata, 'goal_board_id'));
        $this->assertSame(3, data_get($audit->metadata, 'inserted'));

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->getJson(route('paid-advertising.goals.refresh-status', $syncRunUuid))
            ->assertOk()
            ->assertJsonPath('sync.status', 'completed')
            ->assertJsonPath('sync.result.fields', 45)
            ->assertJsonPath('sync.result.inserted', 3)
            ->assertJsonPath('sync.result.updated', 4)
            ->assertJsonPath('sync.result.deleted', 1)
            ->assertJsonPath('sync.result.skipped', 2);
    }

    public function test_refresh_status_is_store_scoped_and_failed_jobs_return_a_sanitized_message(): void
    {
        [$user, $organization, $store] = $this->context('organization-admin');
        $otherStore = $this->addStore($user, $organization, 'Other Status Store', 'other-status.myshopify.com');
        $run = PaidAdvertisingGoalSyncRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'requested_by' => $user->id,
            'target_key' => 'overall',
            'trigger' => PaidAdvertisingGoalRefreshService::SOURCE_MANUAL_REFRESH,
            'status' => PaidAdvertisingGoalRefreshService::STATUS_RUNNING,
        ]);

        app(PaidAdvertisingGoalRefreshService::class)->markFailed(
            $run,
            'Request failed for https://open.feishu.cn with token=secret-value and tblSensitive123.',
        );

        $response = $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->getJson(route('paid-advertising.goals.refresh-status', $run->uuid))
            ->assertOk()
            ->assertJsonPath('sync.status', 'failed');
        $message = (string) $response->json('sync.message');
        $this->assertStringContainsString('[redacted]', $message);
        $this->assertStringNotContainsString('secret-value', $message);
        $this->assertStringNotContainsString('tblSensitive123', $message);

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $otherStore))
            ->getJson(route('paid-advertising.goals.refresh-status', $run->uuid))
            ->assertNotFound();
    }

    public function test_user_without_sync_run_permission_cannot_manually_refresh_paid_advertising_goals(): void
    {
        [$user, $organization, $store] = $this->context('operator');
        Queue::fake();

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->postJson(route('paid-advertising.goals.refresh'), ['tab' => 'overall'])
            ->assertForbidden();

        Queue::assertNothingPushed();

        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'paid_advertising_goals_refreshed',
            'store_id' => $store->id,
        ]);
    }

    public function test_manual_refresh_rejects_a_goal_page_from_another_store(): void
    {
        [$user, $organization, $store] = $this->context('organization-admin');
        $otherStore = $this->addStore($user, $organization, 'Other Refresh Store', 'other-refresh.myshopify.com');
        $board = PaidAdvertisingGoalBoard::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $otherStore->id,
            ...$this->boardValues('其他店铺目标'),
            'sync_status' => 'pending',
            'created_by' => $user->id,
        ]);
        Queue::fake();

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->postJson(route('paid-advertising.goals.refresh'), ['tab' => 'board-'.$board->id])
            ->assertNotFound();

        Queue::assertNothingPushed();
    }

    public function test_confirmed_delete_removes_custom_configuration_records_and_daily_sync_association(): void
    {
        [$user, $organization, $store] = $this->context('organization-admin');
        $otherStore = $this->addStore($user, $organization, 'Delete Isolation Store', 'delete-isolation.myshopify.com');
        $board = PaidAdvertisingGoalBoard::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            ...$this->boardValues('王静彬'),
            'sync_status' => 'completed',
            'created_by' => $user->id,
        ]);
        $record = PaidAdvertisingGoalRecord::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'goal_board_id' => $board->id,
            'source_key' => 'board:'.$board->id,
            'source_record_id' => 'rec-delete-me',
            'fields_encrypted' => ['月份' => '2026-08', '目标' => 100],
            'synced_at' => now(),
        ]);
        $field = PaidAdvertisingGoalField::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'goal_board_id' => $board->id,
            'source_key' => 'board:'.$board->id,
            'source_field_id' => 'fld-delete-me',
            'name' => '目标',
            'type' => 2,
            'field_order' => 0,
            'is_primary' => true,
            'synced_at' => now(),
        ]);

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->deleteJson(route('paid-advertising.goals.destroy', $board))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('confirmed');
        $this->assertDatabaseHas('paid_advertising_goal_boards', ['id' => $board->id]);

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $otherStore))
            ->deleteJson(route('paid-advertising.goals.destroy', $board), ['confirmed' => true])
            ->assertNotFound();
        $this->assertDatabaseHas('paid_advertising_goal_boards', ['id' => $board->id]);

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->delete(route('paid-advertising.goals.destroy', $board), ['confirmed' => true])
            ->assertRedirect(route('paid-advertising.goals'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('paid_advertising_goal_boards', ['id' => $board->id]);
        $this->assertDatabaseMissing('paid_advertising_goal_records', ['id' => $record->id]);
        $this->assertDatabaseMissing('paid_advertising_goal_fields', ['id' => $field->id]);
        $audit = AuditLog::query()->where('action', 'paid_advertising_goal_board_deleted')->sole();
        $this->assertSame(1, data_get($audit->old_values, 'records_deleted'));
        $this->assertSame(1, data_get($audit->old_values, 'fields_deleted'));
        $this->assertTrue((bool) data_get($audit->metadata, 'daily_sync_removed'));
        $this->assertStringNotContainsString('secret-app-token', $audit->toJson());

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->get(route('paid-advertising.goals'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('goalPage.tabs', 1)
                ->where('goalPage.tabs.0.label', '总目标')
                ->where('goalPage.tabs.0.deletable', false)
                ->where('goalPage.tabs.0.clearable', false));
    }

    public function test_user_without_reports_permission_cannot_open_paid_advertising_pages(): void
    {
        [$user, $organization, $store] = $this->context('marketing');

        foreach ($this->routeNames() as $routeName) {
            $this->actingAs($user)
                ->withSession($this->contextSession($organization, $store))
                ->get(route($routeName))
                ->assertForbidden();
        }
    }

    /** @return array<string, string> */
    private function emptyPages(): array
    {
        return [
            'paid-advertising.google' => 'Google Ads',
            'paid-advertising.tiktok' => 'TikTok Ads',
            'paid-advertising.bing' => 'Bing Ads',
            'paid-advertising.criteo' => 'Criteo',
        ];
    }

    /** @return list<string> */
    private function routeNames(): array
    {
        return ['paid-advertising.goals', 'paid-advertising.facebook', ...array_keys($this->emptyPages())];
    }

    /** @return array{User, Organization, Store} */
    private function context(string $roleSlug): array
    {
        $this->seed(PermissionSeeder::class);
        $organization = Organization::query()->create([
            'name' => 'Paid Advertising',
            'code' => 'paid-advertising',
        ]);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $organization->users()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $store = $this->addStore($user, $organization, 'Paid Advertising Store', 'paid-advertising.myshopify.com');
        $this->seed(RoleSeeder::class);
        $role = Role::query()->whereBelongsTo($organization)->where('slug', $roleSlug)->firstOrFail();
        $user->roles()->attach($role, [
            'organization_id' => $organization->id,
            'store_id' => null,
        ]);

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

    private function configureGoogle(Organization $organization, Store $store): void
    {
        foreach ([
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
            'refresh_token' => 'refresh-token',
            'developer_token' => 'developer-token',
            'customer_id' => '6442213333',
        ] as $key => $value) {
            StoreBusinessCredential::query()->create([
                'organization_id' => $organization->id,
                'store_id' => $store->id,
                'provider' => 'google_ads',
                'credential_key' => $key,
                'credential_value' => $value,
            ]);
        }
    }

    private function configureTikTok(Organization $organization, Store $store): void
    {
        foreach (['access_token' => 'tiktok-token', 'advertiser_ids' => '7623302290988089361'] as $key => $value) {
            StoreBusinessCredential::query()->create([
                'organization_id' => $organization->id,
                'store_id' => $store->id,
                'provider' => 'tiktok_ads',
                'credential_key' => $key,
                'credential_value' => $value,
            ]);
        }
    }

    private function createTikTokMetric(
        Organization $organization,
        Store $store,
        AdvertisingChannelAccount $account,
        string $date,
        float $spend,
        float $revenue,
        float $conversions,
        float $addToCart,
        float $checkout,
    ): void {
        AdvertisingChannelDailyMetric::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'advertising_channel_account_id' => $account->id,
            'provider' => 'tiktok',
            'external_account_id' => $account->external_account_id,
            'metric_date' => $date,
            'spend' => $spend,
            'attributed_sales' => $revenue,
            'impressions' => 1000,
            'clicks' => 100,
            'conversions' => $conversions,
            'add_to_cart' => $addToCart,
            'initiate_checkout' => $checkout,
            'raw_payload' => [],
            'synced_at' => now(),
        ]);
    }

    private function createTikTokCampaignMetric(
        Organization $organization,
        Store $store,
        AdvertisingChannelAccount $account,
        string $date,
        string $campaignId,
        string $campaignName,
        float $spend,
    ): void {
        TikTokAdsCampaignDailyMetric::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'advertising_channel_account_id' => $account->id,
            'external_account_id' => $account->external_account_id,
            'campaign_id' => $campaignId,
            'campaign_name' => $campaignName,
            'campaign_status' => 'ENABLE',
            'objective_type' => 'WEB_CONVERSIONS',
            'metric_date' => $date,
            'spend' => $spend,
            'attributed_sales' => $spend * 5,
            'impressions' => 1000,
            'clicks' => 100,
            'conversions' => 10,
            'raw_payload' => [],
            'synced_at' => now(),
        ]);
    }

    private function createTikTokAdMetric(
        Organization $organization,
        Store $store,
        AdvertisingChannelAccount $account,
        string $date,
        string $adId,
        string $name,
        float $spend,
        float $revenue,
    ): void {
        TikTokAdsAdDailyMetric::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'advertising_channel_account_id' => $account->id,
            'external_account_id' => $account->external_account_id,
            'campaign_id' => 'campaign-a',
            'campaign_name' => 'TikTok Bikes',
            'adgroup_id' => 'group-a',
            'ad_id' => $adId,
            'ad_name' => $name,
            'ad_text' => 'Full TikTok advertising copy.',
            'ad_texts' => ['Full TikTok advertising copy.', 'Second copy variant.'],
            'ad_format' => 'VIDEO',
            'video_id' => 'video-'.$adId,
            'metric_date' => $date,
            'spend' => $spend,
            'attributed_sales' => $revenue,
            'impressions' => 1000,
            'clicks' => 100,
            'conversions' => 5,
            'video_play_actions' => 400,
            'video_watched_2s' => 160,
            'average_video_play' => 2.5,
            'raw_payload' => [],
            'synced_at' => now(),
        ]);
    }

    private function createGoogleMetric(
        Organization $organization,
        Store $store,
        AdvertisingChannelAccount $account,
        string $date,
        float $spend,
        float $revenue,
        float $conversions,
        float $addToCart,
        float $checkout,
        ?float $conversionValueByConversionDate = null,
    ): void {
        AdvertisingChannelDailyMetric::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'advertising_channel_account_id' => $account->id,
            'provider' => 'google',
            'external_account_id' => $account->external_account_id,
            'metric_date' => $date,
            'spend' => $spend,
            'attributed_sales' => $revenue,
            'conversion_value_by_conversion_date' => $conversionValueByConversionDate ?? $revenue,
            'impressions' => 1000,
            'clicks' => 100,
            'conversions' => $conversions,
            'add_to_cart' => $addToCart,
            'initiate_checkout' => $checkout,
            'raw_payload' => [],
            'synced_at' => now(),
        ]);
    }

    private function createGoogleCampaignMetric(
        Organization $organization,
        Store $store,
        AdvertisingChannelAccount $account,
        string $date,
        string $campaignId,
        string $campaignName,
        float $spend,
    ): void {
        GoogleAdsCampaignDailyMetric::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'advertising_channel_account_id' => $account->id,
            'external_account_id' => $account->external_account_id,
            'campaign_id' => $campaignId,
            'campaign_name' => $campaignName,
            'campaign_status' => 'ENABLED',
            'advertising_channel_type' => 'PERFORMANCE_MAX',
            'metric_date' => $date,
            'spend' => $spend,
            'impressions' => 1000,
            'clicks' => 100,
            'conversions' => 10,
            'conversions_value' => $spend * 5,
            'conversion_value_by_conversion_date' => $spend * 5.5,
            'raw_payload' => [],
            'synced_at' => now(),
        ]);
    }

    private function createGoogleSearchTermMetric(
        Organization $organization,
        Store $store,
        AdvertisingChannelAccount $account,
        string $date,
        string $searchTerm,
        float $spend,
        float $revenue,
        int $impressions,
        int $clicks,
        float $conversions,
    ): void {
        $normalized = strtolower(trim($searchTerm));
        GoogleAdsSearchTermDailyMetric::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'advertising_channel_account_id' => $account->id,
            'external_account_id' => $account->external_account_id,
            'metric_date' => $date,
            'dimension_key' => hash('sha256', $normalized.'|'.$date),
            'source_type' => 'standard',
            'search_term' => $searchTerm,
            'normalized_search_term' => $normalized,
            'matched_keyword' => 'macfox ebike',
            'match_type' => 'EXACT',
            'status' => 'ADDED',
            'campaign_id' => 'campaign-search',
            'campaign_name' => 'Search Brand',
            'ad_group_id' => 'ad-group-search',
            'ad_group_name' => 'Brand Exact',
            'spend' => $spend,
            'revenue' => $revenue,
            'impressions' => $impressions,
            'clicks' => $clicks,
            'conversions' => $conversions,
            'raw_payload' => [],
            'synced_at' => now(),
        ]);
    }

    private function createGoogleKeywordMetric(
        Organization $organization,
        Store $store,
        AdvertisingChannelAccount $account,
        string $date,
        string $keyword,
        float $spend,
        float $revenue,
        int $impressions,
        int $clicks,
        float $conversions,
    ): void {
        GoogleAdsKeywordDailyMetric::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'advertising_channel_account_id' => $account->id,
            'external_account_id' => $account->external_account_id,
            'metric_date' => $date,
            'dimension_key' => hash('sha256', $keyword.'|campaign-keyword|ad-group-keyword'),
            'criterion_id' => 'criterion-1',
            'keyword' => $keyword,
            'normalized_keyword' => strtolower(trim($keyword)),
            'match_type' => 'EXACT',
            'status' => 'ENABLED',
            'campaign_id' => 'campaign-keyword',
            'campaign_name' => 'Search Brand',
            'ad_group_id' => 'ad-group-keyword',
            'ad_group_name' => 'Brand Exact',
            'spend' => $spend,
            'revenue' => $revenue,
            'impressions' => $impressions,
            'clicks' => $clicks,
            'conversions' => $conversions,
            'raw_payload' => [],
            'synced_at' => now(),
        ]);
    }

    /** @return array{name: string, type: string, feishu_app_token: string, feishu_table_id: string, feishu_view_id: string} */
    private function boardValues(string $name): array
    {
        return [
            'name' => $name,
            'type' => 'personal_facebook',
            'feishu_app_token' => 'secret-app-token',
            'feishu_table_id' => 'tbl-secret-targets',
            'feishu_view_id' => 'vew-secret-targets',
        ];
    }

    private function createMetricFields(
        Organization $organization,
        Store $store,
        string $sourceKey,
        ?PaidAdvertisingGoalBoard $board = null,
    ): void {
        foreach ([
            '日期',
            '总销售额',
            '退款',
            '月销售额总和',
            '月度目标销售额',
            'X7销量',
            'X2销量',
            'X1S销量',
            'M16销量',
            'X1S x Bs.zay销量',
            '订单数',
            '整站总加购',
            '整站总结账',
            'FB花费',
            'FB销售额',
            'FB的ROI',
            'GG花费',
            'GG销售额',
            'GG的ROI',
            'Tiktok花费',
            'Tiktok销售额',
            'Tiktok的ROI',
            'Bing花费',
            'Bing销售额',
            'Bing的ROI',
            'Criteo花费',
            'Criteo销售',
            'Criteo的ROI',
            '总花费',
            '总ROI',
            'ROI（预5%退款）',
            '月总花费总和',
            '月总退款总和',
        ] as $position => $name) {
            PaidAdvertisingGoalField::query()->create([
                'organization_id' => $organization->id,
                'store_id' => $store->id,
                'goal_board_id' => $board?->id,
                'source_key' => $sourceKey,
                'source_field_id' => $sourceKey.'-field-'.$position,
                'name' => $name,
                'type' => $position === 0 ? 5 : 2,
                'field_order' => $position,
                'is_primary' => $position === 0,
                'synced_at' => now(),
            ]);
        }
    }

    private function createMetricRecord(
        Organization $organization,
        Store $store,
        string $sourceKey,
        string $date,
        float $dailySales,
        float $refunds,
        float $monthlySales,
        float $monthlyTarget,
        ?PaidAdvertisingGoalBoard $board = null,
        array $details = [],
    ): void {
        PaidAdvertisingGoalRecord::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'goal_board_id' => $board?->id,
            'source_key' => $sourceKey,
            'source_record_id' => $sourceKey.'-'.$date,
            'fields_encrypted' => [
                '日期' => CarbonImmutable::parse($date, 'Asia/Shanghai')->getTimestampMs(),
                '总销售额' => (string) $dailySales,
                '退款' => (string) $refunds,
                '月销售额总和' => $monthlySales,
                '月度目标销售额' => (string) $monthlyTarget,
                ...$details,
            ],
            'synced_at' => now(),
        ]);
    }

    private function createMetaInsight(
        Organization $organization,
        Store $store,
        MetaAdAccount $account,
        string $date,
        string $level,
        string $granularity,
        float $spend,
        float $purchaseValue,
        float $purchases,
        int $impressions,
        int $reach,
        int $clicks,
        int $linkClicks,
        ?string $campaignId = null,
        ?string $campaignName = null,
        ?string $dateStop = null,
        ?float $frequency = null,
    ): void {
        $entityId = $level === 'account' ? $account->meta_account_id : ($campaignId ?? 'campaign-'.$date);
        DB::table('meta_ad_insight_entities')->updateOrInsert(
            [
                'organization_id' => $organization->id,
                'store_id' => $store->id,
                'level' => $level,
                'entity_id' => $entityId,
            ],
            [
                'account_name' => $account->name,
                'meta_campaign_id' => $level === 'campaign' ? $entityId : null,
                'campaign_name' => $level === 'campaign' ? $campaignName : null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
        MetaAdInsight::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'meta_ad_account_id' => $account->id,
            'level' => $level,
            'entity_id' => $entityId,
            'account_external_id' => $account->meta_account_id,
            'meta_campaign_id' => $level === 'campaign' ? $entityId : null,
            'date_start' => $date,
            'date_stop' => $dateStop ?? $date,
            'granularity' => $granularity,
            'spend' => $spend,
            'purchase_value' => $purchaseValue,
            'purchases' => $purchases,
            'impressions' => $impressions,
            'reach' => $reach,
            'clicks' => $clicks,
            'inline_link_clicks' => $linkClicks,
            'frequency' => $frequency,
            'synced_at' => now(),
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
