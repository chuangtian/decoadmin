<?php

namespace App\Services\Sync;

use App\Jobs\ProcessSyncJob;
use App\Models\AppInstallation;
use App\Models\Store;
use App\Models\SyncJob;
use App\Models\User;
use App\Services\Shopify\Sync\SyncResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SyncJobService
{
    public function createAndDispatch(
        Store $store,
        string $type,
        User $actor,
        ?AppInstallation $installation = null,
    ): SyncJob {
        $syncJob = DB::transaction(function () use ($store, $type, $actor, $installation): SyncJob {
            $syncJob = SyncJob::query()->create([
                'uuid' => (string) Str::uuid(),
                'organization_id' => $store->organization_id,
                'store_id' => $store->getKey(),
                'app_id' => $installation?->app_id,
                'app_installation_id' => $installation?->getKey(),
                'type' => $type,
                'direction' => 'pull',
                'status' => 'pending',
                'payload' => [
                    'source' => 'manual',
                    'requested_by' => $actor->getKey(),
                    'framework_only' => $type !== 'products',
                ],
                'logs' => [],
                'available_at' => now(),
            ]);

            return $this->markQueued($syncJob);
        });

        ProcessSyncJob::dispatch($syncJob->getKey())->afterCommit();

        return $syncJob;
    }

    public function markQueued(SyncJob $syncJob): SyncJob
    {
        $syncJob->forceFill([
            'status' => 'queued',
            'available_at' => now(),
            'logs' => $this->appendLog($syncJob, 'info', '同步任务已加入 shopify-sync 队列。'),
        ])->save();

        return $syncJob;
    }

    public function markRunning(int $syncJobId): ?SyncJob
    {
        return DB::transaction(function () use ($syncJobId): ?SyncJob {
            $syncJob = SyncJob::query()->lockForUpdate()->find($syncJobId);

            if (
                ! $syncJob
                || ! in_array($syncJob->status, ['pending', 'queued', 'failed'], true)
                || $syncJob->attempts >= $syncJob->max_attempts
            ) {
                return null;
            }

            $syncJob->forceFill([
                'status' => 'running',
                'started_at' => now(),
                'finished_at' => null,
                'completed_at' => null,
                'failed_at' => null,
                'last_error' => null,
                'attempts' => $syncJob->attempts + 1,
                'logs' => $this->appendLog($syncJob, 'info', '同步执行框架已启动。'),
            ])->save();

            return $syncJob;
        });
    }

    /** @param array<string, mixed>|SyncResult $result */
    public function markCompleted(SyncJob $syncJob, array|SyncResult $result = []): SyncJob
    {
        $finishedAt = now();
        $resultPayload = $result instanceof SyncResult
            ? $result->toArray()
            : [
                'success' => true,
                'status' => 'success',
                'message' => '同步任务框架执行完成，本阶段未调用 Shopify API。',
                'records_count' => 0,
                'errors' => [],
                'metadata' => ['framework_only' => true],
                ...$result,
            ];

        $syncJob->forceFill([
            'status' => 'completed',
            'result' => $resultPayload,
            'total_items' => max($syncJob->total_items, (int) ($resultPayload['records_count'] ?? 0)),
            'processed_items' => max($syncJob->processed_items, (int) ($resultPayload['records_count'] ?? 0)),
            'finished_at' => $finishedAt,
            'completed_at' => $finishedAt,
            'failed_at' => null,
            'last_error' => null,
            'logs' => $this->appendLog($syncJob, 'success', '同步执行框架已完成。'),
        ])->save();

        return $syncJob;
    }

    public function markFailed(SyncJob $syncJob, string $error, ?SyncResult $result = null): SyncJob
    {
        $finishedAt = now();
        $safeError = $this->safeError($error);
        $syncJob->forceFill([
            'status' => 'failed',
            'finished_at' => $finishedAt,
            'failed_at' => $finishedAt,
            'last_error' => $safeError,
            'result' => $result?->toArray() ?? $syncJob->result,
            'failed_items' => $result ? max($syncJob->failed_items, count($result->errors)) : $syncJob->failed_items,
            'logs' => $this->appendLog($syncJob, 'error', $safeError),
        ])->save();

        return $syncJob;
    }

    public function markCancelled(SyncJob $syncJob): SyncJob
    {
        $syncJob->forceFill([
            'status' => 'cancelled',
            'finished_at' => now(),
            'logs' => $this->appendLog($syncJob, 'warning', '同步任务已取消。'),
        ])->save();

        return $syncJob;
    }

    /** @return list<array{level: string, message: string, at: string}> */
    private function appendLog(SyncJob $syncJob, string $level, string $message): array
    {
        return [
            ...($syncJob->logs ?? []),
            ['level' => $level, 'message' => $message, 'at' => now()->toIso8601String()],
        ];
    }

    private function safeError(string $error): string
    {
        $redacted = preg_replace(
            '/\b(access[_-]?token|refresh[_-]?token|client[_-]?secret|authorization)\b\s*[:=]\s*[^\s,;]+/i',
            '$1=[redacted]',
            $error,
        );

        return mb_substr($redacted ?: '同步任务执行失败。', 0, 2000);
    }
}
