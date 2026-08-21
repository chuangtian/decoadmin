<?php

namespace Tests\Feature;

use App\Models\AmazonDailySale;
use App\Models\Organization;
use App\Models\Store;
use App\Models\StoreBusinessCredential;
use App\Services\Feishu\AmazonDailySalesSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FeishuAmazonDailySalesSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_maps_feishu_headers_and_updates_the_same_store_date_idempotently(): void
    {
        Config::set('services.feishu_table.app_id', 'cli_test');
        Config::set('services.feishu_table.app_secret', 'test-secret');
        [$organization, $store] = $this->storeContext('one');
        $this->amazonCredentials($store, 'app_one', 'tbl_one', 'vew_one');
        $totalSales = 4778.0;
        $this->fakeFeishu(function (string $appToken) use (&$totalSales): array {
            $this->assertSame('app_one', $appToken);

            return [$this->record('rec_one', $totalSales)];
        });

        $first = app(AmazonDailySalesSyncService::class)->syncStore($store);
        $this->assertSame(['inserted' => 1, 'updated' => 0, 'skipped' => 0, 'records' => 1], $first);

        $sale = AmazonDailySale::query()->sole();
        $this->assertSame($organization->id, $sale->organization_id);
        $this->assertSame($store->id, $sale->store_id);
        $this->assertSame('2026-01-01', $sale->sale_date->toDateString());
        $this->assertSame(9, $sale->fba_order_count);
        $this->assertSame('521.0000', $sale->fba_sales);
        $this->assertSame('4778.0000', $sale->total_sales);
        $this->assertSame(1000, $sale->sp_impressions);
        $this->assertSame('0.120000', $sale->sp_click_through_rate);
        $this->assertContains("配件\n退款", $sale->remarks['headers']);
        $this->assertContains('列17', $sale->remarks['unmapped_headers']);
        $this->assertArrayNotHasKey('source_fields', $sale->remarks);

        $totalSales = 5000.0;
        $second = app(AmazonDailySalesSyncService::class)->syncStore($store);
        $this->assertSame(['inserted' => 0, 'updated' => 1, 'skipped' => 0, 'records' => 1], $second);
        $this->assertDatabaseCount('amazon_daily_sales', 1);
        $this->assertSame('5000.0000', AmazonDailySale::query()->sole()->total_sales);
    }

    public function test_scheduled_sync_keeps_organizations_and_stores_isolated(): void
    {
        Config::set('services.feishu_table.app_id', 'cli_test');
        Config::set('services.feishu_table.app_secret', 'test-secret');
        [$firstOrganization, $firstStore] = $this->storeContext('first');
        [$secondOrganization, $secondStore] = $this->storeContext('second');
        $this->amazonCredentials($firstStore, 'app_first', 'tbl_first', 'vew_first');
        $this->amazonCredentials($secondStore, 'app_second', 'tbl_second', 'vew_second');
        $this->fakeFeishu(fn (string $appToken): array => [
            $this->record($appToken === 'app_first' ? 'rec_first' : 'rec_second', $appToken === 'app_first' ? 100.0 : 200.0),
        ]);

        $result = app(AmazonDailySalesSyncService::class)->syncConfiguredStores();

        $this->assertSame(2, $result['stores']);
        $this->assertSame(2, $result['inserted']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame('100.0000', AmazonDailySale::query()->forOrganization($firstOrganization)->forStore($firstStore)->sole()->total_sales);
        $this->assertSame('200.0000', AmazonDailySale::query()->forOrganization($secondOrganization)->forStore($secondStore)->sole()->total_sales);
        $this->assertFalse(
            AmazonDailySale::query()->forOrganization($firstOrganization)->forStore($secondStore)->exists(),
            'A store must never be readable through another organization scope.',
        );
    }

    public function test_amazon_sync_is_scheduled_for_nine_am_in_beijing(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains($event->command, 'feishu:sync-amazon-daily-sales'));

        $this->assertNotNull($event);
        $this->assertSame('0 9 * * *', $event->expression);
        $this->assertSame('Asia/Shanghai', $event->timezone);
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
                        fn (string $header): array => ['field_name' => $header, 'type' => $header === '日期' ? 5 : 2],
                    )->all()],
                ]);
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
    private function record(string $recordId, float $totalSales): array
    {
        return [
            'record_id' => $recordId,
            'created_time' => '1767225600',
            'last_modified_time' => '1767229200',
            'fields' => [
                '日期' => CarbonImmutable::create(2026, 1, 1, 0, 0, 0, 'Asia/Shanghai')->getTimestampMs(),
                '星期' => '四',
                'FBA单量' => 9,
                'FBA销售额' => 521,
                'FBM单量' => 3,
                'FBM销售额' => 4257,
                '总销售额' => $totalSales,
                "配件\n退款" => 0,
                '退款' => 10,
                '总退款' => 10,
                '净销售' => $totalSales - 10,
                '退款占比' => '1.5%',
                '广告花费' => 300,
                '广告占比' => 0.0628,
                'ROI' => 15.9,
                '列17' => 999,
                'SP曝光' => 1000,
                'SP点击' => 120,
                'SP点击率' => '12%',
                'SP花费' => 100,
                'SP广告销售额' => 500,
                'SP单量' => 5,
                'SP-ACOS' => '20%',
                'SP转化率' => '4.17%',
                'SB曝光' => 2000,
                'SB点击' => 100,
                'SB点击率' => '5%',
                'SB花费' => 80,
                'SB广告销售额' => 400,
                'SB单量' => 4,
                'SB-ACOS' => '20%',
                'SB转化率' => '4%',
                'SD 曝光' => 3000,
                'SD 点击' => 90,
                'SD 点击率' => '3%',
                'SD 花费' => 120,
                'SD 广告销售额' => 600,
                'SD 广告单量' => 6,
                'SD ACOS' => '20%',
                'SD 转化率' => '6.67%',
            ],
        ];
    }

    /** @return list<string> */
    private function headers(): array
    {
        return [
            '日期', '星期', 'FBA单量', 'FBA销售额', 'FBM单量', 'FBM销售额', '总销售额', "配件\n退款", '退款', '总退款',
            '净销售', '退款占比', '广告花费', '广告占比', 'ROI', '列17',
            'SP曝光', 'SP点击', 'SP点击率', 'SP花费', 'SP广告销售额', 'SP单量', 'SP-ACOS', 'SP转化率',
            'SB曝光', 'SB点击', 'SB点击率', 'SB花费', 'SB广告销售额', 'SB单量', 'SB-ACOS', 'SB转化率',
            'SD 曝光', 'SD 点击', 'SD 点击率', 'SD 花费', 'SD 广告销售额', 'SD 广告单量', 'SD ACOS', 'SD 转化率',
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
            'name' => "Amazon {$suffix}",
            'shopify_domain' => "amazon-{$suffix}.myshopify.com",
            'status' => 'active',
            'timezone' => 'America/Los_Angeles',
            'currency' => 'USD',
        ]);

        return [$organization, $store];
    }

    private function amazonCredentials(Store $store, string $appToken, string $tableId, string $viewId): void
    {
        foreach ([
            'amazon_app_token' => $appToken,
            'amazon_table_id' => $tableId,
            'amazon_view_id' => $viewId,
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
