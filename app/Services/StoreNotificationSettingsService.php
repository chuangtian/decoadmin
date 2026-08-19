<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Store;
use App\Models\StoreNotificationSetting;
use App\Models\User;
use Illuminate\Support\Arr;

class StoreNotificationSettingsService
{
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
            'notify_webhook_failed', 'notify_connection_unhealthy',
        ]);
    }

    public function update(Store $store, array $values, User $actor): StoreNotificationSetting
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
            'user_id' => $actor->id, 'action' => 'store_notification_settings_updated',
            'subject_type' => Store::class, 'subject_id' => $store->id,
            'old_values' => $before, 'new_values' => $this->safeSnapshot($setting),
            'metadata' => ['scope' => 'store', 'changed_keys' => array_keys($setting->getChanges())],
        ]);

        return $setting;
    }

    private function defaults(): array
    {
        return ['mail_enabled' => false, 'mail_host' => '', 'mail_port' => 587, 'mail_encryption' => 'tls', 'mail_username' => '', 'mail_from_address' => '', 'mail_from_name' => '', 'mail_recipients' => [], 'feishu_enabled' => false, 'notify_sync_failed' => true, 'notify_webhook_failed' => true, 'notify_connection_unhealthy' => true];
    }

    private function safeSnapshot(StoreNotificationSetting $setting): array
    {
        return Arr::only($setting->attributesToArray(), ['mail_enabled', 'mail_host', 'mail_port', 'mail_encryption', 'mail_username', 'mail_from_address', 'mail_from_name', 'mail_recipients', 'feishu_enabled', 'notify_sync_failed', 'notify_webhook_failed', 'notify_connection_unhealthy']);
    }
}
