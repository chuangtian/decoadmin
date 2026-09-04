<?php

namespace App\Services;

use App\Models\StoreAlert;
use App\Models\StoreNotificationSetting;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class StoreAlertNotificationService
{
    public function __construct(private MailManager $mail) {}

    public function deliver(StoreAlert $alert): void
    {
        $alert->loadMissing('store.notificationSetting');
        $settings = $alert->store->notificationSetting;
        $alert->increment('delivery_attempts');
        $alert->forceFill(['last_delivery_at' => now()])->save();
        if (! $settings || ! $this->enabledForType($settings, $alert->type)) {
            $alert->update(['delivery_status' => 'skipped', 'delivery_error' => null]);

            return;
        }
        $context = $alert->context ?? [];
        $statuses = data_get($context, 'notification_channel_statuses', []);
        $channels = collect($statuses)->filter(fn (string $status): bool => $status === 'sent')->keys()->all();
        $errors = [];
        if ($settings->mail_enabled && $settings->mail_recipients !== [] && ($statuses['mail'] ?? null) !== 'sent') {
            try {
                $this->sendMail($settings, $alert);
                $channels[] = 'mail';
                $statuses['mail'] = 'sent';
            } catch (Throwable $exception) {
                $errors[] = 'mail: '.$this->safeError($exception);
                $statuses['mail'] = 'failed';
            }
        }
        if ($settings->feishu_enabled && filled($settings->feishu_webhook_url) && ($statuses['feishu'] ?? null) !== 'sent') {
            try {
                $this->sendFeishu($settings, $alert);
                $channels[] = 'feishu';
                $statuses['feishu'] = 'sent';
            } catch (Throwable $exception) {
                $errors[] = 'feishu: '.$this->safeError($exception);
                $statuses['feishu'] = 'failed';
            }
        }
        $alert->update([
            'delivery_status' => $errors === [] ? ($channels === [] ? 'skipped' : 'sent') : 'failed',
            'delivery_error' => $errors === [] ? null : implode('; ', $errors),
            'notified_at' => $channels === [] ? null : now(),
            'context' => [...$context, 'notification_channels' => array_values(array_unique($channels)), 'notification_channel_statuses' => $statuses],
        ]);

        if ($errors !== []) {
            throw new RuntimeException('店铺异常通知发送失败。');
        }
    }

    private function sendMail(StoreNotificationSetting $settings, StoreAlert $alert): void
    {
        $mailer = $this->mail->build(['transport' => 'smtp', 'scheme' => $settings->mail_encryption === 'ssl' ? 'smtps' : null, 'host' => $settings->mail_host, 'port' => $settings->mail_port, 'username' => $settings->mail_username ?: null, 'password' => $settings->mail_password ?: null, 'timeout' => 10]);
        $mailer->raw($this->plainText($alert), function ($message) use ($settings, $alert): void {
            $message->to($settings->mail_recipients)->from($settings->mail_from_address, $settings->mail_from_name ?: $alert->store->name)->subject("[{$alert->store->name}] {$alert->title}");
        });
    }

    private function sendFeishu(StoreNotificationSetting $settings, StoreAlert $alert): void
    {
        $payload = ['msg_type' => 'text', 'content' => ['text' => $this->plainText($alert)]];
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

    private function plainText(StoreAlert $alert): string
    {
        return implode("\n", ['DecoAdmin 店铺异常告警', "店铺：{$alert->store->name}", "类型：{$alert->type}", "级别：{$alert->severity}", "标题：{$alert->title}", "说明：{$alert->message}", '时间：'.$alert->occurred_at?->toIso8601String()]);
    }

    private function enabledForType(StoreNotificationSetting $settings, string $type): bool
    {
        return match ($type) {
            'sync' => $settings->notify_sync_failed,
            'webhook' => $settings->notify_webhook_failed,
            'connection' => $settings->notify_connection_unhealthy,
            'discount' => $settings->notify_discount_monitor,
            default => false,
        };
    }

    private function safeError(Throwable $exception): string
    {
        return mb_substr(preg_replace('/\b(?:shp\w+_|https:\/\/[^\s]+)[^\s]*/i', '[redacted]', $exception->getMessage()) ?: '发送失败', 0, 500);
    }
}
