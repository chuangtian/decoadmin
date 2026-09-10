<?php

namespace App\Services\InstagramFeed;

use App\Exceptions\InstagramFeedException;
use App\Models\InstagramFeedInstallation;
use App\Models\InstagramGallery;
use App\Models\InstagramMedia;
use App\Models\Store;
use Throwable;

/**
 * Instagram Feed 的读侧数据拼装。
 *
 * DecoAdmin 后台页面（Inertia）与 Shopify 内嵌页面（JSON）必须看到同一份结构，
 * 否则两边的展示会随时间分叉。所以拼装只在这里做一次，两个 controller 都调用它，
 * 各自只负责补充自己独有的部分（后台补 permissions / credentials，内嵌补 capabilities）。
 *
 * 平台级凭证不在这里出现：它不属于任何店铺，只能由 DecoAdmin 后台按
 * system.settings.* 权限单独暴露。
 */
class InstagramFeedPresenter
{
    /** @var list<string> */
    public const MEDIA_FILTERS = ['all', 'VIDEO', 'IMAGE'];

    public function __construct(
        private InstagramProviderService $providers,
        private InstagramProductResolver $productResolver,
        private R2Client $r2,
    ) {}

    /**
     * 概览页数据：账号、转存统计、展示组列表、App 会话状态。
     *
     * @return array<string, mixed>
     */
    public function overview(Store $store): array
    {
        $account = $store->instagramAccount;
        $counts = InstagramMedia::query()
            ->where('store_id', $store->id)
            ->selectRaw('mirror_status, count(*) as aggregate')
            ->groupBy('mirror_status')
            ->pluck('aggregate', 'mirror_status');

        $pageOptions = [];
        $pageOptionsError = null;
        if ($account?->status === 'needs_page_selection') {
            try {
                $pageOptions = $this->providers->listSelectablePages($account);
            } catch (Throwable $exception) {
                $pageOptionsError = $exception instanceof InstagramFeedException
                    ? $exception->getMessage()
                    : '读取 Facebook 主页列表失败，请稍后重试。';
            }
        }

        $galleries = InstagramGallery::query()
            ->where('store_id', $store->id)
            ->with(['items' => fn ($query) => $query->with('media')->limit(4)])
            ->withCount('items')
            ->orderBy('position')
            ->orderBy('created_at')
            ->get()
            ->map(fn (InstagramGallery $gallery): array => [
                'id' => $gallery->uuid,
                'name' => $gallery->name,
                'handle' => $gallery->handle,
                'item_count' => (int) $gallery->items_count,
                'previews' => $gallery->items
                    ->map(fn ($item) => $item->media?->previewUrl())
                    ->filter()
                    ->values()
                    ->all(),
            ])
            ->all();

        $installation = InstagramFeedInstallation::query()->where('store_id', $store->id)->first();

        return [
            'environment' => (string) config('instagram_feed.environment'),
            'providers' => $this->providers->configuredProviders(),
            'account' => $this->accountPayload($store),
            'pageOptions' => $pageOptions,
            'pageOptionsError' => $pageOptionsError,
            'stats' => [
                'total' => (int) $counts->sum(),
                'ready' => (int) $counts->get('ready', 0),
                'processing' => (int) $counts->get('processing', 0),
                'pending' => (int) $counts->get('pending', 0),
                'failed' => (int) $counts->get('failed', 0),
                'galleries' => count($galleries),
            ],
            'galleries' => $galleries,
            'mirrorConfigured' => $this->r2->isConfigured(),
            'appSessionReady' => $installation?->isUsable() ?? false,
            'appSession' => $this->appSession($installation),
        ];
    }

    /** @return array<string, mixed>|null */
    public function accountPayload(Store $store): ?array
    {
        $account = $store->instagramAccount;

        return $account ? [
            'provider' => $account->provider,
            'provider_label' => $account->providerLabel(),
            'status' => $account->status,
            'username' => $account->username,
            'account_type' => $account->account_type,
            'profile_picture_url' => $account->profile_picture_url,
            'page_name' => $account->page_name,
            'token_expires_at' => $account->token_expires_at?->toIso8601String(),
            'last_synced_at' => $account->last_synced_at?->toIso8601String(),
            'last_published_at' => $account->last_published_at?->toIso8601String(),
        ] : null;
    }

    /**
     * 展示组编辑页数据：左侧候选、右侧组内成员。
     *
     * 筛选只作用在候选一侧 —— 右侧是组内完整清单，拖拽排序要按全量重写 position，
     * 被筛掉会串号。
     *
     * @return array<string, mixed>
     */
    public function galleryDetail(Store $store, InstagramGallery $gallery, string $rawFilter): array
    {
        $filter = in_array($rawFilter, self::MEDIA_FILTERS, true) ? $rawFilter : 'all';

        $gallery->load(['items.media']);
        $members = $gallery->items->map(fn ($item) => $item->media)->filter()->values();
        $memberIds = $members->pluck('id')->all();

        $candidates = InstagramMedia::query()
            ->where('store_id', $store->id)
            ->when($memberIds !== [], fn ($query) => $query->whereNotIn('id', $memberIds))
            ->when($filter === 'VIDEO', fn ($query) => $query->where('media_type', 'VIDEO'))
            // 「图片」要连带算上 CAROUSEL_ALBUM，轮播相册本质就是多图的图文贴。
            ->when($filter === 'IMAGE', fn ($query) => $query->whereIn('media_type', ['IMAGE', 'CAROUSEL_ALBUM']))
            ->orderByDesc('posted_at')
            ->limit(200)
            ->get();

        $productGids = $members->concat($candidates)
            ->flatMap(fn (InstagramMedia $media): array => $media->productGids())
            ->unique()
            ->values()
            ->all();
        $productMap = [];
        $productError = null;
        if ($productGids !== []) {
            try {
                $productMap = $this->productResolver->resolve($store, $productGids);
            } catch (Throwable) {
                $productError = '暂时无法从 Shopify 读取关联商品信息。';
            }
        }

        return [
            'gallery' => ['id' => $gallery->uuid, 'name' => $gallery->name, 'handle' => $gallery->handle],
            'members' => $members->map(fn (InstagramMedia $media): array => $this->mediaPayload($media, $productMap))->all(),
            'candidates' => $candidates->map(fn (InstagramMedia $media): array => $this->mediaPayload($media, $productMap))->all(),
            'totalCount' => InstagramMedia::query()->where('store_id', $store->id)->count(),
            'filter' => $filter,
            'filters' => self::MEDIA_FILTERS,
            'productError' => $productError,
        ];
    }

    /**
     * @param  array<string, array{id: string, title: string, handle: string, image_url: string|null, image_alt: string|null}>  $productMap
     * @return array<string, mixed>
     */
    public function mediaPayload(InstagramMedia $media, array $productMap): array
    {
        $products = [];
        foreach ($media->productGids() as $gid) {
            if (isset($productMap[$gid])) {
                $products[] = $productMap[$gid];
            }
        }

        return [
            'id' => $media->uuid,
            'media_type' => $media->media_type,
            'media_product_type' => $media->media_product_type,
            'caption' => $media->caption,
            'permalink' => $media->permalink,
            'preview_url' => $media->previewUrl(),
            'video_url' => $media->video_url,
            'mirror_status' => $media->mirror_status,
            'mirror_error' => $media->mirror_error,
            'posted_at' => $media->posted_at?->toIso8601String(),
            'like_count' => $media->like_count,
            'comments_count' => $media->comments_count,
            'products' => $products,
        ];
    }

    /**
     * Shopify App 授权状态。后台的连接状态卡片与内嵌页顶部提示共用这一份。
     *
     * @return array<string, mixed>
     */
    public function appSession(?InstagramFeedInstallation $installation): array
    {
        if (! $installation) {
            return [
                'status' => 'not_authorized',
                'usable' => false,
                'environment' => null,
                'environment_matches' => false,
                'app_installation_id' => null,
                'granted_scopes' => [],
                'installed_at' => null,
                'uninstalled_at' => null,
                'last_verified_at' => null,
                'last_api_check' => null,
                'last_published_at' => null,
                'last_error' => null,
                'last_error_at' => null,
            ];
        }

        return [
            'status' => (string) $installation->status,
            'usable' => $installation->isUsable(),
            'environment' => (string) $installation->environment,
            'environment_matches' => $installation->environment === (string) config('instagram_feed.environment'),
            'app_installation_id' => $installation->app_installation_id,
            'granted_scopes' => is_array($installation->granted_scopes) ? $installation->granted_scopes : [],
            'installed_at' => $installation->installed_at?->toIso8601String(),
            'uninstalled_at' => $installation->uninstalled_at?->toIso8601String(),
            'last_verified_at' => $installation->last_verified_at?->toIso8601String(),
            'last_api_check' => $installation->last_api_check?->toIso8601String(),
            'last_published_at' => $installation->last_published_at?->toIso8601String(),
            'last_error' => $installation->last_error,
            'last_error_at' => $installation->last_error_at?->toIso8601String(),
        ];
    }
}
