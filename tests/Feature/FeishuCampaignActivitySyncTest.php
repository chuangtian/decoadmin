<?php

namespace Tests\Feature;

use App\Models\CampaignActivity;
use App\Models\Organization;
use App\Models\Store;
use App\Models\StoreBusinessCredential;
use App\Services\Feishu\CampaignActivitySyncService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FeishuCampaignActivitySyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_maps_all_campaign_field_types_and_updates_by_source_record_id(): void
    {
        Storage::fake('public');
        Config::set('services.feishu_table.app_id', 'cli_test');
        Config::set('services.feishu_table.app_secret', 'test-secret');
        [$organization, $store] = $this->storeContext('one');
        $this->campaignCredentials($store, 'app_one', 'tbl_one', 'vew_one');
        $sales = 212094.04;
        $this->fakeFeishu(function (string $appToken) use (&$sales): array {
            $this->assertSame('app_one', $appToken);

            return [$this->record('rec_campaign', $sales)];
        });

        $first = app(CampaignActivitySyncService::class)->syncStore($store);
        $this->assertSame(['inserted' => 1, 'updated' => 0, 'skipped' => 0, 'records' => 1], $first);

        $activity = CampaignActivity::query()->sole();
        $this->assertSame($organization->id, $activity->organization_id);
        $this->assertSame($store->id, $activity->store_id);
        $this->assertSame('MACFOX-2026-010', $activity->campaign_id);
        $this->assertSame('2026-02-11', $activity->starts_on->toDateString());
        $this->assertSame('2026-02-16', $activity->ends_on->toDateString());
        $this->assertSame('总统日', $activity->campaign_name);
        $this->assertSame('https://example.feishu.cn/docx/planning', $activity->planning_document);
        $this->assertSame('212094.0400', $activity->sales_amount);
        $this->assertSame('8.864884', $activity->roi);
        $this->assertSame(201, $activity->order_count);
        $this->assertSame(47077, $activity->store_visits);
        $this->assertSame('0.0042696009', $activity->conversion_rate);
        $this->assertSame(
            '/storage/feishu/campaigns/'.$organization->id.'/'.$store->id.'/rec_campaign/campaign_images/optimized/file_one.webp',
            $activity->campaign_images[0],
        );
        $this->assertSame(
            '/storage/feishu/campaigns/'.$organization->id.'/'.$store->id.'/rec_campaign/email_content/optimized/file_two.webp',
            $activity->email_content[0],
        );
        Storage::disk('public')->assertExists(
            'feishu/campaigns/'.$organization->id.'/'.$store->id.'/rec_campaign/campaign_images/optimized/file_one.webp',
        );
        Storage::disk('public')->assertExists(
            'feishu/campaigns/'.$organization->id.'/'.$store->id.'/rec_campaign/email_content/optimized/file_two.webp',
        );
        $this->assertStringStartsWith(
            'RIFF',
            Storage::disk('public')->get(
                'feishu/campaigns/'.$organization->id.'/'.$store->id.'/rec_campaign/campaign_images/optimized/file_one.webp',
            ),
        );
        $this->assertSame('rec_parent', $activity->parent_records[0]['record_id']);
        $this->assertSame('保留的新字段', $activity->unmapped_fields['未来新增字段']);

        $sales = 300000.0;
        $second = app(CampaignActivitySyncService::class)->syncStore($store);
        $this->assertSame(['inserted' => 0, 'updated' => 1, 'skipped' => 0, 'records' => 1], $second);
        $this->assertDatabaseCount('campaign_activities', 1);
        $this->assertSame('300000.0000', CampaignActivity::query()->sole()->sales_amount);
    }

    public function test_manual_campaign_sync_keeps_store_scopes_isolated_and_is_not_scheduled(): void
    {
        Storage::fake('public');
        Config::set('services.feishu_table.app_id', 'cli_test');
        Config::set('services.feishu_table.app_secret', 'test-secret');
        [$firstOrganization, $firstStore] = $this->storeContext('first');
        [$secondOrganization, $secondStore] = $this->storeContext('second');
        $this->campaignCredentials($firstStore, 'app_first', 'tbl_first', 'vew_first');
        $this->campaignCredentials($secondStore, 'app_second', 'tbl_second', 'vew_second');
        $this->fakeFeishu(fn (string $appToken): array => [
            $this->record('shared_record', $appToken === 'app_first' ? 100.0 : 200.0),
        ]);

        $result = app(CampaignActivitySyncService::class)->syncConfiguredStores();

        $this->assertSame(2, $result['stores']);
        $this->assertSame(2, $result['inserted']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame(
            '100.0000',
            CampaignActivity::query()->forOrganization($firstOrganization)->forStore($firstStore)->sole()->sales_amount,
        );
        $this->assertSame(
            '200.0000',
            CampaignActivity::query()->forOrganization($secondOrganization)->forStore($secondStore)->sole()->sales_amount,
        );
        $this->assertFalse(
            CampaignActivity::query()->forOrganization($firstOrganization)->forStore($secondStore)->exists(),
        );

        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains((string) $event->command, 'feishu:sync-campaign-activities'));

        $this->assertNull($event, '活动主题同步必须仅允许手动执行，不能加入定时计划。');
    }

    /** @param callable(string): list<array<string, mixed>> $records */
    private function fakeFeishu(callable $records): void
    {
        Http::fake(function (Request $request) use ($records) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if (str_ends_with($path, '/auth/v3/tenant_access_token/internal')) {
                return Http::response(['code' => 0, 'tenant_access_token' => 'tenant-token']);
            }

            if (str_ends_with($path, '/fields')) {
                return Http::response([
                    'code' => 0,
                    'data' => ['has_more' => false, 'items' => collect($this->headers())->map(
                        fn (string $header): array => ['field_name' => $header, 'type' => 1],
                    )->all()],
                ]);
            }

            if (preg_match('#/drive/v1/medias/([^/]+)/download$#', $path, $matches)) {
                return Http::response(
                    base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='),
                    200,
                    ['Content-Type' => 'image/png'],
                );
            }

            if (str_ends_with($path, '/records')) {
                preg_match('#/apps/([^/]+)/tables/#', $path, $matches);

                return Http::response([
                    'code' => 0,
                    'data' => ['has_more' => false, 'items' => $records($matches[1] ?? '')],
                ]);
            }

            return Http::response(['code' => 404], 404);
        });
    }

    /** @return array<string, mixed> */
    private function record(string $recordId, float $sales): array
    {
        return [
            'record_id' => $recordId,
            'created_time' => '1770739200',
            'last_modified_time' => '1770742800',
            'fields' => [
                '活动ID' => 'MACFOX-2026-010',
                '活动开始日期' => CarbonImmutable::create(2026, 2, 11, 0, 0, 0, 'Asia/Shanghai')->getTimestampMs(),
                '活动结束日期' => CarbonImmutable::create(2026, 2, 16, 0, 0, 0, 'Asia/Shanghai')->getTimestampMs(),
                '活动名称' => '总统日',
                '主标题' => 'Ride Into the Presidents’ Day Sale',
                '副标题' => 'Save on Macfox',
                '策划书' => [[
                    'link' => 'https://example.feishu.cn/docx/planning',
                    'text' => '活动策划链接',
                    'type' => 'mention',
                ]],
                '核心优惠' => '$100 off Coupon Code',
                '活动图片' => [['file_token' => 'file_one', 'name' => 'banner.jpg']],
                '邮件内容' => [['file_token' => 'file_two', 'name' => 'email.png']],
                '销售额' => (string) $sales,
                '广告花费' => '23925.192',
                'ROI' => 8.86488350856286,
                '订单数' => '201',
                '店铺访问' => '47077',
                '转化率' => 0.00426960086666525,
                '日均销售额' => 35349.0066666667,
                '日均店铺访问' => 7846.16666666667,
                '日均订单数' => 33.5,
                '日均广告花费' => 3987.532,
                '活动总结' => '活动总结内容',
                '问题诊断' => '问题诊断内容',
                '优化分析' => '优化分析内容',
                '单选' => '已复盘',
                '父记录' => [['record_id' => 'rec_parent', 'text' => '父活动']],
                '未来新增字段' => '保留的新字段',
            ],
        ];
    }

    /** @return list<string> */
    private function headers(): array
    {
        return [
            '活动ID', '活动开始日期', '活动结束日期', '活动名称', '主标题', '副标题', '策划书', '核心优惠',
            '活动图片', '邮件内容', '销售额', '广告花费', 'ROI', '订单数', '店铺访问', '转化率',
            '日均销售额', '日均店铺访问', '日均订单数', '日均广告花费', '活动总结', '问题诊断', '优化分析',
            '单选', '父记录', '未来新增字段',
        ];
    }

    /** @return array{Organization, Store} */
    private function storeContext(string $suffix): array
    {
        $organization = Organization::query()->create([
            'name' => "Organization {$suffix}",
            'code' => "organization-{$suffix}",
        ]);
        $store = $organization->stores()->create([
            'name' => "Campaign {$suffix}",
            'shopify_domain' => "campaign-{$suffix}.myshopify.com",
            'status' => 'active',
            'timezone' => 'America/Los_Angeles',
            'currency' => 'USD',
        ]);

        return [$organization, $store];
    }

    private function campaignCredentials(Store $store, string $appToken, string $tableId, string $viewId): void
    {
        foreach ([
            'campaign_app_token' => $appToken,
            'campaign_table_id' => $tableId,
            'campaign_view_id' => $viewId,
        ] as $key => $value) {
            StoreBusinessCredential::query()->create([
                'organization_id' => $store->organization_id,
                'store_id' => $store->id,
                'provider' => 'feishu_data_links',
                'credential_key' => $key,
                'credential_value' => $value,
            ]);
        }
    }
}
