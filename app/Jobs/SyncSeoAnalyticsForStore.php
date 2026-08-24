<?php

namespace App\Jobs;

use App\Models\SeoAnalyticsSyncRun;
use App\Models\Store;
use App\Services\SeoAnalytics\SeoAnalyticsSyncManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SyncSeoAnalyticsForStore implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;

    public int $tries = 3;

    public int $uniqueFor = 3900;

    public function __construct(public readonly int $organizationId, public readonly int $storeId, public readonly int $syncRunId)
    {
        $this->onQueue('analytics-sync');
    }

    public function handle(SeoAnalyticsSyncManager $manager): void
    {
        $store = Store::query()->where('organization_id', $this->organizationId)->whereKey($this->storeId)->where('status', 'active')->first();
        $run = SeoAnalyticsSyncRun::query()->forOrganization($this->organizationId)->forStore($this->storeId)->find($this->syncRunId);
        if ($store && $run && in_array($run->status, ['queued', 'running'], true)) {
            $manager->dispatchShards($store, $run);
        }
    }

    public function uniqueId(): string
    {
        return "{$this->organizationId}:{$this->storeId}";
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("seo-analytics:{$this->organizationId}:{$this->storeId}"))->releaseAfter(90)->expireAfter(3720)->shared()];
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
                'status' => 'failed', 'last_error' => mb_substr($exception?->getMessage() ?? '同步任务失败。', 0, 2000), 'failed_at' => now(),
            ]);
    }
}
