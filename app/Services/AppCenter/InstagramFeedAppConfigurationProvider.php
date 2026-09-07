<?php

namespace App\Services\AppCenter;

use App\Contracts\AppConfigurationProvider;
use App\Models\AppInstallation;
use App\Models\InstagramFeedInstallation;
use App\Models\InstagramGallery;
use App\Models\InstagramMedia;
use App\Models\User;

/**
 * Instagram Feed 在「应用配置」页的卡片。
 *
 * 必须注册在 GenericAppConfigurationProvider 之前，否则会被兜底 provider 抢走，
 * 卡片就只能显示「查看应用详情」而进不到 Instagram Feed 工作台。
 */
class InstagramFeedAppConfigurationProvider implements AppConfigurationProvider
{
    public function supports(AppInstallation $installation): bool
    {
        return data_get($installation->app?->settings, 'managed_by') === 'instagram_feed_config'
            || str_starts_with((string) $installation->app?->handle, 'deco-instagram-feed');
    }

    public function present(AppInstallation $installation, User $user): array
    {
        $store = $installation->store;
        $organization = $store->organization;
        $canView = $user->hasPermission('instagram_feed.view', $organization, $store);

        $account = $canView ? $store->instagramAccount : null;
        $galleries = $canView
            ? InstagramGallery::query()->where('store_id', $store->id)->count()
            : null;
        $readyMedia = $canView
            ? InstagramMedia::query()
                ->where('store_id', $store->id)
                ->where('mirror_status', 'ready')
                ->count()
            : null;
        $appSessionReady = $canView
            ? (InstagramFeedInstallation::query()->where('store_id', $store->id)->first()?->isUsable() ?? false)
            : null;

        $configurationStatus = match (true) {
            $installation->status !== 'active' => 'unavailable',
            ! $canView => 'restricted',
            ! $account => 'pending',
            // needs_page_selection 或 token 失效都停在这里，前台还拿不到数据。
            ! $account->isUsable() => 'partial',
            $galleries === 0 => 'partial',
            default => 'configured',
        };

        return [
            'category' => '内容与社交',
            'description' => '连接 Instagram 专业账号，把视频与图片转存到永久地址，按展示组编排后发布到店铺前台。',
            'configuration_status' => $configurationStatus,
            'configuration_status_label' => match (true) {
                $configurationStatus === 'unavailable' => '当前不可用',
                $configurationStatus === 'restricted' => '权限不足',
                $configurationStatus === 'pending' => '待连接账号',
                $account && ! $account->isUsable() => '账号待完成授权',
                $galleries === 0 => '待创建展示组',
                default => '内容已就绪',
            },
            'management_url' => $installation->status === 'active' && $canView
                ? route('instagram-feed.index', [$organization, $store], false)
                : null,
            'action_label' => '管理 Instagram Feed',
            'unavailable_reason' => match (true) {
                $installation->status !== 'active' => '应用安装当前不是启用状态。',
                ! $canView => '当前账号缺少 Instagram Feed 查看权限。',
                default => null,
            },
            'metrics' => array_values(array_filter([
                ! $canView ? null : [
                    'label' => 'Instagram 账号',
                    'value' => match (true) {
                        ! $account => '未连接',
                        filled($account->username) => '@'.$account->username,
                        default => $account->providerLabel(),
                    },
                ],
                $galleries === null ? null : ['label' => '展示组', 'value' => $galleries.' 个'],
                $readyMedia === null ? null : ['label' => '可用媒体', 'value' => $readyMedia.' 条'],
                $appSessionReady === null ? null : [
                    'label' => 'Shopify 会话',
                    'value' => $appSessionReady ? '已建立' : '未建立',
                ],
            ])),
        ];
    }
}
