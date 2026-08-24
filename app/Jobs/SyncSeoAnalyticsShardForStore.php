<?php

namespace App\Jobs;

use App\Models\SeoAnalyticsSyncRun;
use App\Models\Store;
use App\Services\SeoAnalytics\SeoAnalyticsSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

class SyncSeoAnalyticsShardForStore implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;

    public int $tries = 3;

    public int $uniqueFor = 10800;

    public function __construct(
        public readonly int $organizationId,
        public readonly int $storeId,
        public readonly int $syncRunId,
        public readonly int $shardIndex,
        public readonly int $totalShards,
        public readonly string $phase,
        public readonly string $dateFrom,
        public readonly string $dateTo,
    ) {
        $this->onQueue('analytics-sync');
    }

    public function handle(SeoAnalyticsSyncService $sync): void
    {
        $store = Store::query()->where('organization_id', $this->organizationId)->whereKey($this->storeId)->where('status', 'active')->first();
        $run = SeoAnalyticsSyncRun::query()->forOrganization($this->organizationId)->forStore($this->storeId)->find($this->syncRunId);
        if (! $store || ! $run || in_array($run->status, ['completed', 'failed'], true)) {
            return;
        }

        $completed = array_map('intval', data_get($run->result, 'completed_shards', []));
        if (in_array($this->shardIndex, $completed, true)) {
            return;
        }

        $run->forceFill([
            'status' => 'running',
            'started_at' => $run->started_at ?: now(),
            'last_error' => null,
        ])->save();

        try {
            $processed = $sync->syncRange($store, $this->dateFrom, $this->dateTo);
            DB::transaction(function () use ($processed): void {
                $run = SeoAnalyticsSyncRun::query()->forOrganization($this->organizationId)->forStore($this->storeId)
                    ->lockForUpdate()->findOrFail($this->syncRunId);
                $result = is_array($run->result) ? $run->result : [];
                $completed = array_values(array_unique([
                    ...array_map('intval', $result['completed_shards'] ?? []),
                    $this->shardIndex,
                ]));
                sort($completed);
                $result['total_shards'] = $this->totalShards;
                $result['completed_shards'] = $completed;
                $result['priority_ready'] = (bool) ($result['priority_ready'] ?? false) || $this->phase === 'priority';
                $result['last_completed_shard'] = [
                    'phase' => $this->phase,
                    'date_from' => $this->dateFrom,
                    'date_to' => $this->dateTo,
                    'processed_rows' => $processed,
                    'completed_at' => now()->toIso8601String(),
                ];
                $finished = count($completed) >= $this->totalShards;
                $run->forceFill([
                    'status' => $finished ? 'completed' : 'running',
                    'progress_percent' => $finished ? 100 : max(1, (int) floor(count($completed) / $this->totalShards * 100)),
                    'processed_rows' => $run->processed_rows + $processed,
                    'result' => $result,
                    'completed_at' => $finished ? now() : null,
                ])->save();
            });
        } catch (Throwable $exception) {
            SeoAnalyticsSyncRun::query()->forOrganization($this->organizationId)->forStore($this->storeId)->whereKey($this->syncRunId)
                ->update(['last_error' => mb_substr($exception->getMessage(), 0, 2000)]);
            throw $exception;
        }
    }

    public function uniqueId(): string
    {
        return "{$this->organizationId}:{$this->storeId}:{$this->syncRunId}:{$this->shardIndex}";
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("seo-analytics-shard:{$this->organizationId}:{$this->storeId}"))->releaseAfter(30)->expireAfter(3720)->shared()];
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [120, 600];
    }

    public function failed(?Throwable $exception): void
    {
        SeoAnalyticsSyncRun::query()->forOrganization($this->organizationId)->forStore($this->storeId)->whereKey($this->syncRunId)
            ->whereNotIn('status', ['completed', 'failed'])->update([
                'status' => 'failed',
                'last_error' => mb_substr($exception?->getMessage() ?? '同步分片失败。', 0, 2000),
                'failed_at' => now(),
            ]);
    }
}
