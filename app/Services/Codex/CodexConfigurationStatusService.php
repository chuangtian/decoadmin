<?php

namespace App\Services\Codex;

use App\Models\Store;
use App\Models\StoreBusinessCredential;
use App\Services\StoreNotificationSettingsService;
use App\Services\SystemSettingsService;

class CodexConfigurationStatusService
{
    public function __construct(
        private SystemSettingsService $systemSettings,
        private StoreNotificationSettingsService $storeNotifications,
    ) {}

    /** @return array<string, mixed> */
    public function forStore(Store $store): array
    {
        $mail = $this->systemSettings->sectionForFrontend('mail', false);
        $feishu = $this->systemSettings->sectionForFrontend('feishu', false);
        $studentAi = $this->systemSettings->sectionForFrontend('student_ai', false);
        $storeMail = $this->storeNotifications->mailForFrontend($store, false);
        $storeFeishu = $this->storeNotifications->feishuForFrontend($store, false);
        $credentials = StoreBusinessCredential::query()
            ->where('organization_id', $store->organization_id)
            ->where('store_id', $store->getKey())
            ->selectRaw('provider, COUNT(*) as configured_items')
            ->groupBy('provider')
            ->orderBy('provider')
            ->get()
            ->map(fn (StoreBusinessCredential $credential): array => [
                'provider' => $credential->provider,
                'configured_items' => (int) $credential->configured_items,
            ])->values()->all();

        return [
            'store_id' => $store->getKey(),
            'shopify' => [
                'connected' => in_array($store->shopifyConnection?->status, ['active', 'connected'], true),
                'status' => $store->shopifyConnection?->status ?? 'disconnected',
            ],
            'system' => [
                'mail' => $this->status([
                    'enabled' => (bool) ($mail['enabled'] ?? false),
                    'host' => filled($mail['host'] ?? null),
                    'port' => (int) ($mail['port'] ?? 0) > 0,
                    'from_address' => filled($mail['from_address'] ?? null),
                    'password' => (bool) ($mail['password_configured'] ?? false),
                ]),
                'feishu' => $this->status([
                    'enabled' => (bool) ($feishu['enabled'] ?? false),
                    'app_id' => filled($feishu['app_id'] ?? null),
                    'app_secret' => (bool) ($feishu['app_secret_configured'] ?? false),
                ]),
                'student_ai' => $this->status([
                    'model' => filled($studentAi['gemini_model'] ?? null),
                    'api_key' => (bool) ($studentAi['gemini_api_key_configured'] ?? false),
                ]),
            ],
            'store_notifications' => [
                'mail' => $this->status([
                    'enabled' => (bool) ($storeMail['mail_enabled'] ?? false),
                    'host' => filled($storeMail['mail_host'] ?? null),
                    'from_address' => filled($storeMail['mail_from_address'] ?? null),
                    'recipient' => filled($storeMail['notification_email'] ?? null),
                    'password' => (bool) ($storeMail['mail_password_configured'] ?? false),
                ]),
                'feishu' => $this->status([
                    'enabled' => (bool) ($storeFeishu['feishu_enabled'] ?? false),
                    'webhook' => (bool) ($storeFeishu['feishu_webhook_configured'] ?? false),
                ]),
            ],
            'business_credentials' => $credentials,
            'student_discount' => [
                'configured' => $store->studentDiscountCampaign()->exists(),
                'enabled' => (bool) $store->studentDiscountCampaign()->value('enabled'),
            ],
            'checked_at' => now()->toIso8601String(),
        ];
    }

    /** @param array<string, bool> $checks
     * @return array{configured: bool, checks: array<string, bool>, missing: list<string>}
     */
    private function status(array $checks): array
    {
        $missing = collect($checks)->filter(fn (bool $configured): bool => ! $configured)->keys()->values()->all();

        return ['configured' => $missing === [], 'checks' => $checks, 'missing' => $missing];
    }
}
