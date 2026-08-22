<?php

namespace App\Console\Commands;

use App\Services\Feishu\CampaignPlanningDocumentSyncService;
use Illuminate\Console\Command;

class SyncFeishuCampaignPlanningDocuments extends Command
{
    protected $signature = 'feishu:sync-campaign-planning-documents {--store= : 仅同步指定店铺 ID}';

    protected $description = '手动将活动策划书及文档资源同步到项目服务器（不加入定时任务）';

    public function handle(CampaignPlanningDocumentSyncService $sync): int
    {
        $storeOption = $this->option('store');
        $storeId = filled($storeOption) ? filter_var($storeOption, FILTER_VALIDATE_INT) : null;

        if (filled($storeOption) && ($storeId === false || $storeId < 1)) {
            $this->components->error('店铺 ID 必须是正整数。');

            return self::INVALID;
        }

        $result = $sync->syncConfiguredStores($storeId ?: null);
        $this->components->info(sprintf(
            '飞书策划书同步完成：店铺 %d，文档 %d，新增 %d，更新 %d，无变化 %d，失败 %d。',
            $result['stores'],
            $result['documents'],
            $result['inserted'],
            $result['updated'],
            $result['unchanged'],
            $result['failed'],
        ));

        foreach ($result['failures'] as $failure) {
            $this->components->warn(sprintf(
                '组织 %d / 店铺 %d / 活动 %d：%s',
                $failure['organization_id'],
                $failure['store_id'],
                $failure['campaign_activity_id'],
                $failure['error'],
            ));
        }

        return $result['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
