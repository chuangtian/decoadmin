<?php

namespace App\Services;

use App\Jobs\SyncPaidAdvertisingGoalTarget;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\PaidAdvertisingGoalBoard;
use App\Models\PaidAdvertisingGoalSyncRun;
use App\Models\Store;
use App\Models\User;
use App\Services\Feishu\PaidAdvertisingGoalSyncService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class PaidAdvertisingGoalRefreshService
{
    public const SOURCE_MANUAL_REFRESH = 'manual_refresh';

    public const SOURCE_GOAL_BOARD_CREATED = 'goal_board_created';

    public const SOURCE_OVERALL_CONFIGURED = 'overall_configured';

    public const TARGET_OVERALL = 'overall';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    private const LOCK_SECONDS = 600;

    private const INITIAL_SYNC_ESTIMATE_SECONDS = 300;

    public function __construct(private PaidAdvertisingGoalSyncService $sync) {}

    public function enqueue(
        Organization $organization,
        Store $store,
        User $actor,
        string $source = self::SOURCE_MANUAL_REFRESH,
        string $target = self::TARGET_OVERALL,
    ): PaidAdvertisingGoalSyncRun {
        $this->assertContext($organization, $store, $source);
        $board = $this->targetBoard($organization, $store, $target);

        $activeRun = PaidAdvertisingGoalSyncRun::query()
            ->forOrganization($organization)
            ->forStore($store)
            ->where('target_key', $target)
            ->whereIn('status', [self::STATUS_QUEUED, self::STATUS_RUNNING])
            ->latest('id')
            ->first();

        if ($activeRun instanceof PaidAdvertisingGoalSyncRun) {
            return $activeRun;
        }

        $run = PaidAdvertisingGoalSyncRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'goal_board_id' => $board?->id,
            'requested_by' => $actor->id,
            'target_key' => $target,
            'trigger' => $source,
            'status' => self::STATUS_QUEUED,
        ]);

        SyncPaidAdvertisingGoalTarget::dispatch(
            (int) $run->id,
            (int) $organization->id,
            (int) $store->id,
            (int) $actor->id,
            $source,
            $target,
        )->afterCommit();

        return $run;
    }

    public function findRun(
        Organization $organization,
        Store $store,
        string $uuid,
    ): PaidAdvertisingGoalSyncRun {
        return PaidAdvertisingGoalSyncRun::query()
            ->forOrganization($organization)
            ->forStore($store)
            ->where('uuid', $uuid)
            ->firstOrFail();
    }

    /** @return array<string, mixed>|null */
    public function latestForTarget(
        Organization $organization,
        Store $store,
        string $target,
    ): ?array {
        $run = PaidAdvertisingGoalSyncRun::query()
            ->forOrganization($organization)
            ->forStore($store)
            ->where('target_key', $target)
            ->latest('id')
            ->first();

        return $run instanceof PaidAdvertisingGoalSyncRun ? $this->present($run) : null;
    }

    /**
     * @return array{
     *     id: string,
     *     tab: string,
     *     status: string,
     *     result: array{sources: int, fields: int, inserted: int, updated: int, deleted: int, skipped: int, archived_tables: int, archived_fields: int, archived_records: int}|null,
     *     message: string|null,
     *     started_at: string|null,
     *     finished_at: string|null,
     *     duration_seconds: int|null,
     *     initial_sync: bool,
     *     estimated_finished_at: string|null
     * }
     */
    public function present(PaidAdvertisingGoalSyncRun $run): array
    {
        $result = is_array($run->result) ? $run->result : null;
        $initialSync = in_array($run->trigger, [
            self::SOURCE_GOAL_BOARD_CREATED,
            self::SOURCE_OVERALL_CONFIGURED,
        ], true);
        $estimatedFinishedAt = $initialSync && in_array($run->status, [self::STATUS_QUEUED, self::STATUS_RUNNING], true)
            ? ($run->started_at ?? $run->created_at)?->copy()->addSeconds(self::INITIAL_SYNC_ESTIMATE_SECONDS)
            : null;

        return [
            'id' => $run->uuid,
            'tab' => $run->target_key,
            'status' => $run->status,
            'result' => $result === null ? null : [
                'sources' => (int) ($result['sources'] ?? 0),
                'fields' => (int) ($result['fields'] ?? 0),
                'inserted' => (int) ($result['inserted'] ?? 0),
                'updated' => (int) ($result['updated'] ?? 0),
                'deleted' => (int) ($result['deleted'] ?? 0),
                'skipped' => (int) ($result['skipped'] ?? 0),
                'archived_tables' => (int) ($result['archived_tables'] ?? 0),
                'archived_fields' => (int) ($result['archived_fields'] ?? 0),
                'archived_records' => (int) ($result['archived_records'] ?? 0),
            ],
            'message' => $run->message,
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'duration_seconds' => $this->durationSeconds($run),
            'initial_sync' => $initialSync,
            'estimated_finished_at' => $estimatedFinishedAt?->toIso8601String(),
        ];
    }

    public function markRunning(PaidAdvertisingGoalSyncRun $run): void
    {
        $run->forceFill([
            'status' => self::STATUS_RUNNING,
            'started_at' => $run->started_at ?? now(),
            'finished_at' => null,
            'error_code' => null,
            'message' => null,
        ])->save();
    }

    /** @param array<string, int|bool> $result */
    public function markCompleted(PaidAdvertisingGoalSyncRun $run, array $result): void
    {
        $run->forceFill([
            'status' => self::STATUS_COMPLETED,
            'result' => [
                'sources' => (int) ($result['sources'] ?? 0),
                'fields' => (int) ($result['fields'] ?? 0),
                'inserted' => (int) ($result['inserted'] ?? 0),
                'updated' => (int) ($result['updated'] ?? 0),
                'deleted' => (int) ($result['deleted'] ?? 0),
                'skipped' => (int) ($result['skipped'] ?? 0),
                'archived_tables' => (int) ($result['archived_tables'] ?? 0),
                'archived_fields' => (int) ($result['archived_fields'] ?? 0),
                'archived_records' => (int) ($result['archived_records'] ?? 0),
            ],
            'error_code' => null,
            'message' => '当前页签飞书数据同步完成。',
            'finished_at' => now(),
        ])->save();
    }

    public function markFailed(PaidAdvertisingGoalSyncRun $run, Throwable|string $error): void
    {
        $run->forceFill([
            'status' => self::STATUS_FAILED,
            'error_code' => 'feishu_paid_advertising_goal_sync_failed',
            'message' => $this->safeError($error),
            'finished_at' => now(),
        ])->save();
    }

    /**
     * @return array{
     *     acquired: bool,
     *     sources: int,
     *     fields: int,
     *     inserted: int,
     *     updated: int,
     *     deleted: int,
     *     skipped: int,
     *     archived_tables: int,
     *     archived_fields: int,
     *     archived_records: int,
     *     failed: int
     * }
     */
    public function syncNow(
        Organization $organization,
        Store $store,
        User $actor,
        string $source = self::SOURCE_MANUAL_REFRESH,
        string $target = self::TARGET_OVERALL,
    ): array {
        $this->assertContext($organization, $store, $source);
        $board = $this->targetBoard($organization, $store, $target);

        $result = Cache::lock($this->lockKey($organization, $store, $target), self::LOCK_SECONDS)
            ->get(function () use ($organization, $store, $actor, $source, $board): array {
                $syncResult = $board instanceof PaidAdvertisingGoalBoard
                    ? $this->sync->syncBoard($board)
                    : $this->sync->syncOverall($store);

                AuditLog::query()->create([
                    'organization_id' => $organization->id,
                    'store_id' => $store->id,
                    'user_id' => $actor->id,
                    'action' => 'paid_advertising_goals_refreshed',
                    'subject_type' => Store::class,
                    'subject_id' => $store->id,
                    'old_values' => null,
                    'new_values' => ['status' => 'completed'],
                    'metadata' => [
                        'source' => 'paid_advertising_goals_'.$source,
                        'scope' => 'store',
                        'target' => $board instanceof PaidAdvertisingGoalBoard ? 'board' : 'overall',
                        'goal_board_id' => $board?->id,
                        'fields' => $syncResult['fields'],
                        'inserted' => $syncResult['inserted'],
                        'updated' => $syncResult['updated'],
                        'deleted' => $syncResult['deleted'],
                        'skipped' => $syncResult['skipped'],
                        'archived_tables' => (int) ($syncResult['archived_tables'] ?? 0),
                        'archived_fields' => (int) ($syncResult['archived_fields'] ?? 0),
                        'archived_records' => (int) ($syncResult['archived_records'] ?? 0),
                    ],
                ]);

                return [
                    'acquired' => true,
                    'sources' => (int) ($syncResult['sources'] ?? 1),
                    'fields' => $syncResult['fields'],
                    'inserted' => $syncResult['inserted'],
                    'updated' => $syncResult['updated'],
                    'deleted' => $syncResult['deleted'],
                    'skipped' => $syncResult['skipped'],
                    'archived_tables' => (int) ($syncResult['archived_tables'] ?? 0),
                    'archived_fields' => (int) ($syncResult['archived_fields'] ?? 0),
                    'archived_records' => (int) ($syncResult['archived_records'] ?? 0),
                    'failed' => 0,
                ];
            });

        return is_array($result) ? $result : [
            'acquired' => false,
            'sources' => 0,
            'fields' => 0,
            'inserted' => 0,
            'updated' => 0,
            'deleted' => 0,
            'skipped' => 0,
            'archived_tables' => 0,
            'archived_fields' => 0,
            'archived_records' => 0,
            'failed' => 0,
        ];
    }

    private function durationSeconds(PaidAdvertisingGoalSyncRun $run): ?int
    {
        if ($run->started_at === null) {
            return null;
        }

        return max(0, (int) $run->started_at->diffInSeconds($run->finished_at ?? now()));
    }

    private function lockKey(Organization $organization, Store $store, string $target): string
    {
        return "paid-advertising-goals-refresh:{$organization->id}:{$store->id}:{$target}";
    }

    private function assertContext(Organization $organization, Store $store, string $source): void
    {
        if ((int) $store->organization_id !== (int) $organization->id) {
            throw new InvalidArgumentException('The store does not belong to the supplied organization.');
        }

        if (! in_array($source, [self::SOURCE_MANUAL_REFRESH, self::SOURCE_GOAL_BOARD_CREATED, self::SOURCE_OVERALL_CONFIGURED], true)) {
            throw new InvalidArgumentException('Unsupported paid advertising goal refresh source.');
        }
    }

    private function targetBoard(
        Organization $organization,
        Store $store,
        string $target,
    ): ?PaidAdvertisingGoalBoard {
        if ($target === self::TARGET_OVERALL) {
            return null;
        }

        if (! preg_match('/^board-([1-9][0-9]*)$/', $target, $matches)) {
            throw new InvalidArgumentException('Unsupported paid advertising goal refresh target.');
        }

        return PaidAdvertisingGoalBoard::query()
            ->forOrganization($organization)
            ->forStore($store)
            ->findOrFail((int) $matches[1]);
    }

    private function safeError(Throwable|string $error): string
    {
        $message = $error instanceof Throwable ? $error->getMessage() : $error;
        $message = preg_replace(
            '/https?:\/\/[^\s]+|(?:app|tbl|vew)[A-Za-z0-9_-]{6,}|(?:token|secret|password)\s*[:=]\s*[^\s,;]+/i',
            '[redacted]',
            $message,
        );

        return mb_substr($message ?: '飞书同步失败，请检查当前页签的飞书配置后重试。', 0, 300);
    }
}
