<?php

namespace App\Console\Commands;

use App\Models\Store;
use App\Services\Discounts\ShopifyDiscountMonitorService;
use Illuminate\Console\Command;

class MonitorShopifyDiscounts extends Command
{
    protected $signature = 'shopify:monitor-discounts {--store= : 仅检查指定店铺 ID}';

    protected $description = '按 Shopify 折扣 ID 检查重点折扣变更和到期时间';

    public function handle(ShopifyDiscountMonitorService $monitors): int
    {
        $query = Store::query()
            ->where('status', 'active')
            ->whereHas('shopifyDiscountMonitors', fn ($query) => $query->where('is_enabled', true))
            ->when($this->option('store'), fn ($query) => $query->whereKey((int) $this->option('store')))
            ->orderBy('id');
        $totals = ['stores' => 0, 'checked' => 0, 'snapshots' => 0, 'alerts' => 0, 'failed' => 0];
        $query->eachById(function (Store $store) use ($monitors, &$totals): void {
            $result = $monitors->scanStore($store);
            $totals['stores']++;
            foreach (['checked', 'snapshots', 'alerts', 'failed'] as $key) {
                $totals[$key] += $result[$key];
            }
        }, 50);
        $this->info("已检查 {$totals['stores']} 个店铺、{$totals['checked']} 个重点折扣；新增 {$totals['snapshots']} 份快照、{$totals['alerts']} 条告警，失败 {$totals['failed']} 个。");

        return $totals['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
