<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Store;
use App\Models\SyncJob;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Support\Collection;

class StoreOperationsQueryService
{
    /** @var list<string> */
    private const SYNC_TYPES = ['products', 'orders', 'customers', 'inventory'];

    public function __construct(private AuditLogQueryService $auditLogs) {}

    /** @return array<string, mixed> */
    public function forStore(User $user, Organization $organization, Store $store): array
    {
        abort_unless($store->organization_id === $organization->getKey() && $user->canAccessStore($store), 403);

        $capabilities = [
            'sync_view' => $user->hasPermission('sync.view', $organization, $store),
            'sync_run' => $user->hasPermission('sync.run', $organization, $store),
            'sync_retry' => $user->hasPermission('sync.retry', $organization, $store),
            'webhooks_view' => $user->hasPermission('webhooks.view', $organization, $store),
            'webhooks_retry' => $user->hasPermission('webhooks.retry', $organization, $store),
            'audit_view' => $user->hasPermission('audit.view', $organization, $store),
        ];

        $syncJobs = $capabilities['sync_view']
            ? $this->syncJobs($organization, $store)
            : collect();
        $webhookEvents = $capabilities['webhooks_view']
            ? $this->webhookEvents($organization, $store)
            : collect();
        $auditLogs = $capabilities['audit_view']
            ? collect($this->auditLogs->recentForStore($user, $organization, $store, 20))
            : collect();

        return [
            'capabilities' => $capabilities,
            'sync' => $this->syncData($user, $organization, $store, $syncJobs, $capabilities),
            'webhooks' => $this->webhookData($organization, $store, $webhookEvents, $capabilities),
            'logs' => $this->logData($organization, $store, $syncJobs, $webhookEvents, $auditLogs, $capabilities),
        ];
    }

    /** @return Collection<int, SyncJob> */
    private function syncJobs(Organization $organization, Store $store): Collection
    {
        return SyncJob::query()
            ->where('organization_id', $organization->getKey())
            ->where('store_id', $store->getKey())
            ->with(['appInstallation:id,app_id,status', 'appInstallation.app:id,name,handle'])
            ->latest()
            ->limit(20)
            ->get();
    }

    /** @return Collection<int, WebhookEvent> */
    private function webhookEvents(Organization $organization, Store $store): Collection
    {
        return WebhookEvent::query()
            ->where('organization_id', $organization->getKey())
            ->where('store_id', $store->getKey())
            ->latest('received_at')
            ->limit(20)
            ->get();
    }

    /** @param Collection<int, SyncJob> $jobs
     * @param  array<string, bool>  $capabilities
     * @return array<string, mixed>
     */
    private function syncData(User $user, Organization $organization, Store $store, Collection $jobs, array $capabilities): array
    {
        $query = SyncJob::query()
            ->where('organization_id', $organization->getKey())
            ->where('store_id', $store->getKey());

        $runnableTypes = collect(self::SYNC_TYPES)
            ->filter(fn (string $type): bool => $capabilities['sync_run']
                && $user->hasPermission("{$type}.sync", $organization, $store))
            ->values()
            ->all();

        return [
            'summary' => $capabilities['sync_view'] ? [
                'total' => (clone $query)->count(),
                'running' => (clone $query)->whereIn('status', ['pending', 'queued', 'running'])->count(),
                'completed' => (clone $query)->where('status', 'completed')->count(),
                'failed' => (clone $query)->where('status', 'failed')->count(),
            ] : ['total' => 0, 'running' => 0, 'completed' => 0, 'failed' => 0],
            'runnable_types' => $runnableTypes,
            'installations' => $capabilities['sync_run'] ? $store->appInstallations
                ->where('status', 'active')
                ->map(fn ($installation): array => [
                    'id' => $installation->id,
                    'status' => $installation->status,
                    'app' => $installation->app ? [
                        'id' => $installation->app->id,
                        'name' => $installation->app->name,
                        'handle' => $installation->app->handle,
                    ] : null,
                ])->values()->all() : [],
            'jobs' => $jobs->map(fn (SyncJob $job): array => [
                'id' => $job->id,
                'uuid' => $job->uuid,
                'type' => $job->type,
                'mode' => $job->mode,
                'status' => $job->status,
                'processed_items' => $job->processed_items,
                'failed_items' => $job->failed_items,
                'error_code' => $job->error_code,
                'started_at' => $job->started_at?->toIso8601String(),
                'finished_at' => $job->finished_at?->toIso8601String(),
                'created_at' => $job->created_at?->toIso8601String(),
                'can_retry' => $capabilities['sync_retry'] && $job->status === 'failed',
            ])->values()->all(),
        ];
    }

    /** @param Collection<int, WebhookEvent> $events
     * @param  array<string, bool>  $capabilities
     * @return array<string, mixed>
     */
    private function webhookData(Organization $organization, Store $store, Collection $events, array $capabilities): array
    {
        $query = WebhookEvent::query()
            ->where('organization_id', $organization->getKey())
            ->where('store_id', $store->getKey());

        return [
            'summary' => $capabilities['webhooks_view'] ? [
                'total' => (clone $query)->count(),
                'processed' => (clone $query)->where('status', 'processed')->count(),
                'active' => (clone $query)->whereIn('status', ['received', 'queued', 'processing', 'retrying'])->count(),
                'failed' => (clone $query)->where('status', 'failed')->count(),
            ] : ['total' => 0, 'processed' => 0, 'active' => 0, 'failed' => 0],
            'events' => $events->map(fn (WebhookEvent $event): array => [
                'id' => $event->id,
                'webhook_id' => $event->webhook_id,
                'topic' => $event->topic,
                'status' => $event->status,
                'processing_result' => $event->processing_result,
                'attempts' => $event->attempts,
                'received_at' => $event->received_at?->toIso8601String(),
                'processed_at' => $event->processed_at?->toIso8601String(),
                'can_retry' => $capabilities['webhooks_retry'] && $event->status === 'failed',
            ])->values()->all(),
        ];
    }

    /** @param Collection<int, SyncJob> $jobs
     * @param  Collection<int, WebhookEvent>  $events
     * @param  Collection<int, array<string, mixed>>  $auditLogs
     * @param  array<string, bool>  $capabilities
     * @return array<string, mixed>
     */
    private function logData(Organization $organization, Store $store, Collection $jobs, Collection $events, Collection $auditLogs, array $capabilities): array
    {
        $auditItems = $auditLogs->map(fn (array $audit): array => [
            'key' => "audit-{$audit['id']}",
            'source' => 'audit',
            'title' => $audit['action_label'],
            'description' => $audit['category'].' · '.($audit['actor']['name'] ?? '系统'),
            'status' => $audit['result'],
            'occurred_at' => $audit['created_at'],
            'href' => "/audit-logs/{$audit['id']}",
        ]);
        $syncItems = $jobs->map(fn (SyncJob $job): array => [
            'key' => "sync-{$job->id}",
            'source' => 'sync',
            'title' => $this->syncTypeLabel($job->type).'同步 · '.$this->syncStatusLabel($job->status),
            'description' => $job->error_code ? "错误码：{$job->error_code}" : $this->syncModeLabel($job->mode).'同步任务',
            'status' => $this->resultStatus($job->status),
            'occurred_at' => $job->finished_at?->toIso8601String() ?? $job->created_at?->toIso8601String(),
            'href' => "/sync/{$job->id}",
        ]);
        $webhookItems = $events->map(fn (WebhookEvent $event): array => [
            'key' => "webhook-{$event->id}",
            'source' => 'webhook',
            'title' => "Webhook · {$event->topic}",
            'description' => $this->webhookStatusLabel($event->status),
            'status' => $this->resultStatus($event->status),
            'occurred_at' => $event->processed_at?->toIso8601String() ?? $event->received_at?->toIso8601String(),
            'href' => "/webhooks/{$event->id}",
        ]);
        $items = $auditItems->concat($syncItems)->concat($webhookItems)
            ->sortByDesc('occurred_at')
            ->take(30)
            ->values();

        $operations = $capabilities['audit_view']
            ? AuditLog::query()->where('organization_id', $organization->getKey())->where('store_id', $store->getKey())->count()
            : 0;
        $integrations = ($capabilities['sync_view']
            ? SyncJob::query()->where('organization_id', $organization->getKey())->where('store_id', $store->getKey())->count()
            : 0) + ($capabilities['webhooks_view']
            ? WebhookEvent::query()->where('organization_id', $organization->getKey())->where('store_id', $store->getKey())->count()
            : 0);
        $auditExceptions = $capabilities['audit_view']
            ? AuditLog::query()
                ->where('organization_id', $organization->getKey())
                ->where('store_id', $store->getKey())
                ->where(function ($query): void {
                    $query->where('action', 'like', '%invalid%')
                        ->orWhere('action', 'like', '%failed%')
                        ->orWhere('action', 'like', '%warning%')
                        ->orWhere('action', 'like', '%disconnected%');
                })
                ->count()
            : 0;
        $exceptions = ($capabilities['sync_view']
            ? SyncJob::query()->where('organization_id', $organization->getKey())->where('store_id', $store->getKey())->where('status', 'failed')->count()
            : 0) + ($capabilities['webhooks_view']
            ? WebhookEvent::query()->where('organization_id', $organization->getKey())->where('store_id', $store->getKey())->where('status', 'failed')->count()
            : 0) + $auditExceptions;

        return [
            'summary' => ['operations' => $operations, 'integrations' => $integrations, 'exceptions' => $exceptions],
            'items' => $items->all(),
        ];
    }

    private function syncTypeLabel(string $type): string
    {
        return ['products' => '商品', 'orders' => '订单', 'customers' => '客户', 'inventory' => '库存'][$type] ?? $type;
    }

    private function syncModeLabel(string $mode): string
    {
        return ['full' => '全量', 'incremental' => '增量', 'reconcile' => '一致性校准'][$mode] ?? $mode;
    }

    private function syncStatusLabel(string $status): string
    {
        return ['pending' => '待处理', 'queued' => '已入队', 'running' => '执行中', 'completed' => '已完成', 'failed' => '失败', 'cancelled' => '已取消'][$status] ?? $status;
    }

    private function webhookStatusLabel(string $status): string
    {
        return ['received' => '已接收', 'queued' => '已入队', 'processing' => '处理中', 'processed' => '已处理', 'failed' => '处理失败', 'retrying' => '重试排队中'][$status] ?? $status;
    }

    private function resultStatus(string $status): string
    {
        return match ($status) {
            'completed', 'processed' => 'success',
            'failed' => 'error',
            'cancelled' => 'warning',
            default => 'info',
        };
    }
}
