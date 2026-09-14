<?php

namespace App\Services\Advertising;

use App\Models\AdvertisingChannelDailyMetric;
use App\Models\Store;
use App\Models\StoreSyncState;
use App\Models\SyncJob;

class AdvertisingChannelStatusService
{
    public function __construct(private AdvertisingChannelSyncService $sync) {}

    /** @return array<string, mixed> */
    public function forStore(Store $store, string $channel): array
    {
        $definition = AdvertisingChannelSyncService::CHANNELS[$channel] ?? null;
        abort_unless($definition, 404);
        $configured = $this->sync->configured($store, $channel);
        $base = [
            'schema' => 'advertising-channel-sync-status-v1',
            'channel' => $channel,
            'label' => $definition['label'],
            'configured' => $configured,
            'settings_url' => route('store-settings.credentials', ['provider' => $definition['provider']]),
        ];
        if (! $configured) {
            return [...$base, ...$this->empty('not_configured')];
        }

        $type = $this->sync->syncType($channel);
        $state = StoreSyncState::query()
            ->where('organization_id', $store->organization_id)
            ->where('store_id', $store->getKey())
            ->where('sync_type', $type)
            ->first();
        $job = SyncJob::query()
            ->where('organization_id', $store->organization_id)
            ->where('store_id', $store->getKey())
            ->where('type', $type)
            ->latest('id')
            ->first();
        $priorityReady = SyncJob::query()
            ->where('organization_id', $store->organization_id)
            ->where('store_id', $store->getKey())
            ->where('type', $type)
            ->where('mode', 'priority')
            ->where('status', 'completed')
            ->exists();
        $freshness = AdvertisingChannelDailyMetric::query()
            ->forOrganization((int) $store->organization_id)
            ->forStore((int) $store->getKey())
            ->where('provider', $channel)
            ->selectRaw('MAX(metric_date) as metric_date, MAX(synced_at) as synced_at')
            ->first();
        $data = [
            'data_ready' => $priorityReady || (bool) $state?->last_success_at,
            'last_metric_date' => $freshness?->metric_date?->toDateString(),
            'data_synced_at' => $freshness?->synced_at?->toIso8601String(),
            'last_success_at' => $state?->last_success_at?->toIso8601String(),
        ];

        if ($job && in_array($job->status, ['queued', 'running'], true)) {
            $total = max(0, (int) $job->total_items);
            $processed = max(0, (int) $job->processed_items);

            return [
                ...$base,
                ...$data,
                'state' => $job->mode === 'backfill' && $priorityReady ? 'backfilling' : 'syncing',
                'mode' => $job->mode,
                'progress_percent' => $total > 0 ? min(99.9, round(($processed / $total) * 100, 1)) : 0,
                'completed_chunks' => $processed,
                'total_chunks' => $total,
                'message' => match ($job->mode) {
                    'backfill' => '最近 7 天数据已可使用，正在后台补齐最近半年的历史数据。',
                    'realtime' => '正在刷新今天的 Google Ads 核心指标。',
                    'incremental', 'reconcile' => '正在刷新广告账户和历史指标。',
                    default => '正在优先同步账户信息和最近 7 天数据，完成后页面即可使用。',
                },
            ];
        }

        if ($job?->status === 'failed' || $state?->status === 'failed') {
            return [
                ...$base,
                ...$data,
                'state' => $priorityReady ? 'partial_failed' : 'failed',
                'mode' => $job?->mode,
                'progress_percent' => 0,
                'completed_chunks' => (int) ($job?->processed_items ?? 0),
                'total_chunks' => (int) ($job?->total_items ?? 0),
                'message' => $priorityReady
                    ? '最近 7 天数据可用，但历史回填未完成，系统会按队列重试。'
                    : '数据同步失败，请检查授权配置或稍后重新保存凭证。',
            ];
        }

        // The backfill job is dispatched with a short delay after the priority
        // phase. Until a worker starts it there is no SyncJob row yet, but the
        // UI should still show that the six-month history is pending instead
        // of claiming the full history has already completed.
        if ($priorityReady && ! $state?->last_full_sync_at) {
            return [
                ...$base,
                ...$data,
                'state' => 'backfilling',
                'mode' => 'backfill',
                'progress_percent' => 0,
                'completed_chunks' => 0,
                'total_chunks' => 0,
                'message' => '最近 7 天数据已可使用，历史回填已排队并将在后台继续。',
            ];
        }

        return [
            ...$base,
            ...$data,
            'state' => $priorityReady ? 'completed' : 'pending',
            'mode' => $job?->mode,
            'progress_percent' => $priorityReady ? 100 : 0,
            'completed_chunks' => (int) ($job?->processed_items ?? 0),
            'total_chunks' => (int) ($job?->total_items ?? 0),
            'message' => $priorityReady ? '广告账户和历史指标已同步到当前店铺数据库。' : '凭证已配置，正在等待后台启动同步。',
        ];
    }

    /** @return array<string, mixed> */
    private function empty(string $state): array
    {
        return [
            'state' => $state,
            'mode' => null,
            'data_ready' => false,
            'progress_percent' => 0,
            'completed_chunks' => 0,
            'total_chunks' => 0,
            'last_metric_date' => null,
            'data_synced_at' => null,
            'last_success_at' => null,
            'message' => null,
        ];
    }
}
