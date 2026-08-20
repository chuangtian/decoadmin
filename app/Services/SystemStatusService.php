<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\SyncJob;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Throwable;

class SystemStatusService
{
    private const SCHEDULER_HEARTBEAT_KEY = 'system_status:scheduler_heartbeat';

    /** @var array<string, string> */
    private const QUEUES = [
        'shopify-webhook' => 'Webhook 处理',
        'shopify-sync' => 'Shopify 数据同步',
        'shopify-analytics' => 'Shopify 分析刷新',
        'default' => '默认任务',
        'notifications' => '通知任务',
    ];

    public function __construct(
        private DeploymentHealthService $health,
        private MasterSupervisorRepository $masterSupervisors,
    ) {}

    /**
     * Return a stable, sanitized status snapshot for the current organization.
     *
     * @return array<string, mixed>
     */
    public function snapshot(User $user, Organization $organization): array
    {
        $checkedAt = now();
        $basicHealth = $this->health->check();
        $services = [
            $this->service('application', '应用服务', 'Laravel 后台与页面请求', 'healthy'),
            $this->service(
                'database',
                '数据库',
                'MySQL 数据读写连接',
                data_get($basicHealth, 'checks.database.status') === 'ok' ? 'healthy' : 'unavailable',
            ),
            $this->service(
                'redis',
                'Redis',
                '缓存、会话与队列连接',
                data_get($basicHealth, 'checks.redis.status') === 'ok' ? 'healthy' : 'unavailable',
            ),
            $this->horizonStatus(),
            $this->schedulerStatus($checkedAt),
            $this->storageStatus(),
        ];

        $queues = collect(self::QUEUES)
            ->map(fn (string $label, string $name): array => $this->queueStatus($name, $label))
            ->values()
            ->all();

        $incidents = $this->incidentSummary($user, $organization, $checkedAt);
        $serviceStates = collect($services)->pluck('status');
        $queueStates = collect($queues)->pluck('status');

        $overallStatus = $serviceStates->contains('unavailable')
            ? 'degraded'
            : ($serviceStates->contains(fn (string $status): bool => in_array($status, ['warning', 'unknown'], true))
                || $queueStates->contains(fn (string $status): bool => in_array($status, ['warning', 'unknown'], true))
                ? 'warning'
                : 'healthy');

        return [
            'summary' => [
                'status' => $overallStatus,
                'checked_at' => $checkedAt->toIso8601String(),
            ],
            'services' => $services,
            'queues' => $queues,
            'incidents' => $incidents,
            'runtime' => [
                'application' => (string) config('app.name'),
                'environment' => app()->environment(),
                'application_version' => config('app.version'),
                'laravel_version' => app()->version(),
                'php_version' => PHP_VERSION,
                'debug_enabled' => (bool) config('app.debug'),
                'timezone' => (string) config('app.timezone'),
            ],
        ];
    }

    public static function recordSchedulerHeartbeat(): void
    {
        Cache::put(self::SCHEDULER_HEARTBEAT_KEY, now()->toIso8601String(), now()->addMinutes(10));
    }

    /** @return array<string, mixed> */
    private function horizonStatus(): array
    {
        try {
            $masters = collect($this->masterSupervisors->all());
            $running = $masters->where('status', 'running')->count();

            return $this->service(
                'horizon',
                'Horizon',
                '后台队列工作进程',
                $masters->isNotEmpty() && $running === $masters->count() ? 'healthy' : 'unavailable',
                ['masters' => $masters->count(), 'running' => $running],
            );
        } catch (Throwable) {
            return $this->service('horizon', 'Horizon', '后台队列工作进程', 'unknown');
        }
    }

    /** @return array<string, mixed> */
    private function schedulerStatus(Carbon $checkedAt): array
    {
        try {
            $heartbeat = Cache::get(self::SCHEDULER_HEARTBEAT_KEY);
            $lastHeartbeat = is_string($heartbeat) ? Carbon::parse($heartbeat) : null;
            $status = ! $lastHeartbeat
                ? 'unknown'
                : ($lastHeartbeat->diffInMinutes($checkedAt) <= 3 ? 'healthy' : 'warning');

            return $this->service(
                'scheduler',
                '任务调度器',
                '定时任务心跳',
                $status,
                ['last_heartbeat_at' => $lastHeartbeat?->toIso8601String()],
            );
        } catch (Throwable) {
            return $this->service('scheduler', '任务调度器', '定时任务心跳', 'unknown');
        }
    }

    /** @return array<string, mixed> */
    private function storageStatus(): array
    {
        try {
            $path = storage_path();
            $freeBytes = disk_free_space($path);

            return $this->service(
                'storage',
                '文件存储',
                '日志与应用文件写入',
                is_writable($path) ? 'healthy' : 'unavailable',
                ['free_bytes' => is_numeric($freeBytes) ? (int) $freeBytes : null],
            );
        } catch (Throwable) {
            return $this->service('storage', '文件存储', '日志与应用文件写入', 'unknown');
        }
    }

    /** @return array<string, mixed> */
    private function queueStatus(string $name, string $label): array
    {
        try {
            $pending = Queue::connection()->size($name);
            $failed = DB::table('failed_jobs')->where('queue', $name)->count();
            $oldestQueuedAt = $this->oldestQueuedAt($name);
            $oldestAgeSeconds = $oldestQueuedAt
                ? max(0, $oldestQueuedAt->diffInSeconds(now()))
                : null;
            $warning = $failed > 0
                || $pending >= 100
                || ($oldestAgeSeconds !== null && $oldestAgeSeconds > 300);

            return [
                'name' => $name,
                'label' => $label,
                'pending' => $pending,
                'failed' => $failed,
                'oldest_queued_at' => $oldestQueuedAt?->toIso8601String(),
                'oldest_age_seconds' => $oldestAgeSeconds,
                'status' => $warning ? 'warning' : 'healthy',
            ];
        } catch (Throwable) {
            return [
                'name' => $name,
                'label' => $label,
                'pending' => null,
                'failed' => null,
                'oldest_queued_at' => null,
                'oldest_age_seconds' => null,
                'status' => 'unknown',
            ];
        }
    }

    private function oldestQueuedAt(string $queue): ?Carbon
    {
        $timestamp = match ($queue) {
            'shopify-sync' => SyncJob::query()
                ->whereIn('status', ['pending', 'queued'])
                ->min('created_at'),
            'shopify-webhook' => WebhookEvent::query()
                ->whereIn('status', ['received', 'queued', 'retrying'])
                ->min('received_at'),
            default => null,
        };

        return $timestamp ? Carbon::parse($timestamp) : null;
    }

    /** @return list<array<string, mixed>> */
    private function incidentSummary(User $user, Organization $organization, Carbon $checkedAt): array
    {
        $since = $checkedAt->copy()->subDay();
        $storeIds = $this->accessibleStoreIds($user, $organization);

        try {
            $failedJobs = DB::table('failed_jobs')->where('failed_at', '>=', $since);
            $failedSyncs = SyncJob::query()
                ->whereBelongsTo($organization)
                ->whereIn('store_id', $storeIds)
                ->where('status', 'failed')
                ->where('failed_at', '>=', $since);
            $failedWebhooks = WebhookEvent::query()
                ->whereBelongsTo($organization)
                ->whereIn('store_id', $storeIds)
                ->where('status', 'failed')
                ->where('received_at', '>=', $since);

            return [
                $this->incident('failed_queue_jobs', '失败队列任务', (clone $failedJobs)->count(), (clone $failedJobs)->max('failed_at')),
                $this->incident('failed_sync_jobs', '失败同步任务', (clone $failedSyncs)->count(), (clone $failedSyncs)->max('failed_at')),
                $this->incident('failed_webhooks', '失败 Webhook 事件', (clone $failedWebhooks)->count(), (clone $failedWebhooks)->max('received_at')),
            ];
        } catch (Throwable) {
            return [
                $this->incident('failed_queue_jobs', '失败队列任务', null, null),
                $this->incident('failed_sync_jobs', '失败同步任务', null, null),
                $this->incident('failed_webhooks', '失败 Webhook 事件', null, null),
            ];
        }
    }

    /** @return list<int> */
    private function accessibleStoreIds(User $user, Organization $organization): array
    {
        return $user->isSuperAdmin()
            ? $organization->stores()->pluck('id')->all()
            : $user->stores()
                ->where('stores.organization_id', $organization->getKey())
                ->pluck('stores.id')
                ->all();
    }

    /** @return array<string, mixed> */
    private function service(
        string $key,
        string $name,
        string $description,
        string $status,
        array $metadata = [],
    ): array {
        return compact('key', 'name', 'description', 'status', 'metadata');
    }

    /** @return array<string, mixed> */
    private function incident(string $key, string $label, ?int $count, mixed $latestAt): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'count' => $count,
            'latest_at' => $latestAt ? Carbon::parse($latestAt)->toIso8601String() : null,
            'status' => $count === null ? 'unknown' : ($count > 0 ? 'warning' : 'healthy'),
        ];
    }
}
