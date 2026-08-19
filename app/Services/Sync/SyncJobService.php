<?php

namespace App\Services\Sync;

use App\Jobs\ProcessSyncJob;
use App\Models\AppInstallation;
use App\Models\AuditLog;
use App\Models\Store;
use App\Models\SyncJob;
use App\Models\User;
use App\Services\Shopify\Sync\StoreSyncStateService;
use App\Services\Shopify\Sync\SyncErrorClassifier;
use App\Services\Shopify\Sync\SyncResult;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SyncJobService
{
    public function __construct(
        private StoreSyncStateService $states,
        private SyncErrorClassifier $errors,
    ) {}

    public function createAndDispatch(
        Store $store,
        string $type,
        User $actor,
        ?AppInstallation $installation = null,
        string $mode = 'full',
    ): SyncJob {
        $window = $this->states->window($store, $type, $mode);
        $syncJob = $this->createForSource(
            $store,
            $type,
            $installation,
            'manual',
            $actor->getKey(),
            $window['mode'],
            $window['since_at'],
            $window['until_at'],
        );

        ProcessSyncJob::dispatch($syncJob->getKey())->afterCommit();

        return $syncJob;
    }

    public function retryAndDispatch(SyncJob $failedJob, User $actor): SyncJob
    {
        $syncJob = DB::transaction(function () use ($failedJob, $actor): SyncJob {
            $locked = SyncJob::query()
                ->with(['store', 'appInstallation'])
                ->lockForUpdate()
                ->findOrFail($failedJob->getKey());

            if ($locked->status !== 'failed') {
                throw ValidationException::withMessages([
                    'sync_job' => '只有执行失败的同步任务可以重试。',
                ]);
            }

            if (data_get($locked->payload, 'retry_job_id')) {
                throw ValidationException::withMessages([
                    'sync_job' => '该同步任务已经创建了重试任务。',
                ]);
            }

            $retryJob = $this->createForSource(
                $locked->store,
                $locked->type,
                $locked->appInstallation?->status === 'active' ? $locked->appInstallation : null,
                'manual_retry',
                $actor->getKey(),
                $locked->mode ?: 'full',
                $locked->since_at,
                $locked->until_at ?? now(),
            );
            $retryJob->forceFill([
                'payload' => [
                    ...($retryJob->payload ?? []),
                    'retry_of_job_id' => $locked->getKey(),
                    'retry_of_uuid' => $locked->uuid,
                ],
            ])->save();
            $locked->forceFill([
                'payload' => [
                    ...($locked->payload ?? []),
                    'retry_job_id' => $retryJob->getKey(),
                    'retried_by' => $actor->getKey(),
                    'retried_at' => now()->toIso8601String(),
                ],
            ])->save();

            AuditLog::query()->create([
                'organization_id' => $locked->organization_id,
                'store_id' => $locked->store_id,
                'user_id' => $actor->getKey(),
                'action' => 'shopify_sync_retried',
                'subject_type' => $locked->getMorphClass(),
                'subject_id' => $locked->getKey(),
                'old_values' => ['status' => $locked->status],
                'new_values' => ['retry_job_id' => $retryJob->getKey(), 'status' => $retryJob->status],
                'metadata' => [
                    'sync_type' => $locked->type,
                    'mode' => $locked->mode,
                    'previous_job_uuid' => $locked->uuid,
                    'retry_job_uuid' => $retryJob->uuid,
                ],
            ]);

            return $retryJob;
        });

        ProcessSyncJob::dispatch($syncJob->getKey())->afterCommit();

        return $syncJob;
    }

    public function createScheduledAndDispatch(
        Store $store,
        string $type,
        AppInstallation $installation,
    ): SyncJob {
        $window = $this->states->window($store, $type, 'full');
        $created = $this->createAutomaticAndDispatch(
            $store,
            $type,
            $installation,
            $window['mode'],
            $window['since_at'],
            $window['until_at'],
            'scheduled',
        );

        return $created['job'];
    }

    /** @return array{job: SyncJob, created: bool} */
    public function createAutomaticAndDispatch(
        Store $store,
        string $type,
        AppInstallation $installation,
        string $mode,
        ?CarbonInterface $sinceAt,
        CarbonInterface $untilAt,
        string $source = 'scheduled',
        ?string $idempotencyWindow = null,
    ): array {
        $activeJob = SyncJob::query()
            ->where('store_id', $store->getKey())
            ->where('type', $type)
            ->whereIn('status', ['pending', 'queued', 'running'])
            ->latest('id')
            ->first();

        if ($activeJob) {
            return ['job' => $activeJob, 'created' => false];
        }

        $idempotencyKey = hash('sha256', implode('|', [
            $store->getKey(),
            $type,
            $mode,
            $idempotencyWindow ?? $sinceAt?->utc()->toIso8601String() ?? 'beginning',
            $idempotencyWindow ?? $untilAt->utc()->toIso8601String(),
        ]));
        $existing = SyncJob::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($existing) {
            return ['job' => $existing, 'created' => false];
        }

        try {
            $syncJob = $this->createForSource(
                $store,
                $type,
                $installation,
                $source,
                mode: $mode,
                sinceAt: $sinceAt,
                untilAt: $untilAt,
                idempotencyKey: $idempotencyKey,
            );
        } catch (QueryException $exception) {
            $existing = SyncJob::query()->where('idempotency_key', $idempotencyKey)->first();

            if (! $existing) {
                throw $exception;
            }

            return ['job' => $existing, 'created' => false];
        }

        ProcessSyncJob::dispatch($syncJob->getKey())->afterCommit();

        return ['job' => $syncJob, 'created' => true];
    }

    private function createForSource(
        Store $store,
        string $type,
        ?AppInstallation $installation,
        string $source,
        ?int $requestedBy = null,
        string $mode = 'full',
        ?CarbonInterface $sinceAt = null,
        ?CarbonInterface $untilAt = null,
        ?string $idempotencyKey = null,
    ): SyncJob {
        return DB::transaction(function () use ($store, $type, $installation, $source, $requestedBy, $mode, $sinceAt, $untilAt, $idempotencyKey): SyncJob {
            $filters = $this->filters($type, $mode, $sinceAt, $untilAt);
            $syncJob = SyncJob::query()->create([
                'uuid' => (string) Str::uuid(),
                'correlation_id' => (string) Str::uuid(),
                'organization_id' => $store->organization_id,
                'store_id' => $store->getKey(),
                'app_id' => $installation?->app_id,
                'app_installation_id' => $installation?->getKey(),
                'type' => $type,
                'direction' => 'pull',
                'mode' => $mode,
                'status' => 'pending',
                'since_at' => $sinceAt,
                'until_at' => $untilAt,
                'idempotency_key' => $idempotencyKey,
                'payload' => [
                    'source' => $source,
                    'requested_by' => $requestedBy,
                    'framework_only' => ! in_array($type, ['products', 'orders', 'customers', 'inventory'], true),
                    'filters' => $filters,
                ],
                'logs' => [],
                'max_attempts' => min(5, max(1, (int) config('shopify.scheduled_sync.max_attempts', 3))),
                'available_at' => now(),
            ]);

            return $this->markQueued($syncJob);
        });
    }

    public function markQueued(SyncJob $syncJob): SyncJob
    {
        $syncJob->forceFill([
            'status' => 'queued',
            'available_at' => now(),
            'logs' => $this->appendLog($syncJob, 'info', '同步任务已加入 shopify-sync 队列。'),
        ])->save();

        $syncJob->loadMissing('store');
        $this->states->markQueued($syncJob);

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

            $syncJob->loadMissing('store');
            $this->states->markRunning($syncJob);

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

        $syncJob->loadMissing('store');
        $this->states->markCompleted($syncJob);

        return $syncJob;
    }

    public function markFailed(SyncJob $syncJob, string $error, ?SyncResult $result = null, ?string $errorCode = null): SyncJob
    {
        $finishedAt = now();
        $safeError = $this->safeError($error);
        $syncJob->forceFill([
            'status' => 'failed',
            'finished_at' => $finishedAt,
            'failed_at' => $finishedAt,
            'last_error' => $safeError,
            'error_code' => $errorCode ?? $this->errors->classify($result ?? $error),
            'result' => $result?->toArray() ?? $syncJob->result,
            'failed_items' => $result ? max($syncJob->failed_items, count($result->errors)) : $syncJob->failed_items,
            'logs' => $this->appendLog($syncJob, 'error', $safeError),
        ])->save();

        $syncJob->loadMissing('store');
        $this->states->markFailed($syncJob);

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

    /** @return array<string, string> */
    private function filters(
        string $type,
        string $mode,
        ?CarbonInterface $sinceAt,
        ?CarbonInterface $untilAt,
    ): array {
        if ($mode !== 'incremental' || ! $sinceAt || ! $untilAt) {
            return [];
        }

        return [
            'updated_at_from' => $sinceAt->utc()->toIso8601String(),
            'updated_at_to' => $untilAt->utc()->toIso8601String(),
        ];
    }
}
