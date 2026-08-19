<?php

namespace App\Console\Commands;

use App\Models\Store;
use App\Services\StoreOperationalAlertService;
use Illuminate\Console\Command;

class ScanStoreOperationalAlerts extends Command
{
    protected $signature = 'shopify:scan-alerts {--store= : 仅检查指定店铺 ID}';

    protected $description = '扫描 Shopify 连接、同步和 Webhook 异常并创建店铺告警';

    public function handle(StoreOperationalAlertService $alerts): int
    {
        $store = $this->option('store') ? Store::query()->findOrFail((int) $this->option('store')) : null;
        $result = $alerts->scan($store);
        $this->info("已检查 {$result['stores']} 个店铺，新增 {$result['created']} 条告警。");

        return self::SUCCESS;
    }
}
