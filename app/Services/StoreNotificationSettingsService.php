<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Store;
use App\Models\StoreNotificationSetting;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class StoreNotificationSettingsService
{
    public function mailForFrontend(Store $store, bool $includeSecrets): array
    {
        $values = $this->forFrontend($store, $includeSecrets);

        return Arr::only([
            ...$values,
            'notification_email' => $values['mail_recipients'][0] ?? '',
        ], [
            'mail_enabled', 'mail_host', 'mail_port', 'mail_encryption', 'mail_username',
            'mail_password', 'mail_password_configured', 'mail_from_address', 'mail_from_name',
            'notification_email',
        ]);
    }

    public function feishuForFrontend(Store $store, bool $includeSecrets): array
    {
        return Arr::only($this->forFrontend($store, $includeSecrets), [
            'feishu_enabled', 'feishu_webhook_url', 'feishu_webhook_configured',
            'feishu_secret', 'feishu_secret_configured',
        ]);
    }

    public function feishuTableForFrontend(Store $store, bool $includeSecrets): array
    {
        $appId = (string) config('services.feishu_table.app_id', '');
        $appSecret = (string) config('services.feishu_table.app_secret', '');

        return [
            'feishu_table_app_id' => $appId,
            'feishu_table_app_secret' => $includeSecrets ? $appSecret : '',
            'feishu_table_app_secret_configured' => filled($appSecret),
            'feishu_table_configured' => filled($appId) && filled($appSecret),
        ];
    }

    public function forFrontend(Store $store, bool $includeSecrets): array
    {
        $setting = $store->notificationSetting;
        $values = $setting ? $setting->attributesToArray() : $this->defaults();
        $values['mail_password'] = $includeSecrets ? ($setting?->mail_password ?? '') : '';
        $values['feishu_webhook_url'] = $includeSecrets ? ($setting?->feishu_webhook_url ?? '') : '';
        $values['feishu_secret'] = $includeSecrets ? ($setting?->feishu_secret ?? '') : '';
        $values['mail_password_configured'] = filled($setting?->mail_password);
        $values['feishu_webhook_configured'] = filled($setting?->feishu_webhook_url);
        $values['feishu_secret_configured'] = filled($setting?->feishu_secret);

        return Arr::only($values, [
            'mail_enabled', 'mail_host', 'mail_port', 'mail_encryption', 'mail_username',
            'mail_password', 'mail_password_configured', 'mail_from_address', 'mail_from_name',
            'mail_recipients', 'feishu_enabled', 'feishu_webhook_url', 'feishu_webhook_configured',
            'feishu_secret', 'feishu_secret_configured', 'notify_sync_failed',
            'notify_webhook_failed', 'notify_connection_unhealthy', 'notify_discount_monitor', 'notify_product_monitor',
        ]);
    }

    public function update(Store $store, array $values, User $actor): StoreNotificationSetting
    {
        return $this->persist($store, $values, $actor, 'store_notification_settings_updated');
    }

    public function updateMail(Store $store, array $values, User $actor): StoreNotificationSetting
    {
        $notificationEmail = trim((string) ($values['notification_email'] ?? ''));
        unset($values['notification_email']);
        $values['mail_recipients'] = $notificationEmail === '' ? [] : [$notificationEmail];

        return $this->persist($store, $values, $actor, 'store_mail_settings_updated');
    }

    public function updateFeishu(Store $store, array $values, User $actor): StoreNotificationSetting
    {
        return $this->persist($store, $values, $actor, 'store_feishu_settings_updated');
    }

    public function toggleMail(Store $store, bool $enabled, User $actor): StoreNotificationSetting
    {
        $setting = $store->notificationSetting;
        if ($enabled && (! $setting || blank($setting->mail_host) || blank($setting->mail_password) || blank($setting->mail_from_address) || $setting->mail_recipients === [])) {
            throw ValidationException::withMessages(['enabled' => '请先完整配置 SMTP、邮箱密码、发件邮箱和通知邮箱。']);
        }

        return $this->persist($store, ['mail_enabled' => $enabled], $actor, 'store_mail_channel_toggled');
    }

    public function toggleFeishu(Store $store, bool $enabled, User $actor): StoreNotificationSetting
    {
        $setting = $store->notificationSetting;
        if ($enabled && (! $setting || blank($setting->feishu_webhook_url))) {
            throw ValidationException::withMessages(['enabled' => '请先配置飞书机器人 Webhook 地址。']);
        }

        return $this->persist($store, ['feishu_enabled' => $enabled], $actor, 'store_feishu_channel_toggled');
    }

    private function persist(Store $store, array $values, User $actor, string $action): StoreNotificationSetting
    {
        $setting = $store->notificationSetting()->firstOrNew(['organization_id' => $store->organization_id]);
        $before = $setting->exists ? $this->safeSnapshot($setting) : [];
        foreach (['mail_password', 'feishu_webhook_url', 'feishu_secret'] as $secret) {
            if (blank($values[$secret] ?? null)) {
                unset($values[$secret]);
            }
        }
        $setting->fill([...$values, 'organization_id' => $store->organization_id, 'updated_by' => $actor->id]);
        $setting->save();
        AuditLog::query()->create([
            'organization_id' => $store->organization_id, 'store_id' => $store->id,
            'user_id' => $actor->id, 'action' => $action,
            'subject_type' => Store::class, 'subject_id' => $store->id,
            'old_values' => $before, 'new_values' => $this->safeSnapshot($setting),
            'metadata' => ['scope' => 'store', 'changed_keys' => array_keys($setting->getChanges())],
        ]);

        return $setting;
    }

    private function defaults(): array
    {
        return ['mail_enabled' => false, 'mail_host' => '', 'mail_port' => 587, 'mail_encryption' => 'tls', 'mail_username' => '', 'mail_from_address' => '', 'mail_from_name' => '', 'mail_recipients' => [], 'feishu_enabled' => false, 'notify_sync_failed' => true, 'notify_webhook_failed' => true, 'notify_connection_unhealthy' => true, 'notify_discount_monitor' => true, 'notify_product_monitor' => true];
    }

    private function safeSnapshot(StoreNotificationSetting $setting): array
    {
        return Arr::only($setting->attributesToArray(), ['mail_enabled', 'mail_host', 'mail_port', 'mail_encryption', 'mail_username', 'mail_from_address', 'mail_from_name', 'mail_recipients', 'feishu_enabled', 'notify_sync_failed', 'notify_webhook_failed', 'notify_connection_unhealthy', 'notify_discount_monitor', 'notify_product_monitor']);
    }
}
