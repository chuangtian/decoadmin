<?php

namespace App\Services;

use App\Models\ShopifyProductMonitor;
use App\Models\StoreAlert;
use App\Models\StoreNotificationSetting;
use App\Models\SyncJob;
use App\Support\SafeDiagnosticMessage;
use App\Support\StoreAlertPresentation;
use App\Support\StoreDateTime;

class StoreAlertNotificationService
{
    public function __construct(private BusinessNotificationService $notifications) {}

    public function deliver(StoreAlert $alert): void
    {
        if ($alert->type === 'sync' && $alert->source_type === SyncJob::class
            && str_starts_with((string) data_get($alert->context, 'sync_type'), 'advertising_channel:')
            && SyncJob::query()->whereKey($alert->source_id)->where('organization_id', $alert->organization_id)
                ->where('store_id', $alert->store_id)->where('status', 'completed')->exists()) {
            $alert->update(['delivery_status' => 'skipped', 'delivery_error' => null]);

            return;
        }
        if ($alert->type === 'product' && ! ShopifyProductMonitor::whereKey($alert->source_id)
            ->where('organization_id', $alert->organization_id)->where('store_id', $alert->store_id)
            ->where('is_enabled', true)->where('generation', data_get($alert->context, 'generation'))->exists()) {
            $alert->update(['delivery_status' => 'skipped', 'delivery_error' => null]);

            return;
        }
        $alert->loadMissing(['store.organization', 'store.notificationSetting']);
        $settings = $alert->store->notificationSetting;
        $alert->increment('delivery_attempts');
        $alert->forceFill(['last_delivery_at' => now()])->save();
        if ($settings && ! $this->enabledForType($settings, $alert->type)) {
            $alert->update(['delivery_status' => 'skipped', 'delivery_error' => null]);

            return;
        }

        $organization = $alert->store->organization;
        $recipientIds = $this->notifications->usersWithPermission(
            $organization,
            'alerts.view',
            ['developer', 'super-admin'],
            $alert->store,
        );
        $context = $alert->context ?? [];
        $channels = $recipientIds === [] ? [] : ['in_app'];
        $statuses = $recipientIds === [] ? [] : ['in_app' => 'sent'];

        $this->notifications->notify(
            $organization,
            $recipientIds,
            null,
            'store_alert',
            $alert->store->name.' · '.StoreAlertPresentation::title($alert),
            $this->notificationMessage($alert),
            route('store-alerts.open', $alert, false),
            $alert,
            "store-alert:{$alert->id}:attempt:{$alert->delivery_attempts}",
        );

        $alert->update([
            'delivery_status' => $channels === [] ? 'skipped' : 'sent',
            'delivery_error' => null,
            'notified_at' => $channels === [] ? null : now(),
            'context' => [...$context, 'notification_channels' => $channels, 'notification_channel_statuses' => $statuses],
        ]);
    }

    private function notificationMessage(StoreAlert $alert): string
    {
        $message = SafeDiagnosticMessage::sanitize($alert->message);
        $severity = match ($alert->severity) {
            'critical' => '严重',
            'error' => '错误',
            'warning' => '警告',
            default => $alert->severity,
        };

        return implode("\n", ["{$severity} · {$message}", '店铺时间：'.StoreDateTime::format($alert->occurred_at, $alert->store)]);
    }

    private function enabledForType(StoreNotificationSetting $settings, string $type): bool
    {
        return match ($type) {
            'sync' => $settings->notify_sync_failed,
            'webhook' => $settings->notify_webhook_failed,
            'connection' => $settings->notify_connection_unhealthy,
            'discount' => $settings->notify_discount_monitor,
            'product' => $settings->notify_product_monitor,
            default => false,
        };
    }
}
