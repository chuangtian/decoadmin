<?php

namespace App\Console\Commands;

use App\Models\ShopifyProductMonitor;
use App\Models\Store;
use App\Services\Shopify\Products\ShopifyProductMonitorService;
use Illuminate\Console\Command;

class MonitorShopifyProducts extends Command
{
    protected $signature = 'shopify:monitor-products {--store= : 仅检查指定店铺 ID}';

    protected $description = '只读检查手动标记为重点的产品库存和状态';

    public function handle(ShopifyProductMonitorService $service): int
    {
        $failed = 0;
        Store::where('status', 'active')->whereHas('organization', fn ($q) => $q->where('status', 'active'))
            ->whereIn('id', ShopifyProductMonitor::where('is_enabled', true)->select('store_id'))
            ->when($this->option('store'), fn ($q) => $q->whereKey((int) $this->option('store')))
            ->eachById(function ($store) use ($service, &$failed) {
                $result = $service->scanStore($store);
                $failed += $result['failed'];
                $this->info("店铺 {$store->id}：检查 {$result['checked']}，告警 {$result['alerts']}，失败 {$result['failed']}。");
            }, 25);

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
