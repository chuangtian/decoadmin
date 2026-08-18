<?php

namespace App\Console\Commands;

use App\Services\Shopify\Sync\ScheduledShopifySyncService;
use Illuminate\Console\Command;

class RunScheduledShopifySync extends Command
{
    protected $signature = 'shopify:sync-reconcile {--store= : 只校准指定店铺 ID}';

    protected $description = '为已连接 Shopify 店铺创建每日全量校准任务';

    public function handle(ScheduledShopifySyncService $scheduledSync): int
    {
        $storeId = $this->option('store');
        $result = $scheduledSync->dispatch($storeId !== null ? (int) $storeId : null);

        $this->info(sprintf(
            '扫描店铺 %d；创建任务 %d；跳过重复 %d；缺少权限 %d。',
            $result['stores'],
            $result['created'],
            $result['duplicate'],
            $result['missing_scope'],
        ));

        return self::SUCCESS;
    }
}
