<?php

namespace App\Services\MetaAds;

use App\Models\MetaAdInsight;
use App\Models\MetaAdSyncShard;
use App\Models\Store;
use App\Models\StoreBusinessCredential;
use App\Models\StoreSyncState;
use App\Models\SyncJob;
use Carbon\CarbonImmutable;
use Throwable;

class MetaAdsStatusService
{
    /** @return array<string, mixed> */
    public function forStore(Store $store): array
    {
        $configured = StoreBusinessCredential::query()
            ->where('organization_id', $store->organization_id)
            ->where('store_id', $store->getKey())
            ->where('provider', 'meta_ads')
            ->where('credential_key', 'access_token')
            ->exists();

        $base = [
            'schema' => 'meta-ads-sync-status-v2',
            'configured' => $configured,
            'settings_url' => route('store-settings.credentials', ['provider' => 'meta_ads']),
        ];

        if (! $configured) {
            return [...$base, ...$this->emptyStatus('not_configured')];
        }

        $state = StoreSyncState::query()
            ->where('organization_id', $store->organization_id)
            ->where('store_id', $store->getKey())
            ->where('sync_type', MetaAdsSyncService::SYNC_TYPE)
            ->first();
        $job = SyncJob::query()
            ->where('organization_id', $store->organization_id)
            ->where('store_id', $store->getKey())
            ->where('type', MetaAdsSyncService::SYNC_TYPE)
            ->latest('id')
            ->first();
        $priorityReady = SyncJob::query()
            ->where('organization_id', $store->organization_id)
            ->where('store_id', $store->getKey())
            ->where('type', MetaAdsSyncService::SYNC_TYPE)
            ->where('mode', 'priority')
            ->where('status', 'completed')
            ->exists();
        $freshness = MetaAdInsight::query()
            ->forOrganization((int) $store->organization_id)
            ->forStore((int) $store->getKey())
            ->selectRaw('MAX(date_stop) as metric_date, MAX(synced_at) as synced_at')
            ->first();
        $data = [
            'data_ready' => $priorityReady || (bool) $state?->last_success_at,
            'last_metric_date' => filled($freshness?->metric_date)
                ? CarbonImmutable::parse($freshness->metric_date)->toDateString()
                : null,
            'data_synced_at' => $freshness?->synced_at?->toIso8601String(),
        ];

        if (($state && in_array($state->status, ['queued', 'running'], true))
            || ($job && in_array($job->status, ['queued', 'running'], true))) {
            return [...$base, ...$data, ...$this->syncingStatus($state, $job, $priorityReady)];
        }

        if (($state?->status === 'failed') || ($job?->status === 'failed')) {
            return [
                ...$base,
                ...$this->emptyStatus('failed'),
                ...$data,
                'state' => $priorityReady ? 'partial_failed' : 'failed',
                'data_ready' => $priorityReady,
                'mode' => $job?->mode,
                'error_code' => $state?->last_error_code ?? $job?->error_code,
                'last_success_at' => $state?->last_success_at?->toIso8601String(),
                'message' => '数据同步失败，请检查 Meta Token 权限或稍后重新保存 Token 再试。',
            ];
        }

        if ($state?->last_success_at || $job?->status === 'completed') {
            return [
                ...$base,
                ...$this->emptyStatus('completed'),
                ...$data,
                'data_ready' => true,
                'mode' => $job?->mode,
                'progress_percent' => 100,
                'last_success_at' => $state?->last_success_at?->toIso8601String()
                    ?? $job?->completed_at?->toIso8601String(),
                'message' => $state?->last_full_sync_at
                    ? 'Meta Ads 最近半年数据已同步到当前店铺数据库。'
                    : 'Meta Ads 账户与最近 7 天数据已就绪，正在等待历史回填任务。',
            ];
        }

        return [
            ...$base,
            ...$this->emptyStatus('pending'),
            'message' => 'Token 已配置，正在等待后台启动首次同步。',
        ];
    }

    /** @return array<string, mixed> */
    private function syncingStatus(?StoreSyncState $state, ?SyncJob $job, bool $priorityReady): array
    {
        $total = max(0, (int) ($job?->total_items ?? 0));
        $completed = max(0, (int) ($job?->processed_items ?? 0));
        $failed = max(0, (int) ($job?->failed_items ?? 0));
        $activeShards = $job
            ? MetaAdSyncShard::query()
                ->where('sync_job_id', $job->getKey())
                ->whereIn('status', ['queued', 'running'])
                ->get(['result', 'updated_at'])
            : collect();
        $asyncProgress = $activeShards->sum(
            fn (MetaAdSyncShard $shard): float => min(
                100,
                max(0, (float) data_get($shard->result, 'async_percent', 0)),
            ) / 100,
        );
        $effectiveCompleted = min((float) $total, $completed + $asyncProgress);
        $progress = $total > 0 ? min(99.9, round(($effectiveCompleted / $total) * 100, 1)) : 0.0;
        $lastActivityAt = $activeShards->max('updated_at') ?? $job?->updated_at;
        $lastActivity = $lastActivityAt ? CarbonImmutable::parse($lastActivityAt) : null;
        $stalled = $lastActivity?->lt(now()->subMinutes(10)) ?? false;

        return [
            'state' => $job?->mode === 'backfill' && $priorityReady ? 'backfilling' : 'syncing',
            'data_ready' => $priorityReady,
            'mode' => $job?->mode ?? 'priority',
            'progress_percent' => $progress,
            'completed_shards' => $completed,
            'total_shards' => $total,
            'failed_shards' => $failed,
            'eta_seconds' => $this->etaSeconds($job, $effectiveCompleted, $total),
            'started_at' => $job?->started_at?->toIso8601String(),
            'last_activity_at' => $lastActivity?->toIso8601String(),
            'stalled' => $stalled,
            'last_success_at' => $state?->last_success_at?->toIso8601String(),
            'error_code' => null,
            'message' => match ($job?->mode) {
                'backfill' => '账户和最近 7 天数据已可使用，正在后台补齐最近半年的 Meta Ads 历史数据。',
                'incremental' => '正在滚动校正最近 3 天的 Meta Ads 数据，以覆盖延迟归因。',
                default => $total > 0
                    ? '正在优先同步广告账户和最近 7 天数据，完成后页面即可使用。'
                    : '正在读取广告账户并准备最近 7 天同步任务。',
            },
        ];
    }

    private function etaSeconds(?SyncJob $job, float $effectiveCompleted, int $total): ?int
    {
        $initialMinutes = max(1, (int) config('services.meta_ads.initial_full_estimate_minutes', 45));
        if (! $job || ! $job->started_at) {
            return $initialMinutes * 60;
        }

        $remaining = max(0.0, $total - $effectiveCompleted);
        if ($remaining === 0 && $total > 0) {
            return 0;
        }

        $baseline = max(0, (int) data_get($job->payload, 'eta_baseline_completed', 0));
        $sample = max(0.0, $effectiveCompleted - $baseline);
        $estimateStartedAt = $job->started_at;
        $configuredStart = data_get($job->payload, 'eta_started_at');
        if (is_string($configuredStart) && $configuredStart !== '') {
            try {
                $estimateStartedAt = CarbonImmutable::parse($configuredStart);
            } catch (Throwable) {
                // Keep the persisted job start when an old payload is invalid.
            }
        }
        $elapsed = max(1, $estimateStartedAt->diffInSeconds(now()));
        if ($sample >= 1) {
            $observed = (int) ceil(($elapsed / $sample) * $remaining);

            return min($initialMinutes * 60 * 4, max(60, $observed));
        }

        return max(60, ($initialMinutes * 60) - min($elapsed, ($initialMinutes - 1) * 60));
    }

    /** @return array<string, mixed> */
    private function emptyStatus(string $state): array
    {
        return [
            'state' => $state,
            'data_ready' => false,
            'mode' => null,
            'progress_percent' => 0,
            'completed_shards' => 0,
            'total_shards' => 0,
            'failed_shards' => 0,
            'eta_seconds' => null,
            'started_at' => null,
            'last_activity_at' => null,
            'stalled' => false,
            'last_success_at' => null,
            'error_code' => null,
            'message' => null,
            'last_metric_date' => null,
            'data_synced_at' => null,
        ];
    }
}
