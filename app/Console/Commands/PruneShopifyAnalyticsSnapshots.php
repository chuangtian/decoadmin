<?php

namespace App\Console\Commands;

use App\Services\Shopify\Analytics\AnalyticsSnapshotMaintenanceService;
use Illuminate\Console\Command;

class PruneShopifyAnalyticsSnapshots extends Command
{
    protected $signature = 'shopify:prune-analytics-snapshots';

    protected $description = 'Delete expired Shopify analytics snapshots beyond the configured retention period';

    public function handle(AnalyticsSnapshotMaintenanceService $maintenance): int
    {
        $result = $maintenance->prune();

        $this->info(sprintf(
            'Deleted %d analytics snapshots older than %d days.',
            $result['deleted'],
            $result['retention_days'],
        ));

        return self::SUCCESS;
    }
}
