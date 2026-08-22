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
            'name' => 'Google 团队',
            'type' => 'google_ads',
            'feishu_app_token' => 'app_board',
            'feishu_table_id' => 'tbl_board',
            'feishu_view_id' => 'vew_board',
            'sync_status' => 'pending',
        ]);
        $phase = 1;
        $this->fakeFeishu(
            function (string $appToken, string $viewId) use (&$phase): array {
                if ($appToken === 'app_overall') {
                    $this->assertSame('vew_overall', $viewId);

                    return [[
                        'record_id' => 'rec_overall',
                        'created_time' => '1785542400000',
                        'last_modified_time' => '1785546000000',
                        'fields' => ['月份' => '2026-08', '总目标' => $phase === 1 ? 100000 : 120000],
                    ]];
                }

                $this->assertSame('app_board', $appToken);
                $this->assertSame('vew_board', $viewId);

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
        $this->fakeFeishu(fn (string $appToken, string $_viewId): array => [[
            'record_id' => 'shared_record',
            'fields' => ['来源' => $appToken],
        ]]);

        $result = app(PaidAdvertisingGoalSyncService::class)->syncConfiguredSources();

        $this->assertSame(1, $result['sources']);
        $this->assertSame(1, $result['inserted']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame(1, $result['fields']);
        $record = PaidAdvertisingGoalRecord::query()->sole();
        $this->assertSame($firstOrganization->id, $record->organization_id);
        $this->assertSame($firstStore->id, $record->store_id);
        $this->assertSame(['来源' => 'app_first'], $record->fields_encrypted);
        $this->assertFalse(
            PaidAdvertisingGoalRecord::query()
                ->forOrganization($secondOrganization)
                ->forStore($secondStore)
                ->exists(),
        );
        $this->assertSame($firstStore->id, PaidAdvertisingGoalField::query()->sole()->store_id);
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
     */
    private function fakeFeishu(callable $records, ?callable $fields = null): void
    {
        Http::fake(function (Request $request) use ($records, $fields) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if (str_ends_with($path, '/auth/v3/tenant_access_token/internal')) {
                return Http::response(['code' => 0, 'tenant_access_token' => 'tenant-token']);
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
                        'items' => $records($matches[1] ?? '', (string) $request['view_id']),
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

    private function overallCredentials(Store $store, string $appToken, string $tableId, string $viewId): void
    {
        foreach (['advertising_goals_app_token' => $appToken, 'advertising_goals_table_id' => $tableId, 'advertising_goals_view_id' => $viewId] as $key => $value) {
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
