<?php

namespace App\Services;

use App\Jobs\DeliverStoreAlertNotificationJob;
use App\Models\ShopifyConnection;
use App\Models\Store;
use App\Models\StoreAlert;
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
            DeliverStoreAlertNotificationJob::dispatch($alert->id)->onQueue('notifications');
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
                'shopify_sync_failed',
                'Shopify 数据同步失败',
                $job->last_error ?: '同步任务执行失败，请查看任务详情。',
                'error',
                ['sync_type' => $job->type, 'job_uuid' => $job->uuid],
                $job->failed_at ?? $job->updated_at,
            ));
    }

    private function webhookAlerts(Store $store): int
    {
        return WebhookEvent::query()
            ->where('store_id', $store->id)
            ->where('status', 'failed')
            ->where('received_at', '>=', now()->subDays(7))
            ->get()
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
