<?php

namespace App\Services\Shopify\Sync;

use App\Services\Sync\SyncJobService;
use Throwable;

class SyncProcessor
{
    public function __construct(
        private SyncHandlerRegistry $handlers,
        private SyncJobService $syncJobs,
    ) {}

    public function process(int $syncJobId): SyncResult
    {
        $syncJob = $this->syncJobs->markRunning($syncJobId);

        if (! $syncJob) {
            return SyncResult::failed('同步任务不存在或当前状态不可执行。', [
                ['code' => 'sync_job_not_runnable', 'message' => '同步任务不存在或当前状态不可执行。'],
            ]);
        }

        $handler = $this->handlers->forType($syncJob->type);

        if (! $handler) {
            $result = SyncResult::unsupported($syncJob->type);
            $this->syncJobs->markFailed($syncJob, $result->message, $result);

            return $result;
        }

        try {
            $result = $handler->handle($syncJob);

            if ($result->success) {
                $this->syncJobs->markCompleted($syncJob, $result);
            } else {
                $this->syncJobs->markFailed($syncJob, $result->message, $result);
            }

            return $result;
        } catch (Throwable $exception) {
            $this->syncJobs->markFailed($syncJob, $exception->getMessage());

            throw $exception;
        }
    }
}
