<?php

namespace App\Console\Commands;

use App\Services\Shopify\Sync\ScheduledShopifySyncService;
use Illuminate\Console\Command;

class RunScheduledShopifySync extends Command
{
    protected $signature = 'shopify:sync-reconcile
        {--store= : 只处理指定店铺 ID}
        {--mode=incremental : 同步模式：incremental、full 或 reconcile}';

    protected $description = '为已连接 Shopify 店铺创建定时增量、全量或一致性校准任务';

    public function handle(ScheduledShopifySyncService $scheduledSync): int
    {
        $storeId = $this->option('store');
        $mode = (string) $this->option('mode');

        if (! in_array($mode, ['incremental', 'full', 'reconcile'], true)) {
            $this->error('同步模式必须是 incremental、full 或 reconcile。');

            return self::INVALID;
        }

        $result = $scheduledSync->dispatch($storeId !== null ? (int) $storeId : null, $mode);

        $this->info(sprintf(
            '模式 %s；扫描店铺 %d；创建任务 %d；跳过未到期或重复 %d；缺少权限 %d。',
            $mode,
            $result['stores'],
            $result['created'],
            $result['duplicate'],
            $result['missing_scope'],
        ));

        return self::SUCCESS;
    }
}
