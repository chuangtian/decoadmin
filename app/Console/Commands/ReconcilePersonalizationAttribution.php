<?php

namespace App\Console\Commands;

use App\Exceptions\PersonalizationException;
use App\Models\PersonalizationEventSource;
use App\Services\Personalization\PersonalizationAttributionService;
use Illuminate\Console\Command;

class ReconcilePersonalizationAttribution extends Command
{
    protected $signature = 'personalization:reconcile-attribution {--store= : Restrict to one DecoAdmin store ID} {--limit=2000 : Maximum checkout events per store}';

    protected $description = 'Reconcile 7-day last recommendation click attribution with Commerce Hub orders';

    public function handle(PersonalizationAttributionService $attribution): int
    {
        $storeOption = $this->option('store');
        if ($storeOption !== null && filter_var($storeOption, FILTER_VALIDATE_INT) === false) {
            $this->error('The --store option must be a numeric DecoAdmin store ID.');

            return self::INVALID;
        }
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 10_000) {
            $this->error('The --limit option must be between 1 and 10000.');

            return self::INVALID;
        }

        $sources = PersonalizationEventSource::query()
            ->where('status', 'active')
            ->when($storeOption !== null, fn ($query) => $query->where('store_id', (int) $storeOption))
            ->with(['store.organization'])
            ->orderBy('store_id')
            ->get();
        if ($storeOption !== null && $sources->isEmpty()) {
            $this->error('No active Personalization event source exists for that store.');

            return self::FAILURE;
        }

        $totals = ['stores' => 0, 'examined' => 0, 'attributed' => 0, 'updated' => 0, 'skipped' => 0];
        foreach ($sources as $source) {
            $store = $source->store;
            if (! $store || ! $store->organization || $store->status !== 'active' || $store->organization->status !== 'active') {
                continue;
            }
            try {
                $result = $attribution->reconcileStore($store, (int) $limit);
            } catch (PersonalizationException $exception) {
                $this->warn("Store {$source->store_id} skipped: {$exception->errorCode}");
                if ($storeOption !== null) {
                    return self::FAILURE;
                }

                continue;
            }
            $totals['stores']++;
            foreach (['examined', 'attributed', 'updated', 'skipped'] as $key) {
                $totals[$key] += $result[$key];
            }
        }

        $this->info(sprintf(
            'Stores: %d; examined: %d; attributed: %d; updated: %d; skipped: %d.',
            $totals['stores'],
            $totals['examined'],
            $totals['attributed'],
            $totals['updated'],
            $totals['skipped'],
        ));

        return self::SUCCESS;
    }
}
