<?php

namespace App\Jobs;

use App\Models\SyncJob;
use App\Services\Shopify\Sync\SyncProcessor;
use App\Services\Sync\SyncJobService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $syncJobId)
    {
        $this->onQueue('shopify-sync');
    }

    public function handle(SyncProcessor $processor): void
    {
        $processor->process($this->syncJobId);
    }

    public function tries(): int
    {
        return min(5, max(1, (int) config('shopify.scheduled_sync.max_attempts', 3)));
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900, 1800, 3600];
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("shopify-sync-job:{$this->syncJobId}"))
                ->releaseAfter(60)
                ->expireAfter(1800)
                ->shared(),
        ];
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(3);
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
