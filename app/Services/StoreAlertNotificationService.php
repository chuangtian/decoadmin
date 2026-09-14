<?php

namespace App\Services;

use App\Models\ShopifyProductMonitor;
use App\Models\StoreAlert;
use App\Models\StoreNotificationSetting;
use App\Models\SyncJob;
use App\Support\SafeDiagnosticMessage;
use App\Support\StoreAlertPresentation;
use App\Support\StoreDateTime;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

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
        $statuses = data_get($context, 'notification_channel_statuses', []);
        $statuses = is_array($statuses) ? $statuses : [];
        $channels = collect($statuses)->filter(fn ($status): bool => $status === 'sent')->keys()->all();
        $errors = [];

        if ($recipientIds !== [] && ($statuses['in_app'] ?? null) !== 'sent') {
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
            $channels[] = 'in_app';
            $statuses['in_app'] = 'sent';
        }

        if ($this->shouldSendFeishu($settings, $alert) && ($statuses['feishu'] ?? null) !== 'sent') {
            try {
                $this->sendFeishu($settings, $alert);
                $channels[] = 'feishu';
                $statuses['feishu'] = 'sent';
            } catch (Throwable $exception) {
                $errors[] = 'feishu: '.$this->safeError($exception);
                $statuses['feishu'] = 'failed';
            }
        }

        $channels = array_values(array_unique($channels));

        $alert->update([
            'delivery_status' => $errors === [] ? ($channels === [] ? 'skipped' : 'sent') : 'failed',
            'delivery_error' => $errors === [] ? null : implode('; ', $errors),
            'notified_at' => $channels === [] ? null : now(),
            'context' => [...$context, 'notification_channels' => $channels, 'notification_channel_statuses' => $statuses],
        ]);

        if ($errors !== []) {
            throw new RuntimeException('店铺异常通知发送失败。');
        }
    }

    private function shouldSendFeishu(?StoreNotificationSetting $settings, StoreAlert $alert): bool
    {
        return $settings !== null
            && in_array($alert->type, ['discount', 'product'], true)
            && $settings->feishu_enabled
            && filled($settings->feishu_webhook_url);
    }

    private function sendFeishu(StoreNotificationSetting $settings, StoreAlert $alert): void
    {
        $payload = ['msg_type' => 'text', 'content' => ['text' => $this->feishuMessage($alert)]];
        if (filled($settings->feishu_secret)) {
            $timestamp = (string) now()->timestamp;
            $payload['timestamp'] = $timestamp;
            $payload['sign'] = base64_encode(hash_hmac('sha256', '', $timestamp."\n".$settings->feishu_secret, true));
        }
        $response = Http::asJson()->timeout(10)->post($settings->feishu_webhook_url, $payload);
        if (! $response->successful() || (int) ($response->json('code') ?? 0) !== 0) {
            throw new RuntimeException('飞书机器人返回失败状态。');
        }
    }

    private function feishuMessage(StoreAlert $alert): string
    {
        return implode("\n", [
            'DecoAdmin 店铺异常告警',
            "店铺：{$alert->store->name}",
            "类型：{$alert->type}",
            "级别：{$alert->severity}",
            '标题：'.StoreAlertPresentation::title($alert),
            '说明：'.SafeDiagnosticMessage::sanitize($alert->message),
            '店铺时间：'.StoreDateTime::format($alert->occurred_at, $alert->store),
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

    private function safeError(Throwable $exception): string
    {
        return mb_substr(preg_replace('/\b(?:shp\w+_|https:\/\/[^\s]+)[^\s]*/i', '[redacted]', $exception->getMessage()) ?: '发送失败', 0, 500);
    }
}
