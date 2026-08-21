<?php

namespace App\Console\Commands;

use App\Services\Feishu\AmazonDailySalesSyncService;
use Illuminate\Console\Command;

class SyncFeishuAmazonDailySales extends Command
{
    protected $signature = 'feishu:sync-amazon-daily-sales {--store= : 仅同步指定店铺 ID}';

    protected $description = '从每个店铺配置的飞书多维表格同步亚马逊日销售与广告数据';

    public function handle(AmazonDailySalesSyncService $sync): int
    {
        $storeOption = $this->option('store');
        $storeId = filled($storeOption) ? filter_var($storeOption, FILTER_VALIDATE_INT) : null;

        if (filled($storeOption) && ($storeId === false || $storeId < 1)) {
            $this->components->error('店铺 ID 必须是正整数。');

            return self::INVALID;
        }

        $result = $sync->syncConfiguredStores($storeId ?: null);
        $this->components->info(sprintf(
            '飞书亚马逊数据同步完成：店铺 %d，新增 %d，更新 %d，跳过 %d，失败 %d。',
            $result['stores'],
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
