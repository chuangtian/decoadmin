<?php

namespace Tests\Feature;

use App\Jobs\DeliverMfDailyReportJob;
use App\Models\FeishuBitableRecord;
use App\Models\FeishuBitableTable;
use App\Models\MfDailyReportDelivery;
use App\Models\Organization;
use App\Models\Store;
use App\Models\StoreBusinessCredential;
use App\Models\StoreNotificationSetting;
use App\Services\Feishu\MfDailyReportFormatter;
use App\Services\Feishu\MfDailyReportImageRenderer;
use App\Services\Feishu\MfDailyReportSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MfDailyReportSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.feishu_table.app_id', 'mf-report-test');
        Config::set('services.feishu_table.app_secret', 'mf-report-secret');
    }

    public function test_existing_nightly_sync_is_unchanged_and_mf_report_runs_at_three_thirty_pm_in_beijing(): void
    {
        $events = collect(app(Schedule::class)->events());
        $nightly = $events->first(fn ($event): bool => str_contains($event->command, 'feishu:sync-paid-advertising-goals'));
        $dailyReport = $events->first(fn ($event): bool => str_contains($event->command, 'feishu:sync-mf-daily-reports'));

        $this->assertNotNull($nightly);
        $this->assertSame('40 3 * * *', $nightly->expression);
        $this->assertSame('Asia/Shanghai', $nightly->timezone);
        $this->assertNotNull($dailyReport);
        $this->assertSame('30 15 * * *', $dailyReport->expression);
        $this->assertSame('Asia/Shanghai', $dailyReport->timezone);
    }

    public function test_it_baselines_then_syncs_only_mf_table_and_queues_one_message_for_the_latest_new_record(): void
    {
        Queue::fake();
        [, $store] = $this->storeContext();
        $this->dataSource($store);
        $records = [$this->record('rec-old', '2026-09-09')];
        $requestedPaths = [];
        $this->fakeBitable($records, $requestedPaths);

        CarbonImmutable::setTestNow('2026-09-10 15:30:00 Asia/Shanghai');
        $first = app(MfDailyReportSyncService::class)->syncStore($store);

        $this->assertTrue($first['baseline']);
        $this->assertSame(1, $first['inserted']);
        $this->assertSame(0, $first['queued']);
        Queue::assertNothingPushed();
        $this->assertDatabaseHas('feishu_bitable_tables', [
            'store_id' => $store->id,
            'source_section' => MfDailyReportSyncService::SOURCE_SECTION,
            'source_table_id' => 'tbl_mf',
            'name' => 'MF数据表',
        ]);
        $this->assertFalse(collect($requestedPaths)->contains(fn (string $path): bool => str_contains($path, 'tbl_other/fields')));

        CarbonImmutable::setTestNow('2026-09-11 15:30:00 Asia/Shanghai');
        $records[] = $this->record('rec-newer', '2026-09-10', 31000);
        $records[] = $this->record('rec-newest', '2026-09-11', 32000);
        $second = app(MfDailyReportSyncService::class)->syncStore($store);

        $this->assertFalse($second['baseline']);
        $this->assertSame(2, $second['inserted']);
        $this->assertSame(1, $second['queued']);
        $delivery = MfDailyReportDelivery::query()->sole();
        $this->assertSame('rec-newest', $delivery->source_record_id);
        $this->assertSame('2026-09-11', $delivery->report_date->toDateString());
        Queue::assertPushed(DeliverMfDailyReportJob::class, 1);

        CarbonImmutable::setTestNow('2026-09-12 15:30:00 Asia/Shanghai');
        $third = app(MfDailyReportSyncService::class)->syncStore($store);
        $this->assertSame(0, $third['inserted']);
        $this->assertSame(0, $third['queued']);
        Queue::assertPushed(DeliverMfDailyReportJob::class, 1);
        $this->assertDatabaseCount('mf_daily_report_deliveries', 1);

        CarbonImmutable::setTestNow();
    }

    public function test_it_uses_the_accessible_meta_weekly_base_when_the_goal_token_is_a_spreadsheet(): void
    {
        Queue::fake();
        [, $store] = $this->storeContext();
        foreach ([
            'advertising_goals_app_token' => 'app_invalid',
            'advertising_meta_weekly_app_token' => 'app_base',
        ] as $key => $value) {
            StoreBusinessCredential::query()->create([
                'organization_id' => $store->organization_id,
                'store_id' => $store->id,
                'provider' => 'feishu_data_links',
                'credential_key' => $key,
                'credential_value' => $value,
            ]);
        }
        $records = [$this->record('rec-meta-source', '2026-09-09')];
        $requestedPaths = [];
        $this->fakeBitable($records, $requestedPaths);

        $result = app(MfDailyReportSyncService::class)->syncStore($store);

        $this->assertTrue($result['baseline']);
        $this->assertSame(1, $result['records']);
        $this->assertTrue(collect($requestedPaths)->contains(fn (string $path): bool => str_contains($path, '/apps/app_invalid/tables')));
        $this->assertTrue(collect($requestedPaths)->contains(fn (string $path): bool => str_contains($path, '/apps/app_base/tables')));
        Queue::assertNothingPushed();
    }

    public function test_it_formats_the_complete_report_and_sends_text_and_dashboard_as_one_post(): void
    {
        [$organization, $store] = $this->storeContext();
        StoreNotificationSetting::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'feishu_enabled' => true,
            'feishu_webhook_url' => 'https://open.feishu.cn/open-apis/bot/v2/hook/report-test',
            'feishu_secret' => 'signing-secret',
        ]);
        $table = FeishuBitableTable::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'source_section' => MfDailyReportSyncService::SOURCE_SECTION,
            'source_table_id' => 'tbl_mf',
            'name' => 'MF数据表',
            'synced_at' => now(),
        ]);
        $record = FeishuBitableRecord::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'feishu_bitable_table_id' => $table->id,
            'source_record_id' => 'rec-report',
            'fields_encrypted' => $this->fields('2026-09-09'),
            'synced_at' => now(),
        ]);
        $delivery = MfDailyReportDelivery::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'source_table_id' => 'tbl_mf',
            'source_record_id' => $record->source_record_id,
            'report_date' => '2026-09-09',
            'status' => 'pending',
        ]);
        $webhookPayload = null;
        Http::fake(function (Request $request) use (&$webhookPayload) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/auth/v3/tenant_access_token/internal')) {
                return Http::response(['code' => 0, 'tenant_access_token' => 'tenant-token']);
            }
            if (str_ends_with($path, '/im/v1/images')) {
                return Http::response(['code' => 0, 'data' => ['image_key' => 'img_report_test']]);
            }
            if (str_contains($path, '/bot/v2/hook/')) {
                $webhookPayload = $request->data();

                return Http::response(['StatusCode' => 0, 'StatusMessage' => 'success']);
            }

            return Http::response(['code' => 404], 404);
        });

        app()->call([new DeliverMfDailyReportJob($delivery->id), 'handle']);

        $delivery->refresh();
        $this->assertSame('sent', $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertNotNull($delivery->sent_at);
        $this->assertSame('post', $webhookPayload['msg_type']);
        $content = $webhookPayload['content']['post']['zh_cn']['content'];
        $text = collect($content)->flatten(1)->where('tag', 'text')->pluck('text')->implode("\n");
        $this->assertStringContainsString('Macfox 每日数据汇报', $text);
        $this->assertStringContainsString('总销售额：29263.06（退款：0.00）', $text);
        $this->assertStringContainsString('截止目前，09月销售额：399097.14，09月总退款：20974.82，09月总ROI：4.12', $text);
        $this->assertStringContainsString('距离本月185万的目标还差：1450902.86，日均还需：69090.61', $text);
        foreach (['Google:', 'Meta智诚:', 'Meta静彬:', 'Criteo:', 'Bing:', 'Tiktok:'] as $label) {
            $this->assertStringContainsString($label, $text);
        }
        $this->assertSame('img', last($content)[0]['tag']);
        $this->assertSame('img_report_test', last($content)[0]['image_key']);
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'action' => 'mf_daily_report_sent',
            'subject_id' => $delivery->id,
        ]);
        Http::assertSentCount(3);
    }

    public function test_dashboard_renderer_outputs_a_full_size_png(): void
    {
        [, $store] = $this->storeContext();
        $formatter = app(MfDailyReportFormatter::class);
        $report = $formatter->report($store, $this->fields('2026-09-09'));
        $this->assertNotNull($report);
        $png = app(MfDailyReportImageRenderer::class)->render($report, [$report]);
        $size = getimagesizefromstring($png);

        $this->assertStringStartsWith("\x89PNG", $png);
        $this->assertSame(1690, $size[0]);
        $this->assertSame(900, $size[1]);
    }

    public function test_formatter_detects_an_incomplete_row_before_it_can_be_sent(): void
    {
        $fields = $this->fields('2026-09-09');
        unset($fields['Tiktok的ROI']);

        $formatter = app(MfDailyReportFormatter::class);
        $this->assertSame(['Tiktok的ROI'], $formatter->missingFields($fields));
    }

    /** @param list<array<string, mixed>> $records */
    private function fakeBitable(array &$records, array &$requestedPaths): void
    {
        Http::fake(function (Request $request) use (&$records, &$requestedPaths) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $requestedPaths[] = $path;
            if (str_ends_with($path, '/auth/v3/tenant_access_token/internal')) {
                return Http::response(['code' => 0, 'tenant_access_token' => 'tenant-token']);
            }
            if (str_contains($path, '/apps/app_invalid/tables')) {
                return Http::response(['code' => 91402, 'msg' => 'NOTEXIST'], 400);
            }
            if (str_ends_with($path, '/tables')) {
                return Http::response(['code' => 0, 'data' => ['has_more' => false, 'items' => [
                    ['table_id' => 'tbl_mf', 'name' => 'MF数据表'],
                    ['table_id' => 'tbl_other', 'name' => 'Meta 周数据'],
                ]]]);
            }
            if (str_ends_with($path, '/tbl_mf/fields')) {
                return Http::response(['code' => 0, 'data' => ['has_more' => false, 'items' => collect(array_keys($this->fields('2026-09-09')))
                    ->values()->map(fn (string $name, int $index): array => ['field_id' => 'fld_'.$index, 'field_name' => $name, 'type' => $name === '日期' ? 5 : 2, 'is_primary' => $name === '日期'])->all()]]);
            }
            if (str_ends_with($path, '/tbl_mf/records')) {
                return Http::response(['code' => 0, 'data' => ['has_more' => false, 'items' => $records]]);
            }

            return Http::response(['code' => 404], 404);
        });
    }

    /** @return array<string, mixed> */
    private function record(string $id, string $date, float $sales = 29263.06): array
    {
        return [
            'record_id' => $id,
            'created_time' => CarbonImmutable::parse($date.' 14:00:00', 'Asia/Shanghai')->timestamp,
            'last_modified_time' => CarbonImmutable::parse($date.' 15:00:00', 'Asia/Shanghai')->timestamp,
            'fields' => $this->fields($date, $sales),
        ];
    }

    /** @return array<string, mixed> */
    private function fields(string $date, float $sales = 29263.06): array
    {
        return [
            '日期' => CarbonImmutable::parse($date, 'Asia/Shanghai')->getTimestampMs(),
            '总销售额' => (string) $sales,
            '退款' => '0',
            '总花费' => 8766.563713,
            'ROI（预5%退款）' => 3.1711292942268,
            '月销售额总和' => 399097.14,
            '月总花费总和' => 96766.8302191851,
            '月总退款总和' => 20974.82,
            '月ROI' => 4.1243175899842,
            '月度目标销售额' => '1850000',
            '月每日剩余' => 69090.6123809524,
            'GG销售额' => [2028.27198], 'GG花费' => [3563.123713], 'GG的ROI' => .569239842164302,
            'FB销售额-黄智诚' => [7970.66], 'FB花费-黄智诚' => [2396.94], 'FB的ROI-黄智诚' => 3.32534815222742,
            'FB销售额-王静彬' => [12443.97], 'FB花费-王静彬' => [1769.39], 'FB的ROI-王静彬' => 7.03291529849274,
            'Criteo销售' => [4841.88], 'Criteo花费' => [373.26], 'Criteo的ROI' => 12.971869474361,
            'Bing销售额' => [0], 'Bing花费' => [245.26], 'Bing的ROI' => 0,
            'Tiktok销售额' => [1568], 'Tiktok花费' => [418.59], 'Tiktok的ROI' => 3.74590888458874,
        ];
    }

    /** @return array{Organization, Store} */
    private function storeContext(): array
    {
        $organization = Organization::query()->create(['name' => 'Macfox Organization', 'code' => 'macfox-report']);
        $store = $organization->stores()->create([
            'name' => 'Macfox Bike',
            'shopify_domain' => 'macfox-report.myshopify.com',
            'status' => 'active',
            'timezone' => 'America/Los_Angeles',
            'currency' => 'USD',
        ]);

        return [$organization, $store];
    }

    private function dataSource(Store $store): void
    {
        StoreBusinessCredential::query()->create([
            'organization_id' => $store->organization_id,
            'store_id' => $store->id,
            'provider' => 'feishu_data_links',
            'credential_key' => 'advertising_goals_app_token',
            'credential_value' => 'app_base',
        ]);
    }
}
