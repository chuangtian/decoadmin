<?php

namespace App\Services\AppCenter;

use App\Contracts\AppConfigurationProvider;
use App\Models\AppInstallation;
use App\Models\User;
use App\Services\Marketing\MarketingModuleCatalog;
use App\Services\StoreBusinessCredentialService;

class MarketingAppConfigurationProvider implements AppConfigurationProvider
{
    public function __construct(
        private MarketingModuleCatalog $modules,
        private StoreBusinessCredentialService $credentials,
    ) {}

    public function supports(AppInstallation $installation): bool
    {
        return $installation->app?->handle === (string) config('shopify.app_handle');
    }

    public function present(AppInstallation $installation, User $user): array
    {
        $store = $installation->store;
        $modules = collect($this->modules->forInstallation($installation));
        $enabled = $modules->where('enabled', true)->count();
        $configured = $modules->where('configured', true)->count();
        $credentialProviders = collect($this->credentials->catalogForFrontend($store))
            ->whereIn('key', ['email_marketing', 'sms_marketing']);
        $configuredCredentials = $credentialProviders->where('configured', true)->count();

        $configurationStatus = match (true) {
            $installation->status !== 'active' => 'unavailable',
            $configured === $modules->count() && $configuredCredentials === $credentialProviders->count() => 'configured',
            $configured > 0 || $configuredCredentials > 0 => 'partial',
            default => 'pending',
        };

        return [
            'category' => '营销与店铺运营',
            'description' => '管理个性化、营销邮件、短信、弹窗、评论和页面构建器。',
            'configuration_status' => $configurationStatus,
            'configuration_status_label' => match ($configurationStatus) {
                'configured' => '已配置',
                'partial' => '部分配置',
                'unavailable' => '当前不可用',
                default => '待配置',
            },
            'management_url' => $installation->status === 'active'
                ? route('stores.marketing.home', $store, false)
                : null,
            'action_label' => '管理营销模块',
            'unavailable_reason' => $installation->status === 'active' ? null : '应用安装当前不是启用状态。',
            'metrics' => [
                ['label' => '已启用模块', 'value' => "{$enabled}/{$modules->count()}"],
                ['label' => '已完成模块', 'value' => "{$configured}/{$modules->count()}"],
                ['label' => '发送凭证', 'value' => "{$configuredCredentials}/{$credentialProviders->count()}"],
            ],
        ];
    }
}
