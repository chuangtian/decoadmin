<?php

namespace Tests\Feature;

use App\Models\FeishuBitableRecord;
use App\Models\Organization;
use App\Models\Store;
use App\Models\StoreBusinessCredential;
use App\Services\NaturalTraffic\NaturalTrafficDataSyncService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NaturalTrafficSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_brand_wiki_spreadsheet_is_archived_in_current_project_database(): void
    {
        Config::set('services.feishu_table.app_id', 'current-project-app');
        Config::set('services.feishu_table.app_secret', 'current-project-secret');
        [$organization, $store] = $this->storeContext('brand');
        $this->credential($store, 'social_wiki_node', 'wiki_social_current');

        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/auth/v3/tenant_access_token/internal')) {
                return Http::response(['code' => 0, 'tenant_access_token' => 'tenant-token']);
            }
            if (str_ends_with($path, '/wiki/v2/spaces/get_node')) {
                $this->assertSame('wiki_social_current', $request->data()['token'] ?? null);

                return Http::response(['code' => 0, 'data' => ['node' => ['obj_type' => 'sheet', 'obj_token' => 'sheet_current_project']]]);
            }
            if (str_ends_with($path, '/sheets/v3/spreadsheets/sheet_current_project/sheets/query')) {
                return Http::response(['code' => 0, 'data' => ['sheets' => [[
                    'sheet_id' => 'social_weekly', 'title' => '品牌官媒周数据',
                    'grid_properties' => ['row_count' => 50, 'column_count' => 10],
                ]]]]);
            }
            if (str_contains($path, '/sheets/v2/spreadsheets/sheet_current_project/values/')) {
                return Http::response(['code' => 0, 'data' => ['valueRange' => ['values' => [
                    ['日期', '平台', '帖子数', '浏览量', '点赞', '评论数', '分享数'],
                    ['2026-08-18', 'Instagram', 2, 25000, 1300, 115, 8],
                ]]]]);
            }

            return Http::response(['code' => 404], 404);
        });

        $result = app(NaturalTrafficDataSyncService::class)->sync($store, 'brand-media');

        $this->assertSame(1, $result['tables']);
        $this->assertSame(1, $result['records']);
        $record = FeishuBitableRecord::query()->sole();
        $this->assertSame($organization->id, $record->organization_id);
        $this->assertSame($store->id, $record->store_id);
        $this->assertSame('Instagram', $record->fields_encrypted['平台']);
        $this->assertStringNotContainsString('Instagram', (string) DB::table('feishu_bitable_records')->value('fields_encrypted'));
    }

    public function test_affiliate_sync_uses_current_store_configured_view_and_replaces_local_snapshot(): void
    {
        Config::set('services.feishu_table.app_id', 'current-project-app');
        Config::set('services.feishu_table.app_secret', 'current-project-secret');
        [, $store] = $this->storeContext('affiliate');
        foreach (['affiliate_app_token' => 'app_current_affiliate', 'affiliate_table_id' => 'tbl_current_affiliate', 'affiliate_view_id' => 'vew_current_affiliate'] as $key => $value) {
            $this->credential($store, $key, $value);
        }

        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/auth/v3/tenant_access_token/internal')) {
                return Http::response(['code' => 0, 'tenant_access_token' => 'tenant-token']);
            }
            if (str_ends_with($path, '/apps/app_current_affiliate/tables')) {
                return Http::response(['code' => 0, 'data' => ['has_more' => false, 'items' => [[
                    'table_id' => 'tbl_current_affiliate', 'name' => '联盟周数据',
                ]]]]);
            }
            if (str_ends_with($path, '/fields')) {
                return Http::response(['code' => 0, 'data' => ['has_more' => false, 'items' => [
                    ['field_id' => 'fld_name', 'field_name' => 'Name', 'type' => 1, 'is_primary' => true],
                    ['field_id' => 'fld_gmv', 'field_name' => 'GMV', 'type' => 2],
                ]]]);
            }
            if (str_ends_with($path, '/records')) {
                $this->assertSame('vew_current_affiliate', $request->data()['view_id'] ?? null);

                return Http::response(['code' => 0, 'data' => ['has_more' => false, 'items' => [[
                    'record_id' => 'rec_partner', 'fields' => ['Name' => 'Partner A', 'GMV' => 1288.5],
                ]]]]);
            }

            return Http::response(['code' => 404], 404);
        });

        $result = app(NaturalTrafficDataSyncService::class)->sync($store, 'affiliate-marketing');

        $this->assertSame(1, $result['tables']);
        $this->assertSame(1, $result['records']);
        $this->assertSame('Partner A', FeishuBitableRecord::query()->sole()->fields_encrypted['Name']);
    }

    public function test_natural_traffic_database_sync_is_scheduled_daily(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains((string) $event->command, 'natural-traffic:sync'));

        $this->assertNotNull($event);
        $this->assertSame('25 4 * * *', $event->expression);
        $this->assertSame('Asia/Shanghai', $event->timezone);
    }

    /** @return array{Organization, Store} */
    private function storeContext(string $suffix): array
    {
        $organization = Organization::query()->create(['name' => "Natural {$suffix}", 'code' => "natural-{$suffix}"]);
        $store = $organization->stores()->create([
            'name' => "Natural {$suffix}", 'shopify_domain' => "natural-{$suffix}.myshopify.com", 'status' => 'active',
        ]);

        return [$organization, $store];
    }

    private function credential(Store $store, string $key, string $value): void
    {
        StoreBusinessCredential::query()->create([
            'organization_id' => $store->organization_id,
            'store_id' => $store->id,
            'provider' => 'feishu_data_links',
            'credential_key' => $key,
            'credential_value' => $value,
        ]);
    }
}
