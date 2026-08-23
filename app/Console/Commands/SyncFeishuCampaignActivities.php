<?php

namespace App\Console\Commands;

use App\Services\Feishu\CampaignActivitySyncService;
use Illuminate\Console\Command;

class SyncFeishuCampaignActivities extends Command
{
    protected $signature = 'feishu:sync-campaign-activities {--store= : 仅同步指定店铺 ID}';

    protected $description = '同步每个店铺 App Token 下的全部飞书数据表及活动主题数据';

    public function handle(CampaignActivitySyncService $sync): int
    {
        $storeOption = $this->option('store');
        $storeId = filled($storeOption) ? filter_var($storeOption, FILTER_VALIDATE_INT) : null;

        if (filled($storeOption) && ($storeId === false || $storeId < 1)) {
            $this->components->error('店铺 ID 必须是正整数。');

            return self::INVALID;
        }

        $result = $sync->syncConfiguredStores($storeId ?: null);
        $this->components->info(sprintf(
            '飞书全表及活动主题数据同步完成：店铺 %d，归档表 %d，字段 %d，记录 %d；活动新增 %d，更新 %d，跳过 %d，失败 %d。',
            $result['stores'],
            $result['archived_tables'],
            $result['archived_fields'],
            $result['archived_records'],
            $result['inserted'],
            $result['updated'],
            $result['skipped'],
            $result['failed'],
        ));

        foreach ($result['failures'] as $failure) {
            $this->components->warn(sprintf(
                '组织 %d / 店铺 %d：%s',
                $failure['organization_id'],
                $failure['store_id'],
                $failure['error'],
            ));
        }

        return $result['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
