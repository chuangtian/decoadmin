<?php

namespace App\Console\Commands;

use App\Models\ShopifyConnection;
use App\Services\Shopify\ShopifyConnectionHealthService;
use Illuminate\Console\Command;

class CheckShopifyConnections extends Command
{
    protected $signature = 'shopify:check-connections';

    protected $description = 'Check active Shopify connections and update their health status';

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

        $this->components->info("Checked {$checked} connection(s): {$healthy} connected, {$unhealthy} require attention.");

        return self::SUCCESS;
    }
}
