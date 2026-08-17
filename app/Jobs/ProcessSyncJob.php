<?php

namespace App\Jobs;

use App\Models\SyncJob;
use App\Services\Sync\SyncJobService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public function __construct(public readonly int $syncJobId)
    {
        $this->onQueue('shopify-sync');
    }

    public function handle(SyncJobService $syncJobs): void
    {
        $syncJob = $syncJobs->markRunning($this->syncJobId);

        if (! $syncJob) {
            return;
        }

        try {
            // Phase one intentionally provides execution lifecycle only.
            // Shopify products, orders, customers and inventory are not queried here.
            $syncJobs->markCompleted($syncJob);
        } catch (Throwable $exception) {
            $syncJobs->markFailed($syncJob, $exception->getMessage());

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $syncJob = SyncJob::query()->find($this->syncJobId);

        if ($syncJob && $syncJob->status !== 'completed') {
            app(SyncJobService::class)->markFailed(
                $syncJob,
                $exception?->getMessage() ?? '同步队列任务执行失败。',
            );
        }
    }
}
