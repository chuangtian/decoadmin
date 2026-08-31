<?php

namespace App\Services;

use App\Jobs\SyncFeishuCampaignActivitiesForStore;
use App\Models\AuditLog;
use App\Models\CampaignActivity;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

class CampaignThemeRefreshService
{
    private const STATUS_TTL_SECONDS = 7200;

    /** @return array{status: string, message: string|null, requested_at: string|null, started_at: string|null, finished_at: string|null, last_synced_at: string|null} */
    public function status(Store $store): array
    {
        $cached = Cache::get($this->statusKey($store), []);
        $lastSyncedAt = CampaignActivity::query()
            ->forOrganization((int) $store->organization_id)
            ->forStore((int) $store->id)
            ->max('synced_at');

        return [
            'status' => is_string($cached['status'] ?? null) ? $cached['status'] : 'idle',
            'message' => is_string($cached['message'] ?? null) ? $cached['message'] : null,
            'requested_at' => is_string($cached['requested_at'] ?? null) ? $cached['requested_at'] : null,
            'started_at' => is_string($cached['started_at'] ?? null) ? $cached['started_at'] : null,
            'finished_at' => is_string($cached['finished_at'] ?? null) ? $cached['finished_at'] : null,
            'last_synced_at' => $lastSyncedAt ? (string) $lastSyncedAt : null,
        ];
    }

    public function enqueue(Store $store, User $actor): bool
    {
        $queued = Cache::lock($this->dispatchLockKey($store), 10)->get(function () use ($store, $actor): bool {
            if (in_array($this->status($store)['status'], ['queued', 'running'], true)) {
                return false;
            }

            $requestedAt = now()->toIso8601String();
            $this->putStatus($store, [
                'status' => 'queued',
                'message' => '飞书活动数据更新任务已排队。',
                'requested_at' => $requestedAt,
                'started_at' => null,
                'finished_at' => null,
            ]);

            AuditLog::query()->create([
                'organization_id' => $store->organization_id,
                'store_id' => $store->id,
                'user_id' => $actor->id,
                'action' => 'campaign_activity_sync_queued',
                'subject_type' => $store->getMorphClass(),
                'subject_id' => $store->id,
                'old_values' => null,
                'new_values' => ['status' => 'queued'],
                'metadata' => ['source' => 'campaign_themes_manual_refresh'],
            ]);

            SyncFeishuCampaignActivitiesForStore::dispatch((int) $store->id, (int) $actor->id)
                ->afterCommit();

            return true;
        });

        return $queued === true;
    }

    public function markRunning(Store $store): void
    {
        $current = $this->status($store);
        $this->putStatus($store, [
            ...$current,
            'status' => 'running',
            'message' => '正在归档 App Token 下的全部飞书数据表，并更新活动数据及图片。',
            'started_at' => now()->toIso8601String(),
            'finished_at' => null,
        ]);
    }

    /**
     * @param array{
     *     inserted: int, updated: int, skipped: int, records: int,
     *     archived_tables?: int, archived_fields?: int, archived_records?: int,
     *     planning_documents?: int, planning_inserted?: int, planning_updated?: int,
     *     planning_unchanged?: int, planning_failed?: int
     * } $result
     */
    public function markCompleted(Store $store, ?int $actorId, array $result): void
    {
        $finishedAt = now()->toIso8601String();
        $message = (int) ($result['archived_tables'] ?? 0) > 0
            ? sprintf(
                '更新完成：已归档 %d 张表、%d 个字段、%d 条记录；活动新增 %d，更新 %d，跳过 %d。',
                (int) $result['archived_tables'],
                (int) ($result['archived_fields'] ?? 0),
                (int) ($result['archived_records'] ?? 0),
                $result['inserted'],
                $result['updated'],
                $result['skipped'],
            )
            : sprintf(
                '更新完成：新增 %d，更新 %d，跳过 %d。',
                $result['inserted'],
                $result['updated'],
                $result['skipped'],
            );
        if ((int) ($result['planning_documents'] ?? 0) > 0) {
            $message .= sprintf(
                ' 策划书同步 %d 份：新增 %d，更新 %d，无变化 %d，失败 %d。',
                (int) $result['planning_documents'],
                (int) ($result['planning_inserted'] ?? 0),
                (int) ($result['planning_updated'] ?? 0),
                (int) ($result['planning_unchanged'] ?? 0),
                (int) ($result['planning_failed'] ?? 0),
            );
        }
        $this->putStatus($store, [
            ...$this->status($store),
            'status' => 'completed',
            'message' => $message,
            'finished_at' => $finishedAt,
        ]);

        AuditLog::query()->create([
            'organization_id' => $store->organization_id,
            'store_id' => $store->id,
            'user_id' => $actorId,
            'action' => 'campaign_activity_sync_completed',
            'subject_type' => $store->getMorphClass(),
            'subject_id' => $store->id,
            'old_values' => ['status' => 'running'],
            'new_values' => ['status' => 'completed'],
            'metadata' => [
                'source' => 'campaign_themes_manual_refresh',
                'records' => $result['records'],
                'inserted' => $result['inserted'],
                'updated' => $result['updated'],
                'skipped' => $result['skipped'],
                'archived_tables' => (int) ($result['archived_tables'] ?? 0),
                'archived_fields' => (int) ($result['archived_fields'] ?? 0),
                'archived_records' => (int) ($result['archived_records'] ?? 0),
                'planning_documents' => (int) ($result['planning_documents'] ?? 0),
                'planning_inserted' => (int) ($result['planning_inserted'] ?? 0),
                'planning_updated' => (int) ($result['planning_updated'] ?? 0),
                'planning_unchanged' => (int) ($result['planning_unchanged'] ?? 0),
                'planning_failed' => (int) ($result['planning_failed'] ?? 0),
            ],
        ]);
    }

    public function markFailed(Store $store, ?int $actorId): void
    {
        $this->putStatus($store, [
            ...$this->status($store),
            'status' => 'failed',
            'message' => '飞书活动数据更新失败，请稍后重试。',
            'finished_at' => now()->toIso8601String(),
        ]);

        AuditLog::query()->create([
            'organization_id' => $store->organization_id,
            'store_id' => $store->id,
            'user_id' => $actorId,
            'action' => 'campaign_activity_sync_failed',
            'subject_type' => $store->getMorphClass(),
            'subject_id' => $store->id,
            'old_values' => ['status' => 'running'],
            'new_values' => ['status' => 'failed'],
            'metadata' => ['source' => 'campaign_themes_manual_refresh'],
        ]);
    }

    /** @param array<string, mixed> $status */
    private function putStatus(Store $store, array $status): void
    {
        Cache::put($this->statusKey($store), $status, self::STATUS_TTL_SECONDS);
    }

    private function statusKey(Store $store): string
    {
        return "campaign-theme-refresh:{$store->organization_id}:{$store->id}";
    }

    private function dispatchLockKey(Store $store): string
    {
        return "campaign-theme-refresh-dispatch:{$store->organization_id}:{$store->id}";
    }
}
