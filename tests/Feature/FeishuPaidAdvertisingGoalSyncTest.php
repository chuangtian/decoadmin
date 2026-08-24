<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\PaidAdvertisingGoalBoard;
use App\Models\PaidAdvertisingGoalField;
use App\Models\PaidAdvertisingGoalRecord;
use App\Models\Store;
use App\Models\StoreBusinessCredential;
use App\Services\Feishu\PaidAdvertisingGoalSyncService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FeishuPaidAdvertisingGoalSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_sync_imports_total_and_custom_goal_sources_encrypted_and_idempotently(): void
    {
        Config::set('services.feishu_table.app_id', 'paid_goal_test');
        Config::set('services.feishu_table.app_secret', 'paid-goal-secret');
        [$organization, $store] = $this->storeContext('one');
        $this->overallCredentials($store, 'app_overall', 'tbl_overall', 'vew_overall');
        $board = PaidAdvertisingGoalBoard::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'name' => '个人目标团队',
            'type' => 'personal_facebook',
            'feishu_app_token' => 'app_board',
            'feishu_table_id' => 'tbl_board',
            'feishu_view_id' => 'vew_board',
            'sync_status' => 'pending',
        ]);
        $phase = 1;
        $this->fakeFeishu(
            function (string $appToken, string $viewId) use (&$phase): array {
                if ($appToken === 'app_overall') {
                    $this->assertSame('', $viewId);

                    return [[
                        'record_id' => 'rec_overall',
                        'created_time' => '1785542400000',
                        'last_modified_time' => '1785546000000',
                        'fields' => ['月份' => '2026-08', '总目标' => $phase === 1 ? 100000 : 120000],
                    ]];
                }

                $this->assertSame('app_board', $appToken);
                $this->assertContains($viewId, ['', 'vew_board']);

                return $phase === 1 ? [[
                    'record_id' => 'rec_board',
                    'fields' => ['负责人' => '广告同事', '目标' => 30000],
                ]] : [];
            },
            function (string $appToken) use (&$phase): array {
                if ($appToken === 'app_overall') {
                    return [
                        [
                            'field_id' => 'fld_month',
                            'field_name' => '月份',
                            'type' => 1,
                            'is_primary' => true,
                            'description' => '目标月份',
                            'property' => ['formatter' => 'YYYY-MM'],
                        ],
                        [
                            'field_id' => 'fld_total',
                            'field_name' => $phase === 1 ? '总目标' : '月度总目标',
                            'type' => 2,
                            'is_primary' => false,
                            'property' => ['formatter' => '0.00'],
                        ],
                    ];
                }

                return $phase === 1 ? [
                    ['field_id' => 'fld_owner', 'field_name' => '负责人', 'type' => 11, 'is_primary' => true],
                    ['field_id' => 'fld_board_target', 'field_name' => '目标', 'type' => 2, 'is_primary' => false],
                ] : [
                    ['field_id' => 'fld_owner', 'field_name' => '负责人', 'type' => 11, 'is_primary' => true],
                ];
            },
        );

        $first = app(PaidAdvertisingGoalSyncService::class)->syncConfiguredSources($store->id);

        $this->assertSame(2, $first['sources']);
        $this->assertSame(2, $first['inserted']);
        $this->assertSame(0, $first['updated']);
        $this->assertSame(0, $first['deleted']);
        $this->assertSame(0, $first['failed']);
        $this->assertSame(4, $first['fields']);
        $this->assertDatabaseCount('paid_advertising_goal_records', 2);
        $this->assertDatabaseCount('paid_advertising_goal_fields', 4);

        $overall = PaidAdvertisingGoalRecord::query()->where('source_key', 'overall')->sole();
        $custom = PaidAdvertisingGoalRecord::query()->where('source_key', 'board:'.$board->id)->sole();
        $this->assertSame($organization->id, $overall->organization_id);
        $this->assertSame($store->id, $overall->store_id);
        $this->assertNull($overall->goal_board_id);
        $this->assertSame(['月份' => '2026-08', '总目标' => 100000], $overall->fields_encrypted);
        $this->assertSame($board->id, $custom->goal_board_id);
        $this->assertSame(['负责人' => '广告同事', '目标' => 30000], $custom->fields_encrypted);

        $overallFields = PaidAdvertisingGoalField::query()
            ->where('source_key', 'overall')
            ->orderBy('field_order')
            ->get();
        $this->assertSame(['月份', '总目标'], $overallFields->pluck('name')->all());
        $this->assertSame(['fld_month', 'fld_total'], $overallFields->pluck('source_field_id')->all());
        $this->assertTrue($overallFields->first()->is_primary);
        $this->assertSame('目标月份', $overallFields->first()->description);
        $this->assertSame(['formatter' => 'YYYY-MM'], $overallFields->first()->property_encrypted);

        foreach (DB::table('paid_advertising_goal_records')->pluck('fields_encrypted') as $encryptedFields) {
            $this->assertStringNotContainsString('2026-08', (string) $encryptedFields);
            $this->assertStringNotContainsString('广告同事', (string) $encryptedFields);
        }
        foreach (DB::table('paid_advertising_goal_fields')->whereNotNull('property_encrypted')->pluck('property_encrypted') as $encryptedProperty) {
            $this->assertStringNotContainsString('formatter', (string) $encryptedProperty);
            $this->assertStringNotContainsString('YYYY-MM', (string) $encryptedProperty);
        }

        $board->refresh();
        $this->assertSame('completed', $board->sync_status);
        $this->assertNotNull($board->last_synced_at);
        $this->assertNull($board->last_error);

        $phase = 2;
        $second = app(PaidAdvertisingGoalSyncService::class)->syncConfiguredSources($store->id);

        $this->assertSame(2, $second['sources']);
        $this->assertSame(0, $second['inserted']);
        $this->assertSame(1, $second['updated']);
        $this->assertSame(1, $second['deleted']);
        $this->assertSame(0, $second['failed']);
        $this->assertSame(3, $second['fields']);
        $this->assertDatabaseCount('paid_advertising_goal_records', 1);
        $this->assertDatabaseCount('paid_advertising_goal_fields', 3);
        $this->assertSame(
            ['月份' => '2026-08', '总目标' => 120000],
            PaidAdvertisingGoalRecord::query()->sole()->fields_encrypted,
        );
        $this->assertDatabaseHas('paid_advertising_goal_fields', [
            'source_field_id' => 'fld_total',
            'name' => '月度总目标',
        ]);
        $this->assertDatabaseMissing('paid_advertising_goal_fields', [
            'source_field_id' => 'fld_board_target',
        ]);
    }

    public function test_scheduled_sync_is_store_scoped_and_only_reads_active_stores(): void
    {
        Config::set('services.feishu_table.app_id', 'paid_goal_test');
        Config::set('services.feishu_table.app_secret', 'paid-goal-secret');
        [$firstOrganization, $firstStore] = $this->storeContext('first');
        [$secondOrganization, $secondStore] = $this->storeContext('second');
        $this->overallCredentials($firstStore, 'app_first', 'tbl_first', 'vew_first');
        $this->overallCredentials($secondStore, 'app_second', 'tbl_second', 'vew_second');
        $secondStore->update(['status' => 'inactive']);
        $this->fakeFeishu(
            fn (string $appToken, string $_viewId): array => [[
                'record_id' => 'shared_record',
                'fields' => ['总目标' => $appToken],
            ]],
            fn (string $_appToken): array => [[
                'field_id' => 'fld_total',
                'field_name' => '总目标',
                'type' => 2,
                'is_primary' => true,
            ]],
        );

        $result = app(PaidAdvertisingGoalSyncService::class)->syncConfiguredSources();

        $this->assertSame(1, $result['sources']);
        $this->assertSame(1, $result['inserted']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame(1, $result['fields']);
        $record = PaidAdvertisingGoalRecord::query()->sole();
        $this->assertSame($firstOrganization->id, $record->organization_id);
        $this->assertSame($firstStore->id, $record->store_id);
        $this->assertSame(['总目标' => 'app_first'], $record->fields_encrypted);
        $this->assertFalse(
            PaidAdvertisingGoalRecord::query()
                ->forOrganization($secondOrganization)
                ->forStore($secondStore)
                ->exists(),
        );
        $this->assertSame($firstStore->id, PaidAdvertisingGoalField::query()->sole()->store_id);
    }

    public function test_personal_facebook_sync_imports_its_configured_meta_weekly_feishu_table(): void
    {
        Config::set('services.feishu_table.app_id', 'paid_goal_test');
        Config::set('services.feishu_table.app_secret', 'paid-goal-secret');
        [$organization, $store] = $this->storeContext('meta-weekly');
        $this->metaWeeklyCredentials($store, 'app_meta_weekly', 'tbl_meta_weekly');
        $board = PaidAdvertisingGoalBoard::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'name' => '黄智诚',
            'type' => 'personal_facebook',
            'feishu_app_token' => 'app_personal',
            'feishu_table_id' => 'tbl_personal',
            'feishu_view_id' => 'vew_personal',
            'sync_status' => 'pending',
        ]);
        $this->fakeFeishu(
            function (string $appToken, string $viewId): array {
                if ($appToken === 'app_personal') {
                    $this->assertContains($viewId, ['', 'vew_personal']);

                    return [[
                        'record_id' => 'rec_personal',
                        'fields' => ['日期' => 1786291200000, '今日销售额' => 1234.56],
                    ]];
                }

                $this->assertSame('app_meta_weekly', $appToken);
                $this->assertSame('', $viewId);

                return [[
                    'record_id' => 'rec_meta_weekly',
                    'fields' => [
                        '记录日期' => 1785686400000,
                        '年度第几周' => '32周 08.03~08.09',
                        'FB销售额-黄智诚' => 100000,
                        'FB花费-黄智诚' => 20000,
                        'FB的ROI-黄智诚' => 5,
                    ],
                ]];
            },
            fn (string $appToken): array => $appToken === 'app_personal'
                ? [
                    ['field_id' => 'fld_personal_date', 'field_name' => '日期', 'type' => 5, 'is_primary' => true],
                    ['field_id' => 'fld_personal_sales', 'field_name' => '今日销售额', 'type' => 2, 'is_primary' => false],
                ]
                : [
                    ['field_id' => 'fld_meta_date', 'field_name' => '记录日期', 'type' => 5, 'is_primary' => true],
                    ['field_id' => 'fld_meta_week', 'field_name' => '年度第几周', 'type' => 1, 'is_primary' => false],
                    ['field_id' => 'fld_meta_sales', 'field_name' => 'FB销售额-黄智诚', 'type' => 2, 'is_primary' => false],
                    ['field_id' => 'fld_meta_spend', 'field_name' => 'FB花费-黄智诚', 'type' => 2, 'is_primary' => false],
                    ['field_id' => 'fld_meta_roi', 'field_name' => 'FB的ROI-黄智诚', 'type' => 2, 'is_primary' => false],
                ],
        );

        $result = app(PaidAdvertisingGoalSyncService::class)->syncBoard($board);

        $this->assertSame(2, $result['sources']);
        $this->assertSame(2, $result['inserted']);
        $this->assertSame(7, $result['fields']);
        $this->assertDatabaseCount('paid_advertising_goal_records', 2);
        $this->assertDatabaseCount('paid_advertising_goal_fields', 7);
        $metaRecord = PaidAdvertisingGoalRecord::query()
            ->where('source_key', 'board:'.$board->id.':meta-weekly')
            ->sole();
        $this->assertSame($organization->id, $metaRecord->organization_id);
        $this->assertSame($store->id, $metaRecord->store_id);
        $this->assertSame($board->id, $metaRecord->goal_board_id);
        $this->assertSame('32周 08.03~08.09', $metaRecord->fields_encrypted['年度第几周']);
        $this->assertSame(100000, $metaRecord->fields_encrypted['FB销售额-黄智诚']);
        $this->assertDatabaseHas('paid_advertising_goal_fields', [
            'goal_board_id' => $board->id,
            'source_key' => 'board:'.$board->id.':meta-weekly',
            'name' => 'FB的ROI-黄智诚',
        ]);
    }

    public function test_google_goal_sync_discovers_every_table_from_app_token_without_table_or_view_id(): void
    {
        Config::set('services.feishu_table.app_id', 'paid_goal_test');
        Config::set('services.feishu_table.app_secret', 'paid-goal-secret');
        [$organization, $store] = $this->storeContext('google-app');
        $board = PaidAdvertisingGoalBoard::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'name' => 'Google 团队',
            'type' => 'google_ads',
            'feishu_app_token' => 'app_google',
            'feishu_table_id' => '',
            'feishu_view_id' => '',
            'sync_status' => 'pending',
        ]);
        $phase = 1;
        $this->fakeFeishu(
            function (string $appToken, string $viewId): array {
                $this->assertSame('app_google', $appToken);
                $this->assertSame('', $viewId);

                return [[
                    'record_id' => 'rec_google',
                    'fields' => ['来源' => 'Google Ads'],
                ]];
            },
            null,
            function (string $appToken) use (&$phase): array {
                $this->assertSame('app_google', $appToken);

                return $phase === 1
                    ? [
                        ['table_id' => 'tbl_campaigns', 'name' => '广告系列'],
                        ['table_id' => 'tbl_weekly', 'name' => '每周汇总'],
                    ]
                    : [['table_id' => 'tbl_weekly', 'name' => '每周汇总']];
            },
        );

        $first = app(PaidAdvertisingGoalSyncService::class)->syncBoard($board);

        $this->assertSame(2, $first['sources']);
        $this->assertSame(2, $first['inserted']);
        $this->assertSame(2, $first['fields']);
        $this->assertDatabaseHas('paid_advertising_goal_records', [
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'goal_board_id' => $board->id,
            'source_key' => 'board:'.$board->id.':google:table:tbl_campaigns',
        ]);
        $this->assertDatabaseHas('paid_advertising_goal_records', [
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'goal_board_id' => $board->id,
            'source_key' => 'board:'.$board->id.':google:table:tbl_weekly',
        ]);

        $phase = 2;
        $second = app(PaidAdvertisingGoalSyncService::class)->syncBoard($board);

        $this->assertSame(1, $second['sources']);
        $this->assertSame(1, $second['updated']);
        $this->assertSame(1, $second['deleted']);
        $this->assertDatabaseMissing('paid_advertising_goal_records', [
            'source_key' => 'board:'.$board->id.':google:table:tbl_campaigns',
        ]);
        $this->assertDatabaseMissing('paid_advertising_goal_fields', [
            'source_key' => 'board:'.$board->id.':google:table:tbl_campaigns',
        ]);
        $this->assertDatabaseCount('paid_advertising_goal_records', 1);
        $this->assertDatabaseCount('paid_advertising_goal_fields', 1);
        $this->assertSame('completed', $board->fresh()->sync_status);
    }

    public function test_google_goal_automatically_falls_back_to_spreadsheet_and_imports_each_sheet(): void
    {
        Config::set('services.feishu_table.app_id', 'paid_goal_test');
        Config::set('services.feishu_table.app_secret', 'paid-goal-secret');
        [$organization, $store] = $this->storeContext('google-spreadsheet');
        $board = PaidAdvertisingGoalBoard::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'name' => 'Google 电子表格团队',
            'type' => 'google_ads',
            'feishu_app_token' => 'sheet_token',
            'feishu_table_id' => '',
            'feishu_view_id' => '',
            'sync_status' => 'pending',
        ]);
        $this->fakeFeishu(
            fn (string $_appToken, string $_viewId): array => throw new \RuntimeException('不应读取多维表格记录。'),
            null,
            null,
            fn (string $token): array => [
                [
                    'sheet_id' => 'daily01',
                    'title' => '广告日报',
                    'grid_properties' => ['row_count' => 4, 'column_count' => 3],
                ],
                [
                    'sheet_id' => 'target01',
                    'title' => '目标',
                    'grid_properties' => ['row_count' => 2, 'column_count' => 2],
                ],
            ],
            function (string $token, string $sheetId): array {
                $this->assertSame('sheet_token', $token);

                return $sheetId === 'daily01'
                    ? [
                        ['日期', '花费', '转化次数'],
                        ['2026-08-21', 100, 2],
                        ['', '', ''],
                        ['2026-08-22', 120, 3],
                    ]
                    : [
                        ['月份', '月目标'],
                        ['2026-08', 5000],
                    ];
            },
        );

        $result = app(PaidAdvertisingGoalSyncService::class)->syncBoard($board);

        $this->assertSame(2, $result['sources']);
        $this->assertSame(5, $result['fields']);
        $this->assertSame(3, $result['inserted']);
        $this->assertDatabaseCount('paid_advertising_goal_records', 3);
        $this->assertDatabaseCount('paid_advertising_goal_fields', 5);
        $dailyRecord = PaidAdvertisingGoalRecord::query()
            ->where('source_key', 'board:'.$board->id.':google:sheet:daily01')
            ->where('source_record_id', 'row:2')
            ->sole();
        $this->assertSame([
            '日期' => '2026-08-21',
            '花费' => 100,
            '转化次数' => 2,
        ], $dailyRecord->fields_encrypted);
        $dailyField = PaidAdvertisingGoalField::query()
            ->where('source_key', 'board:'.$board->id.':google:sheet:daily01')
            ->where('name', '花费')
            ->sole();
        $this->assertSame('飞书电子表格第 B 列', $dailyField->description);
        $this->assertSame('广告日报', $dailyField->property_encrypted['sheet_title']);
        $this->assertSame('completed', $board->fresh()->sync_status);
    }

    public function test_overall_google_goal_token_imports_every_spreadsheet_sheet_for_the_configured_store(): void
    {
        Config::set('services.feishu_table.app_id', 'paid_goal_test');
        Config::set('services.feishu_table.app_secret', 'paid-goal-secret');
        [$organization, $store] = $this->storeContext('overall-google-spreadsheet');
        [, $otherStore] = $this->storeContext('other-overall-google-spreadsheet');
        $this->overallCredentials($store, 'overall_sheet_token', '', '');
        $this->fakeFeishu(
            fn (string $_appToken, string $_viewId): array => throw new \RuntimeException('不应读取多维表格记录。'),
            null,
            fn (string $token): array => throw new \RuntimeException("{$token} 不是多维表格 Token。"),
            fn (string $token): array => [
                [
                    'sheet_id' => 'sales-target',
                    'title' => '销售目标',
                    'grid_properties' => ['row_count' => 3, 'column_count' => 3],
                ],
                [
                    'sheet_id' => 'brand-learning',
                    'title' => '品牌学习',
                    'grid_properties' => ['row_count' => 2, 'column_count' => 2],
                ],
            ],
            function (string $token, string $sheetId): array {
                $this->assertSame('overall_sheet_token', $token);

                return $sheetId === 'sales-target'
                    ? [
                        ['日期', '今日销售额($)', '本月销售额目标($)'],
                        ['2026/08/23', 18478.09, 1500000],
                        ['2026/08/24', 20000, 1500000],
                    ]
                    : [
                        ['日期', '品牌词'],
                        ['2026/08/23', 'Macfox'],
                    ];
            },
        );

        $result = app(PaidAdvertisingGoalSyncService::class)->syncConfiguredSources($store->id);

        $this->assertSame(2, $result['sources']);
        $this->assertSame(5, $result['fields']);
        $this->assertSame(3, $result['inserted']);
        $this->assertSame(0, $result['failed']);
        $this->assertDatabaseCount('paid_advertising_goal_fields', 5);
        $this->assertDatabaseCount('paid_advertising_goal_records', 3);
        $this->assertDatabaseHas('paid_advertising_goal_records', [
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'goal_board_id' => null,
            'source_key' => 'overall:google:sheet:sales-target',
            'source_record_id' => 'row:2',
        ]);
        $this->assertDatabaseHas('paid_advertising_goal_records', [
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'goal_board_id' => null,
            'source_key' => 'overall:google:sheet:brand-learning',
            'source_record_id' => 'row:2',
        ]);
        $record = PaidAdvertisingGoalRecord::query()
            ->where('source_key', 'overall:google:sheet:sales-target')
            ->where('source_record_id', 'row:2')
            ->sole();
        $this->assertSame([
            '日期' => '2026/08/23',
            '今日销售额($)' => 18478.09,
            '本月销售额目标($)' => 1500000,
        ], $record->fields_encrypted);
        $this->assertFalse(PaidAdvertisingGoalRecord::query()->forStore($otherStore)->exists());
    }

    public function test_paid_advertising_goal_sync_runs_daily_at_three_forty_in_beijing(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains((string) $event->command, 'feishu:sync-paid-advertising-goals'));

        $this->assertNotNull($event);
        $this->assertSame('40 3 * * *', $event->expression);
        $this->assertSame('Asia/Shanghai', $event->timezone);
    }

    /**
     * @param  callable(string, string): list<array<string, mixed>>  $records
     * @param  (callable(string): list<array<string, mixed>>)|null  $fields
     * @param  (callable(string): list<array<string, mixed>>)|null  $tables
     * @param  (callable(string): list<array<string, mixed>>)|null  $spreadsheetSheets
     * @param  (callable(string, string): list<array<int, mixed>>)|null  $spreadsheetValues
     */
    private function fakeFeishu(
        callable $records,
        ?callable $fields = null,
        ?callable $tables = null,
        ?callable $spreadsheetSheets = null,
        ?callable $spreadsheetValues = null,
    ): void {
        Http::fake(function (Request $request) use ($records, $fields, $tables, $spreadsheetSheets, $spreadsheetValues) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if (str_ends_with($path, '/auth/v3/tenant_access_token/internal')) {
                return Http::response(['code' => 0, 'tenant_access_token' => 'tenant-token']);
            }

            if (preg_match('#/apps/([^/]+)/tables$#', $path, $matches) === 1) {
                return Http::response([
                    'code' => 0,
                    'data' => [
                        'has_more' => false,
                        'items' => $tables
                            ? $tables($matches[1])
                            : [['table_id' => 'tbl_auto', 'name' => '自动发现表']],
                    ],
                ]);
            }

            if (preg_match('#/sheets/v3/spreadsheets/([^/]+)/sheets/query$#', $path, $matches) === 1) {
                return Http::response([
                    'code' => 0,
                    'msg' => 'success',
                    'data' => ['sheets' => $spreadsheetSheets ? $spreadsheetSheets($matches[1]) : []],
                ]);
            }

            if (preg_match('#/sheets/v2/spreadsheets/([^/]+)/values/([^/]+)$#', $path, $matches) === 1) {
                $range = rawurldecode($matches[2]);
                $sheetId = explode('!', $range, 2)[0];

                return Http::response([
                    'code' => 0,
                    'msg' => 'success',
                    'data' => [
                        'revision' => 1,
                        'spreadsheetToken' => $matches[1],
                        'valueRange' => [
                            'range' => $range,
                            'values' => $spreadsheetValues ? $spreadsheetValues($matches[1], $sheetId) : [],
                        ],
                    ],
                ]);
            }

            if (str_ends_with($path, '/fields')) {
                preg_match('#/apps/([^/]+)/tables/#', $path, $matches);
                $appToken = $matches[1] ?? '';

                return Http::response([
                    'code' => 0,
                    'data' => [
                        'has_more' => false,
                        'items' => $fields ? $fields($appToken) : [[
                            'field_id' => 'fld_source',
                            'field_name' => '来源',
                            'type' => 1,
                            'is_primary' => true,
                        ]],
                    ],
                ]);
            }

            if (str_ends_with($path, '/records')) {
                preg_match('#/apps/([^/]+)/tables/#', $path, $matches);

                return Http::response([
                    'code' => 0,
                    'data' => [
                        'has_more' => false,
                        'items' => $records($matches[1] ?? '', (string) ($request->data()['view_id'] ?? '')),
                    ],
                ]);
            }

            return Http::response(['code' => 404], 404);
        });
    }

    /** @return array{Organization, Store} */
    private function storeContext(string $suffix): array
    {
        $organization = Organization::query()->create([
            'name' => "Paid Advertising {$suffix}",
            'code' => "paid-advertising-{$suffix}",
        ]);
        $store = $organization->stores()->create([
            'name' => "Paid Advertising {$suffix}",
            'shopify_domain' => "paid-advertising-{$suffix}.myshopify.com",
            'status' => 'active',
            'timezone' => 'Asia/Shanghai',
            'currency' => 'USD',
        ]);

        return [$organization, $store];
    }

    private function overallCredentials(Store $store, string $appToken, string $_tableId, string $_viewId): void
    {
        foreach (['advertising_goals_app_token' => $appToken] as $key => $value) {
            StoreBusinessCredential::query()->create([
                'organization_id' => $store->organization_id,
                'store_id' => $store->id,
                'provider' => 'feishu_data_links',
                'credential_key' => $key,
                'credential_value' => $value,
            ]);
        }
    }

    private function metaWeeklyCredentials(Store $store, string $appToken, string $tableId): void
    {
        foreach (['advertising_meta_weekly_app_token' => $appToken, 'advertising_meta_weekly_table_id' => $tableId] as $key => $value) {
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
