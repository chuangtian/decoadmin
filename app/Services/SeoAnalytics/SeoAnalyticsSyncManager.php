<?php

namespace App\Services\SeoAnalytics;

use App\Jobs\SyncSeoAnalyticsForStore;
use App\Jobs\SyncSeoAnalyticsShardForStore;
use App\Models\SeoAnalyticsSyncRun;
use App\Models\SeoGscDailyMetric;
use App\Models\Store;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

class SeoAnalyticsSyncManager
{
    public function __construct(private SeoAnalyticsConfigurationService $configuration) {}

    public function queue(Store $store, string $source = 'manual', ?User $actor = null, string $mode = 'incremental'): SeoAnalyticsSyncRun
    {
        $active = SeoAnalyticsSyncRun::query()->forOrganization($store->organization_id)->forStore($store->id)
            ->whereIn('status', ['queued', 'running'])->latest('id')->first();
        if ($active) {
            return $active;
        }

        $status = $this->configuration->status($store);
        abort_unless($status['configured'], 422, 'GA4 / GSC 数据源配置不完整。');

        $timezone = $store->timezone ?: (string) config('services.google_search_console.sync_timezone', 'America/Los_Angeles');
        $to = CarbonImmutable::now($timezone)->subDays(max(1, (int) config('services.google_search_console.data_delay_days', 2)))->startOfDay();
        $latest = SeoGscDailyMetric::query()->forOrganization($store->organization_id)->forStore($store->id)
            ->where('segment', 'total')->max('metric_date');
        if ($mode === 'backfill' || ! $latest) {
            $from = $to->subMonthsNoOverflow(max(1, (int) config('services.google_search_console.backfill_months', 16)))->startOfMonth();
            $mode = 'backfill';
        } else {
            $from = CarbonImmutable::parse((string) $latest, $timezone)
                ->subDays(max(1, (int) config('services.google_search_console.overlap_days', 7)));
        }

        $run = SeoAnalyticsSyncRun::query()->create([
            'uuid' => (string) Str::uuid(), 'organization_id' => $store->organization_id, 'store_id' => $store->id,
            'requested_by' => $actor?->id, 'source' => $source, 'mode' => $mode, 'status' => 'queued',
            'date_from' => $from->toDateString(), 'date_to' => $to->toDateString(), 'progress_percent' => 0,
        ]);
        SyncSeoAnalyticsForStore::dispatch((int) $store->organization_id, (int) $store->id, (int) $run->id);

        return $run;
    }

    public function dispatchShards(Store $store, SeoAnalyticsSyncRun $run): void
    {
        $from = CarbonImmutable::parse((string) $run->date_from)->startOfDay();
        $to = CarbonImmutable::parse((string) $run->date_to)->startOfDay();
        $priorityFrom = $to->subDays(6)->max($from);
        $shards = [[
            'index' => 0,
            'phase' => 'priority',
            'date_from' => $priorityFrom->toDateString(),
            'date_to' => $to->toDateString(),
        ]];

        if ($from->lt($priorityFrom)) {
            $historyTo = $priorityFrom->subDay();
            $history = [];
            for ($cursor = $from; $cursor->lte($historyTo); $cursor = $cursor->addMonthNoOverflow()->startOfMonth()) {
                $history[] = [
                    'phase' => 'backfill',
                    'date_from' => $cursor->toDateString(),
                    'date_to' => $cursor->endOfMonth()->min($historyTo)->toDateString(),
                ];
            }
            foreach (array_reverse($history) as $shard) {
                $shards[] = ['index' => count($shards), ...$shard];
            }
        }

        $result = is_array($run->result) ? $run->result : [];
        $result = [
            ...$result,
            'total_shards' => count($shards),
            'completed_shards' => array_values(array_unique(array_map('intval', $result['completed_shards'] ?? []))),
            'priority_ready' => (bool) ($result['priority_ready'] ?? false),
            'priority_period' => [$priorityFrom->toDateString(), $to->toDateString()],
        ];
        $run->forceFill(['status' => 'queued', 'result' => $result, 'last_error' => null])->save();

        foreach ($shards as $shard) {
            SyncSeoAnalyticsShardForStore::dispatch(
                (int) $store->organization_id,
                (int) $store->id,
                (int) $run->id,
                (int) $shard['index'],
                count($shards),
                (string) $shard['phase'],
                (string) $shard['date_from'],
                (string) $shard['date_to'],
            );
        }
    }
}
