<?php

namespace DecoMarketing\Commands;

use App\Models\Store;
use DecoMarketing\Services\Catalog;
use DecoMarketing\Services\Guard;
use DecoMarketing\Services\Shopify;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class SyncMarketing extends Command
{
    protected $signature = 'marketing:sync {--store= : Explicit database store ID} {--pages=20 : Maximum rounds per invocation}';
    protected $description = 'Persist Shopify data and durable incremental checkpoints without sending mail';

    public function handle(): int
    {
        if (! ctype_digit((string) $this->option('store')) || ! ctype_digit((string) $this->option('pages'))) {
            $this->error('Explicit store and positive page limit required.');
            return self::FAILURE;
        }
        $store = Store::findOrFail((int) $this->option('store'));
        app(Guard::class)->store($store);
        $lock = Cache::lock('marketing:sync-command:'.$store->id, 3600);
        if (! $lock->get()) {
            $this->info('Sync already running.');
            return self::SUCCESS;
        }
        $settings = app(Catalog::class)->settings($store);
        try {
            $pending = ['contacts', 'checkouts'];
            $sharedPending = ['customers', 'orders'];
            $limit = max(1, min(100, (int) $this->option('pages')));
            for ($round = 0; $round < $limit && ($pending || $sharedPending); $round++) {
                $shared = $sharedPending ? app(\DecoMarketing\Services\SharedData::class)->sync($store, 500, $sharedPending) : [];
                $sharedPending = array_keys(array_filter($shared, fn ($value) => $value['pending']));
                $counts = $pending ? app(Shopify::class)->sync($store, $pending) : [];
                $counts['shared'] = $shared;
                $state = $settings->fresh()->sync_state ?? [];
                $pending = array_values(array_filter($pending, fn ($kind) => ! empty($state[$kind]['cursor'])));
                $state['_sync_run'] = ['checked_at' => now()->toIso8601String(), 'status' => ($pending || $sharedPending) ? 'syncing' : 'caught_up', 'pending' => $pending, 'last_counts' => $counts];
                $settings->update(['sync_state' => $state]);
                $this->line(json_encode(['store_id' => $store->id, 'counts' => $counts, 'pending' => $pending]));
            }
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $state = $settings->fresh()->sync_state ?? [];
            $state['_sync_run'] = ['checked_at' => now()->toIso8601String(), 'status' => 'retry_required', 'error_type' => class_basename($e)];
            $settings->update(['sync_state' => $state]);
            $this->error('Sync paused; persisted checkpoints retained. '.class_basename($e));
            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
