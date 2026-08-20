<?php

namespace App\Services\Shopify\Sync;

use App\Models\Store;
use App\Models\StoreSyncState;
use App\Models\WebhookEvent;
use Illuminate\Support\Collection;

class StoreSyncStatusQueryService
{
    /** @var list<string> */
    private const TYPES = ['products', 'orders', 'customers', 'inventory'];

    /**
     * @param  Collection<int, Store>  $stores
     * @return list<array<string, mixed>>
     */
    public function forStores(Collection $stores): array
    {
        $stores->load([
            'shopifyConnection:id,store_id,status,last_verified_at',
            'syncStates' => fn ($query) => $query->with([
                'lastJob:id,uuid,store_id,type,mode,status,started_at,finished_at,error_code,correlation_id',
            ]),
        ])->loadCount([
            'alerts as open_alerts_count' => fn ($query) => $query->whereIn('status', ['open', 'acknowledged']),
            'syncJobs as running_sync_jobs_count' => fn ($query) => $query->whereIn('status', ['pending', 'queued', 'running']),
        ]);

        $latestWebhookIds = WebhookEvent::query()
            ->whereIn('store_id', $stores->pluck('id'))
            ->selectRaw('MAX(id)')
            ->groupBy('store_id');

        $latestWebhooks = WebhookEvent::query()
            ->whereIn('id', $latestWebhookIds)
            ->get(['id', 'store_id', 'status', 'topic', 'received_at', 'processed_at'])
            ->keyBy('store_id');

        return $stores->map(function (Store $store) use ($latestWebhooks): array {
            $states = $store->syncStates->keyBy('sync_type');
            $latestWebhook = $latestWebhooks->get($store->id);
            $stateRows = collect(self::TYPES)->map(
                fn (string $type): array => $this->stateRow($states->get($type), $type),
            )->values()->all();
            $connectionStatus = $store->shopifyConnection?->status ?? 'disconnected';
            $hasFailedState = collect($stateRows)->contains(fn (array $state) => $state['status'] === 'failed');

            $status = match (true) {
                in_array($connectionStatus, ['invalid', 'disconnected'], true) => 'critical',
                $hasFailedState || $store->open_alerts_count > 0 => 'warning',
                $store->running_sync_jobs_count > 0 => 'running',
                default => 'healthy',
            };

            return [
                'store' => [
                    'id' => $store->id,
                    'name' => $store->name,
                    'shopify_domain' => $store->shopify_domain,
                    'timezone' => $store->timezone ?: 'UTC',
                ],
                'status' => $status,
                'connection_status' => $connectionStatus,
                'last_verified_at' => $store->shopifyConnection?->last_verified_at?->toIso8601String(),
                'running_jobs' => $store->running_sync_jobs_count,
                'open_alerts' => $store->open_alerts_count,
                'latest_webhook' => $latestWebhook ? [
                    'topic' => $latestWebhook->topic,
                    'status' => $latestWebhook->status,
                    'received_at' => $latestWebhook->received_at?->toIso8601String(),
                    'processed_at' => $latestWebhook->processed_at?->toIso8601String(),
                ] : null,
                'sync_states' => $stateRows,
            ];
        })->values()->all();
    }

    /** @return array<string, mixed> */
    private function stateRow(?StoreSyncState $state, string $type): array
    {
        return [
            'type' => $type,
            'status' => $state?->status ?? 'idle',
            'watermark_at' => $state?->watermark_at?->toIso8601String(),
            'last_full_sync_at' => $state?->last_full_sync_at?->toIso8601String(),
            'last_incremental_sync_at' => $state?->last_incremental_sync_at?->toIso8601String(),
            'last_reconciled_at' => $state?->last_reconciled_at?->toIso8601String(),
            'last_success_at' => $state?->last_success_at?->toIso8601String(),
            'last_failed_at' => $state?->last_failed_at?->toIso8601String(),
            'next_sync_at' => $state?->next_sync_at?->toIso8601String(),
            'consecutive_failures' => $state?->consecutive_failures ?? 0,
            'last_error_code' => $state?->last_error_code,
            'last_job' => $state?->lastJob ? [
                'id' => $state->lastJob->id,
                'uuid' => $state->lastJob->uuid,
                'mode' => $state->lastJob->mode,
                'status' => $state->lastJob->status,
                'error_code' => $state->lastJob->error_code,
                'correlation_id' => $state->lastJob->correlation_id,
            ] : null,
        ];
    }
}
