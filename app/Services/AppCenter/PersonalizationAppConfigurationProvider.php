<?php

namespace App\Services\AppCenter;

use App\Contracts\AppConfigurationProvider;
use App\Models\AppInstallation;
use App\Models\User;

class PersonalizationAppConfigurationProvider implements AppConfigurationProvider
{
    public function supports(AppInstallation $installation): bool
    {
        return data_get($installation->app?->settings, 'managed_by') === 'personalization_config'
            || str_starts_with((string) $installation->app?->handle, 'deco-personalization');
    }

    public function present(AppInstallation $installation, User $user): array
    {
        $store = $installation->store;
        $organization = $store->organization;
        $canRead = $user->hasPermission('personalization.view', $organization, $store);
        $status = match (true) {
            $installation->status !== 'active' => 'unavailable',
            ! $canRead => 'restricted',
            default => 'pending',
        };

        return [
            'category' => '个性化与推荐',
            'description' => '管理店铺推荐策略、展示组件、Smart Cart 和归因分析。',
            'configuration_status' => $status,
            'configuration_status_label' => match ($status) {
                'restricted' => '权限不足',
                'unavailable' => '当前不可用',
                default => '待配置',
            },
            'management_url' => $installation->status === 'active' && $canRead
                ? route('personalization.shopify-app.management', ['shop' => $store->shopify_domain], false)
                : null,
            'action_label' => '管理个性化推荐',
            'unavailable_reason' => match (true) {
                $installation->status !== 'active' => '应用安装当前不是启用状态。',
                ! $canRead => '当前账号缺少个性化推荐查看权限。',
                default => null,
            },
            'metrics' => [
                ['label' => '配置状态', 'value' => '待配置'],
                ['label' => '配置范围', 'value' => '当前店铺'],
            ],
        ];
    }
}
