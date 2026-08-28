<?php

namespace App\Services\AppCenter;

use App\Contracts\AppConfigurationProvider;
use App\Models\AppInstallation;
use App\Models\User;

class GenericAppConfigurationProvider implements AppConfigurationProvider
{
    public function supports(AppInstallation $installation): bool
    {
        return true;
    }

    public function present(AppInstallation $installation, User $user): array
    {
        return [
            'category' => 'Shopify 应用',
            'description' => $installation->app?->description ?: '该应用尚未提供独立的店铺配置工作台。',
            'configuration_status' => 'unsupported',
            'configuration_status_label' => '暂无配置项',
            'management_url' => route('apps.show', $installation->app, false),
            'action_label' => '查看应用详情',
            'unavailable_reason' => null,
            'metrics' => [
                ['label' => '安装状态', 'value' => $installation->status === 'active' ? '已启用' : $installation->status],
                ['label' => '配置入口', 'value' => '待应用接入'],
            ],
        ];
    }
}
