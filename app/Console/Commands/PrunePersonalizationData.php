<?php

namespace App\Console\Commands;

use App\Services\Personalization\PersonalizationDataLifecycleService;
use Illuminate\Console\Command;

class PrunePersonalizationData extends Command
{
    protected $signature = 'personalization:prune-data {--due-only : Only purge stores whose uninstall or shop-redact deadline is due}';

    protected $description = 'Apply Personalization raw, aggregate, audit, uninstall, and privacy retention policies';

    public function handle(PersonalizationDataLifecycleService $lifecycle): int
    {
        $due = $lifecycle->purgeDueStores();
        if ($this->option('due-only')) {
            $this->info("Purged {$due['stores_purged']} due Personalization stores.");

            return self::SUCCESS;
        }

        $retention = $lifecycle->pruneRetention();
        $this->info(sprintf(
            'Purged stores: %d; rollups: %d; events: %d; attributions: %d; aggregates: %d; audits: %d.',
            $due['stores_purged'],
            $retention['rollups'],
            $retention['events_deleted'],
            $retention['attributions_deleted'],
            $retention['aggregates_deleted'],
            $retention['audits_deleted'],
        ));

        return self::SUCCESS;
    }
}
