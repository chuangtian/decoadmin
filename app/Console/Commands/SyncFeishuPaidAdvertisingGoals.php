<?php

namespace App\Console\Commands;

use App\Services\Feishu\PaidAdvertisingGoalSyncService;
use Illuminate\Console\Command;

class SyncFeishuPaidAdvertisingGoals extends Command
{
    protected $signature = 'feishu:sync-paid-advertising-goals {--store= : 仅同步指定店铺 ID}';

    protected $description = '每日同步已配置的飞书广告目标数据并归档 App Token 下的全部多维表格';

    public function handle(PaidAdvertisingGoalSyncService $sync): int
    {
        $storeOption = $this->option('store');
        $storeId = filled($storeOption) ? filter_var($storeOption, FILTER_VALIDATE_INT) : null;

        if (filled($storeOption) && ($storeId === false || $storeId < 1)) {
            $this->components->error('店铺 ID 必须是正整数。');

            return self::INVALID;
        }

        $result = $sync->syncConfiguredSources($storeId ?: null);
        $this->components->info(sprintf(
            '飞书广告目标同步完成：数据源 %d，业务字段 %d，新增 %d，更新 %d，删除 %d，跳过 %d；归档 %d 张表、%d 个字段、%d 条记录；失败 %d。',
            $result['sources'],
            $result['fields'],
            $result['inserted'],
            $result['updated'],
            $result['deleted'],
            $result['skipped'],
            $result['archived_tables'],
            $result['archived_fields'],
            $result['archived_records'],
            $result['failed'],
        ));

        foreach ($result['failures'] as $failure) {
            $this->components->warn(sprintf(
                '组织 %d / 店铺 %d / 页签 %s：%s',
                $failure['organization_id'],
                $failure['store_id'],
                $failure['board_id'] === null ? '总目标' : (string) $failure['board_id'],
                $failure['error'],
            ));
        }

        return $result['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
