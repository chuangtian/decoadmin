<?php

namespace Tests\Feature;

use App\Jobs\SyncPaidAdvertisingGoalTarget;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\PaidAdvertisingGoalBoard;
use App\Models\PaidAdvertisingGoalField;
use App\Models\PaidAdvertisingGoalRecord;
use App\Models\PaidAdvertisingGoalSyncRun;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreBusinessCredential;
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

    public function test_user_with_reports_permission_can_open_each_remaining_empty_paid_advertising_page(): void
    {
        [$user, $organization, $store] = $this->context('operator');

        foreach ($this->emptyPages() as $routeName => $title) {
            $this->actingAs($user)
                ->withSession($this->contextSession($organization, $store))
                ->get(route($routeName))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('PaidAdvertising/Empty')
                    ->where('title', $title));
        }
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
                    'advertising_goals_table_id',
                    'advertising_goals_view_id',
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
        [$user, $organization, $store] = $this->context('organization-admin');
        $otherStore = $this->addStore($user, $organization, 'Other Advertising Store', 'other-paid-advertising.myshopify.com');
        $values = [
            'feishu_app_token' => 'sensitive-goals-app-token',
            'feishu_table_id' => 'tbl-goals-current',
            'feishu_view_id' => 'vew-goals-current',
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
            ->assertDontSee($values['feishu_table_id'])
            ->assertDontSee($values['feishu_view_id'])
            ->assertInertia(fn (Assert $page) => $page
                ->where('store.id', $store->id)
                ->where('goalPage.configuration.configured', true)
                ->where('goalPage.configuration.has_configuration', true)
                ->where('goalPage.configuration.missing_fields', [])
                ->where('goalPage.tabs.0.deletable', false)
                ->where('goalPage.tabs.0.clearable', true)
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
            ->whereIn('credential_key', [
                'advertising_goals_app_token',
                'advertising_goals_table_id',
                'advertising_goals_view_id',
            ])
            ->pluck('credential_value');
        $this->assertCount(3, $rawCredentials);
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
        foreach (['日期', '今日销售额', '月销售额之和', '月销售额目标', 'FB销售额-Macfox', '本月FB销售目标', '本月Criteo销售目标'] as $position => $name) {
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
        foreach (['日期', 'FB花费-黄智诚', 'Criteo花费'] as $position => $name) {
            PaidAdvertisingGoalField::query()->create([
                'organization_id' => $organization->id,
                'store_id' => $store->id,
                'goal_board_id' => null,
                'source_key' => 'overall',
                'source_field_id' => 'overall-personal-field-'.$position,
                'name' => $name,
                'type' => $position === 0 ? 5 : 2,
                'field_order' => $position,
                'is_primary' => $position === 0,
                'synced_at' => now(),
            ]);
        }
        foreach ([
            ['2026-08-20', 250, 500, 150],
            ['2026-08-21', 300, 800, 200],
            ['2026-08-22', 0, 800, 0],
        ] as [$date, $dailySales, $monthlySales, $facebookSales]) {
            PaidAdvertisingGoalRecord::query()->create([
                'organization_id' => $organization->id,
                'store_id' => $store->id,
                'goal_board_id' => $board->id,
                'source_key' => 'board:'.$board->id,
                'source_record_id' => 'personal-'.$date,
                'fields_encrypted' => [
                    '日期' => CarbonImmutable::parse($date, 'Asia/Shanghai')->getTimestampMs(),
                    '今日销售额' => $dailySales,
                    '月销售额之和' => $monthlySales,
                    '月销售额目标' => 1000,
                    'FB销售额-Macfox' => $facebookSales,
                    '本月FB销售目标' => 600,
                ],
                'synced_at' => now(),
            ]);
        }
        foreach ([
            ['2026-08-20', 60, 20],
            ['2026-08-21', 40, 20],
            ['2026-08-22', 900, 900],
        ] as [$date, $facebookSpend, $criteoSpend]) {
            PaidAdvertisingGoalRecord::query()->create([
                'organization_id' => $organization->id,
                'store_id' => $store->id,
                'goal_board_id' => null,
                'source_key' => 'overall',
                'source_record_id' => 'overall-'.$date,
                'fields_encrypted' => [
                    '日期' => CarbonImmutable::parse($date, 'Asia/Shanghai')->getTimestampMs(),
                    'FB花费-黄智诚' => [$facebookSpend],
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
                    ->where('goalPage.template_data.personal_facebook.values.monthly_spend', 140)
                    ->where('goalPage.template_data.personal_facebook.values.roas', 5.71)
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
                    ->where('goalPage.template_data.personal_facebook.facebook.values.remaining_days', 0));
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
        [$user, $organization, $store] = $this->context('organization-admin');
        $otherStore = $this->addStore($user, $organization, 'Overall Isolation Store', 'overall-isolation.myshopify.com');
        $values = [
            'feishu_app_token' => 'overall-sensitive-token',
            'feishu_table_id' => 'tbl-overall-targets',
            'feishu_view_id' => 'vew-overall-targets',
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
        $this->assertSame(3, StoreBusinessCredential::query()
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
        $this->assertSame(3, data_get($audit->old_values, 'credentials_deleted'));
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
            'paid-advertising.facebook' => 'Facebook Ads',
            'paid-advertising.google' => 'Google Ads',
            'paid-advertising.tiktok' => 'TikTok Ads',
            'paid-advertising.bing' => 'Bing Ads',
            'paid-advertising.criteo' => 'Criteo',
        ];
    }

    /** @return list<string> */
    private function routeNames(): array
    {
        return ['paid-advertising.goals', ...array_keys($this->emptyPages())];
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

    /** @return array{current_organization_id: int, current_store_id: int} */
    private function contextSession(Organization $organization, Store $store): array
    {
        return [
            'current_organization_id' => $organization->id,
            'current_store_id' => $store->id,
        ];
    }
}
