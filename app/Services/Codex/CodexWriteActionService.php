<?php

namespace App\Services\Codex;

use App\Models\AppInstallation;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Store;
use App\Models\SyncJob;
use App\Models\User;
use App\Services\AnalyticsCacheVersionService;
use App\Services\StoreNotificationSettingsService;
use App\Services\StudentDiscount\StudentDiscountCampaignService;
use App\Services\Sync\SyncJobService;

class CodexWriteActionService
{
    public function __construct(
        private AnalyticsCacheVersionService $analyticsCache,
        private SyncJobService $syncJobs,
        private StoreNotificationSettingsService $notifications,
        private StudentDiscountCampaignService $studentDiscounts,
    ) {}

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function execute(string $action, User $user, Organization $organization, Store $store, array $payload): array
    {
        return match ($action) {
            'refresh_analytics' => $this->refreshAnalytics($user, $organization, $store),
            'start_sync' => $this->startSync($user, $organization, $store, $payload),
            'retry_sync' => $this->retrySync($user, $organization, $store, $payload),
            'update_store_notifications' => $this->updateStoreNotifications($user, $store, $payload),
            'set_student_discount_enabled' => $this->setStudentDiscountEnabled($user, $organization, $store, $payload),
            default => abort(422, '不支持的 Codex 写操作。'),
        };
    }

    /** @return array<string, mixed> */
    private function refreshAnalytics(User $user, Organization $organization, Store $store): array
    {
        $previousVersion = $this->analyticsCache->current((int) $store->getKey());
        $version = $this->analyticsCache->bump((int) $store->getKey());
        AuditLog::query()->create([
            'organization_id' => $organization->getKey(),
            'store_id' => $store->getKey(),
            'user_id' => $user->getKey(),
            'action' => 'codex_analytics_refreshed',
            'subject_type' => Store::class,
            'subject_id' => $store->getKey(),
            'old_values' => ['cache_version' => $previousVersion],
            'new_values' => ['cache_version' => $version],
            'metadata' => ['source' => 'codex_plugin'],
        ]);

        return [
            'action' => 'refresh_analytics',
            'store_id' => $store->getKey(),
            'cache_version' => $version,
            'refreshed_at' => now()->toIso8601String(),
        ];
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function startSync(User $user, Organization $organization, Store $store, array $payload): array
    {
        $installation = filled($payload['app_installation_id'] ?? null)
            ? AppInstallation::query()
                ->where('store_id', $store->getKey())
                ->where('status', 'active')
                ->findOrFail((int) $payload['app_installation_id'])
            : null;
        $job = $this->syncJobs->createAndDispatch(
            $store,
            (string) $payload['type'],
            $user,
            $installation,
            (string) ($payload['mode'] ?? 'incremental'),
        );
        AuditLog::query()->create([
            'organization_id' => $organization->getKey(),
            'store_id' => $store->getKey(),
            'user_id' => $user->getKey(),
            'action' => 'codex_sync_started',
            'subject_type' => SyncJob::class,
            'subject_id' => $job->getKey(),
            'metadata' => [
                'source' => 'codex_plugin',
                'sync_type' => $job->type,
                'mode' => $job->mode,
                'job_uuid' => $job->uuid,
            ],
        ]);

        return $this->syncResult($job, 'start_sync');
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function retrySync(User $user, Organization $organization, Store $store, array $payload): array
    {
        $failedJob = SyncJob::query()
            ->where('organization_id', $organization->getKey())
            ->where('store_id', $store->getKey())
            ->findOrFail((int) $payload['sync_job_id']);
        $job = $this->syncJobs->retryAndDispatch($failedJob, $user);

        return $this->syncResult($job, 'retry_sync');
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function updateStoreNotifications(User $user, Store $store, array $payload): array
    {
        $flags = collect($payload)->only([
            'notify_sync_failed', 'notify_webhook_failed', 'notify_connection_unhealthy',
        ])->all();
        if ($flags !== []) {
            $this->notifications->update($store, $flags, $user);
        }
        if (array_key_exists('mail_enabled', $payload)) {
            $this->notifications->toggleMail($store, (bool) $payload['mail_enabled'], $user);
        }
        if (array_key_exists('feishu_enabled', $payload)) {
            $this->notifications->toggleFeishu($store, (bool) $payload['feishu_enabled'], $user);
        }
        $settings = $this->notifications->forFrontend($store->fresh(), false);

        return [
            'action' => 'update_store_notifications',
            'store_id' => $store->getKey(),
            'settings' => [
                'mail_enabled' => (bool) $settings['mail_enabled'],
                'feishu_enabled' => (bool) $settings['feishu_enabled'],
                'notify_sync_failed' => (bool) $settings['notify_sync_failed'],
                'notify_webhook_failed' => (bool) $settings['notify_webhook_failed'],
                'notify_connection_unhealthy' => (bool) $settings['notify_connection_unhealthy'],
            ],
            'updated_at' => now()->toIso8601String(),
        ];
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function setStudentDiscountEnabled(User $user, Organization $organization, Store $store, array $payload): array
    {
        $campaign = $this->studentDiscounts->setEnabled(
            $organization,
            $store,
            (bool) $payload['enabled'],
            $user,
        );

        return [
            'action' => 'set_student_discount_enabled',
            'store_id' => $store->getKey(),
            'enabled' => (bool) $campaign->enabled,
            'updated_at' => $campaign->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function syncResult(SyncJob $job, string $action): array
    {
        return [
            'action' => $action,
            'sync_job' => [
                'id' => $job->getKey(),
                'uuid' => $job->uuid,
                'type' => $job->type,
                'mode' => $job->mode,
                'status' => $job->status,
                'created_at' => $job->created_at?->toIso8601String(),
            ],
        ];
    }
}
