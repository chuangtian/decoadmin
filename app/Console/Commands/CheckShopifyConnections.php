<?php

namespace App\Console\Commands;

use App\Models\ShopifyConnection;
use App\Services\Shopify\ShopifyConnectionHealthService;
use Illuminate\Console\Command;

class CheckShopifyConnections extends Command
{
    protected $signature = 'shopify:check-connections';

    protected $description = '检查有效的 Shopify 连接并更新健康状态';

    public function handle(ShopifyConnectionHealthService $health): int
    {
        $checked = 0;
        $healthy = 0;
        $unhealthy = 0;

        ShopifyConnection::query()
            ->whereNull('uninstalled_at')
            ->where('status', '!=', 'disconnected')
            ->orderBy('id')
            ->chunkById(100, function ($connections) use ($health, &$checked, &$healthy, &$unhealthy): void {
                foreach ($connections as $connection) {
                    $result = $health->check($connection);
                    $checked++;
                    $result['success'] ? $healthy++ : $unhealthy++;
                }
            });

        $this->components->info("已检查 {$checked} 个连接：{$healthy} 个正常，{$unhealthy} 个需要处理。");

        return self::SUCCESS;
    }
}
