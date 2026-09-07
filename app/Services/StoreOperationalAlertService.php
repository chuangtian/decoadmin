<?php

namespace App\Services;

use App\Jobs\DeliverStoreAlertNotificationJob;
use App\Models\ShopifyConnection;
use App\Models\Store;
use App\Models\StoreAlert;
use App\Models\StoreSyncState;
use App\Models\SyncJob;
use App\Models\WebhookEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class StoreOperationalAlertService
{
    /** @return array{stores: int, created: int} */
    public function scan(?Store $onlyStore = null): array
    {
        $stores = Store::query()
            ->where('status', 'active')
            ->when($onlyStore, fn (Builder $query) => $query->whereKey($onlyStore->id))
            ->with('shopifyConnection')
            ->get();
        $created = 0;

        foreach ($stores as $store) {
            $created += $this->connectionAlert($store);
            $created += $this->syncAlerts($store);
            $created += $this->syncStateAlerts($store);
            $created += $this->webhookAlerts($store);
        }

        return ['stores' => $stores->count(), 'created' => $created];
    }

    /** @param array<string, scalar|null> $context */
    public function record(
        Store $store,
        string $type,
        string $sourceType,
        int $sourceId,
        string $code,
        string $title,
        string $message,
        string $severity = 'error',
        array $context = [],
        mixed $occurredAt = null,
    ): bool {
        $fingerprint = hash('sha256', implode('|', [$store->id, $type, $sourceType, $sourceId, $code]));
        $alert = StoreAlert::query()->firstOrCreate(
            ['fingerprint' => $fingerprint],
            [
                'organization_id' => $store->organization_id,
                'store_id' => $store->id,
                'type' => $type,
                'severity' => $severity,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'code' => $code,
                'title' => Str::limit($title, 255, ''),
                'message' => $this->sanitize($message),
                'context' => $context,
                'status' => 'open',
                'delivery_status' => 'pending',
                'occurred_at' => $occurredAt ?? now(),
            ],
        );

        if ($alert->wasRecentlyCreated) {
            DeliverStoreAlertNotificationJob::dispatch($alert->id)->onQueue('notifications')->afterCommit();
        }

        return $alert->wasRecentlyCreated;
    }

    private function connectionAlert(Store $store): int
    {
        $connection = $store->shopifyConnection;
        if (! $connection || $connection->status === 'connected') {
            StoreAlert::query()
                ->where('store_id', $store->id)
                ->where('type', 'connection')
                ->whereIn('status', ['open', 'acknowledged'])
                ->update(['status' => 'resolved', 'resolved_at' => now()]);

            return 0;
        }

        return (int) $this->record(
            $store,
            'connection',
            ShopifyConnection::class,
            $connection->id,
            'shopify_connection_'.$connection->status,
            'Shopify 连接状态异常',
            $connection->last_error ?: "连接当前状态为 {$connection->status}。",
            $connection->status === 'warning' ? 'warning' : 'critical',
            ['status' => $connection->status],
            $connection->last_error_at,
        );
    }

    private function syncAlerts(Store $store): int
    {
        return SyncJob::query()
            ->where('store_id', $store->id)
            ->where('status', 'failed')
            ->where('created_at', '>=', now()->subDays(7))
            ->get()
            ->sum(fn (SyncJob $job): int => (int) $this->record(
                $store,
                'sync',
                SyncJob::class,
                $job->id,
                $job->error_code ?: 'shopify_sync_failed',
                'Shopify 数据同步失败',
                $job->last_error ?: '同步任务执行失败，请查看任务详情。',
                'error',
                [
                    'sync_type' => $job->type,
                    'sync_mode' => $job->mode,
                    'job_uuid' => $job->uuid,
                    'correlation_id' => $job->correlation_id,
                    'attempts' => $job->attempts,
                    'max_attempts' => $job->max_attempts,
                ],
                $job->failed_at ?? $job->updated_at,
            ));
    }

    private function syncStateAlerts(Store $store): int
    {
        $created = 0;
        $stalledAfter = max(10, (int) config('shopify.scheduled_sync.stalled_after_minutes', 45));

        StoreSyncState::query()
            ->where('store_id', $store->id)
            ->with('lastJob')
            ->get()
            ->each(function (StoreSyncState $state) use ($store, $stalledAfter, &$created): void {
                $job = $state->lastJob;

                if ($state->status === 'running' && $job?->started_at?->lt(now()->subMinutes($stalledAfter))) {
                    $created += (int) $this->record(
                        $store,
                        'sync',
                        SyncJob::class,
                        $job->id,
                        'sync_stalled',
                        'Shopify 同步任务长时间无进展',
                        "{$state->sync_type} 同步已运行超过 {$stalledAfter} 分钟。",
                        'critical',
                        [
                            'sync_type' => $state->sync_type,
                            'job_uuid' => $job->uuid,
                            'correlation_id' => $job->correlation_id,
                        ],
                        $job->started_at,
                    );
                }

                if ($state->consecutive_failures >= max(1, (int) config('shopify.scheduled_sync.max_attempts', 3))) {
                    $created += (int) $this->record(
                        $store,
                        'sync',
                        StoreSyncState::class,
                        $state->id,
                        'sync_max_attempts_reached',
                        'Shopify 同步连续失败',
                        "{$state->sync_type} 同步已连续失败 {$state->consecutive_failures} 次。",
                        'critical',
                        [
                            'sync_type' => $state->sync_type,
                            'consecutive_failures' => $state->consecutive_failures,
                            'last_job_id' => $state->last_job_id,
                        ],
                        $state->last_failed_at,
                    );
                }
            });

        return $created;
    }

    private function webhookAlerts(Store $store): int
    {
        return WebhookEvent::query()
            ->where('store_id', $store->id)
            ->where('status', 'failed')
            ->where('received_at', '>=', now()->subDays(7))
            ->get(['id', 'store_id', 'webhook_id', 'topic', 'last_error', 'received_at'])
            ->sum(fn (WebhookEvent $event): int => (int) $this->record(
                $store,
                'webhook',
                WebhookEvent::class,
                $event->id,
                'shopify_webhook_failed',
                'Shopify Webhook 处理失败',
                $event->last_error ?: 'Webhook 事件处理失败，请查看事件详情。',
                'error',
                ['topic' => $event->topic, 'webhook_id' => $event->webhook_id],
                $event->received_at,
            ));
    }

    private function sanitize(string $message): string
    {
        $sanitized = preg_replace([
            '/\b(?:access[_-]?token|refresh[_-]?token|client[_-]?secret|password|authorization)\b\s*[:=]\s*[^\s,;]+/i',
            '/\bBearer\s+[^\s,;]+/i',
            '/\bshp(?:at|ss|ca|ua)_[A-Za-z0-9]+\b/i',
        ], '[redacted]', $message) ?: '发生未知异常。';

        return Str::limit($sanitized, 2000);
    }
}
