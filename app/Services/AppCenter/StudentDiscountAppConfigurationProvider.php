<?php

namespace App\Services\AppCenter;

use App\Contracts\AppConfigurationProvider;
use App\Models\AppInstallation;
use App\Models\StudentDiscountCampaign;
use App\Models\StudentDiscountClaim;
use App\Models\User;

class StudentDiscountAppConfigurationProvider implements AppConfigurationProvider
{
    public function supports(AppInstallation $installation): bool
    {
        return data_get($installation->app?->settings, 'managed_by') === 'student_discount_config'
            || str_starts_with((string) $installation->app?->handle, 'deco-student-discount');
    }

    public function present(AppInstallation $installation, User $user): array
    {
        $store = $installation->store;
        $organization = $store->organization;
        $canRead = $user->hasPermission('student_discount.claim.read', $organization, $store);
        $canReadAnalytics = $user->hasPermission('student_discount.analytics.read', $organization, $store);
        $campaign = $canRead
            ? StudentDiscountCampaign::query()
                ->where('organization_id', $organization->id)
                ->where('store_id', $store->id)
                ->first(['id', 'enabled'])
            : null;
        $pendingClaims = $canReadAnalytics
            ? StudentDiscountClaim::query()
                ->where('organization_id', $organization->id)
                ->where('store_id', $store->id)
                ->where('status', 'pending')
                ->count()
            : null;

        $configurationStatus = match (true) {
            $installation->status !== 'active' => 'unavailable',
            ! $canRead => 'restricted',
            ! $campaign => 'pending',
            $campaign->enabled => 'configured',
            default => 'disabled',
        };

        return [
            'category' => '优惠与身份审核',
            'description' => '管理学生优惠活动、教育邮箱规则、学生证审核和 Shopify 优惠码。',
            'configuration_status' => $configurationStatus,
            'configuration_status_label' => match ($configurationStatus) {
                'configured' => '活动运行中',
                'disabled' => '活动已停用',
                'restricted' => '权限不足',
                'unavailable' => '当前不可用',
                default => '待配置',
            },
            'management_url' => $installation->status === 'active' && $canRead
                ? route('student-discounts.index', [$organization, $store], false)
                : null,
            'action_label' => '管理学生优惠',
            'unavailable_reason' => match (true) {
                $installation->status !== 'active' => '应用安装当前不是启用状态。',
                ! $canRead => '当前账号缺少学生优惠查看权限。',
                default => null,
            },
            'metrics' => array_values(array_filter([
                ! $canRead ? null : ['label' => '活动状态', 'value' => $campaign?->enabled ? '已启用' : '未启用'],
                $pendingClaims === null ? null : ['label' => '待审核申请', 'value' => (string) $pendingClaims],
                ['label' => '配置范围', 'value' => '当前店铺'],
            ])),
        ];
    }
}
