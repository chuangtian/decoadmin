<?php

namespace App\Services\Shopify\Sync;

use App\Services\AnalyticsCacheVersionService;
use App\Services\Sync\SyncJobService;
use Throwable;

class SyncProcessor
{
    public function __construct(
        private SyncHandlerRegistry $handlers,
        private SyncJobService $syncJobs,
        private ?SyncDataConsistencyService $consistency = null,
        private ?SyncErrorClassifier $errors = null,
        private ?AnalyticsCacheVersionService $analyticsCache = null,
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
                $result = $this->consistency?->inspect($syncJob, $result) ?? $result;
                $this->syncJobs->markCompleted($syncJob, $result);
                $this->analyticsCache?->bump((int) $syncJob->store_id);
            } else {
                $this->syncJobs->markFailed(
                    $syncJob,
                    $result->message,
                    $result,
                    $this->errors?->classify($result),
                );
            }

            return $result;
        } catch (Throwable $exception) {
            $this->syncJobs->markFailed(
                $syncJob,
                $exception->getMessage(),
                errorCode: $this->errors?->classify($exception),
            );

            throw $exception;
        }
    }
}
