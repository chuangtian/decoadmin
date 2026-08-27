<?php

namespace App\Http\Controllers;

use App\Exceptions\InstagramFeedException;
use App\Models\InstagramFeedInstallation;
use App\Models\InstagramGallery;
use App\Models\InstagramMedia;
use App\Models\Organization;
use App\Models\Store;
use App\Services\InstagramFeed\InstagramAccountService;
use App\Services\InstagramFeed\InstagramFeedPublisher;
use App\Services\InstagramFeed\InstagramGalleryService;
use App\Services\InstagramFeed\InstagramProductResolver;
use App\Services\InstagramFeed\InstagramProviderService;
use App\Services\InstagramFeed\InstagramSyncService;
use App\Services\InstagramFeed\R2Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Instagram 内容的 DecoAdmin 后台。
 *
 * 路由已经带了 organization.access / store.access / permission 中间件，这里再做一次
 * 控制器内校验（双保险），并把所有业务动作转交给服务层。
 */
class InstagramFeedController extends Controller
{
    /** @var list<string> */
    private const MEDIA_FILTERS = ['all', 'VIDEO', 'IMAGE'];

    public function __construct(
        private InstagramAccountService $accounts,
        private InstagramProviderService $providers,
        private InstagramSyncService $sync,
        private InstagramGalleryService $galleries,
        private InstagramFeedPublisher $publisher,
        private InstagramProductResolver $productResolver,
        private R2Client $r2,
    ) {}

    public function index(Request $request, Organization $organization, Store $store): Response
    {
        $this->assertUserScope($request, $organization, $store, 'instagram_feed.view');

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

        return Inertia::render('InstagramFeed/Index', [
            'organization' => ['id' => $organization->id, 'name' => $organization->name],
            'store' => ['id' => $store->id, 'name' => $store->name, 'shopify_domain' => $store->shopify_domain],
            'environment' => (string) config('instagram_feed.environment'),
            'providers' => $this->providers->configuredProviders(),
            'account' => $account ? [
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
            ] : null,
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
            'permissions' => [
                'connect' => $request->user()->hasPermission('instagram_feed.connect', $organization, $store),
                'sync' => $request->user()->hasPermission('instagram_feed.sync', $organization, $store),
                'manageGallery' => $request->user()->hasPermission('instagram_feed.gallery.manage', $organization, $store),
                'publish' => $request->user()->hasPermission('instagram_feed.publish', $organization, $store),
            ],
        ]);
    }

    /** 单个展示组的编辑页：左侧候选、右侧组内、拖拽排序。 */
    public function showGallery(Request $request, Organization $organization, Store $store, InstagramGallery $gallery): Response
    {
        $this->assertUserScope($request, $organization, $store, 'instagram_feed.gallery.manage');
        abort_unless((int) $gallery->store_id === (int) $store->id, 404);

        $filter = $request->string('filter')->toString();
        $filter = in_array($filter, self::MEDIA_FILTERS, true) ? $filter : 'all';

        $gallery->load(['items.media']);
        $members = $gallery->items->map(fn ($item) => $item->media)->filter()->values();
        $memberIds = $members->pluck('id')->all();

        // 左侧候选：媒体库里还没进这个组的内容。筛选只作用在这一侧 —— 右侧是组内完整
        // 清单，拖拽排序要按全量重写 position，被筛掉会串号。
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

        return Inertia::render('InstagramFeed/Gallery', [
            'organization' => ['id' => $organization->id, 'name' => $organization->name],
            'store' => ['id' => $store->id, 'name' => $store->name],
            'gallery' => ['id' => $gallery->uuid, 'name' => $gallery->name, 'handle' => $gallery->handle],
            'members' => $members->map(fn (InstagramMedia $media): array => $this->mediaPayload($media, $productMap))->all(),
            'candidates' => $candidates->map(fn (InstagramMedia $media): array => $this->mediaPayload($media, $productMap))->all(),
            'totalCount' => InstagramMedia::query()->where('store_id', $store->id)->count(),
            'filter' => $filter,
            'filters' => self::MEDIA_FILTERS,
            'productError' => $productError,
            'permissions' => [
                'publish' => $request->user()->hasPermission('instagram_feed.publish', $organization, $store),
            ],
        ]);
    }

    /** 生成 Meta 授权链接。授权在新窗口完成，所以返回 JSON 而不是重定向。 */
    public function connect(Request $request, Organization $organization, Store $store): JsonResponse
    {
        $this->assertUserScope($request, $organization, $store, 'instagram_feed.connect');
        $values = $request->validate([
            'provider' => ['required', 'string', 'in:instagram_login,facebook_login'],
        ]);

        try {
            $url = $this->accounts->authorizeUrl($store, $request->user(), $values['provider']);
        } catch (InstagramFeedException $exception) {
            return response()->json(
                ['error' => ['code' => $exception->errorCode, 'message' => $exception->getMessage()]],
                $exception->statusCode,
            );
        }

        return response()->json(['data' => ['authorize_url' => $url]]);
    }

    public function selectPage(Request $request, Organization $organization, Store $store): RedirectResponse
    {
        $this->assertUserScope($request, $organization, $store, 'instagram_feed.connect');
        $values = $request->validate([
            'page_id' => ['required', 'string', 'max:64', 'regex:/^[0-9]+$/'],
        ]);

        return $this->run(fn (): string => '已连接 @'.$this->accounts->selectPage($store, $request->user(), $values['page_id'])->username);
    }

    public function disconnect(Request $request, Organization $organization, Store $store): RedirectResponse
    {
        $this->assertUserScope($request, $organization, $store, 'instagram_feed.connect');

        return $this->run(function () use ($store, $request): string {
            $purged = $this->accounts->disconnect($store, $request->user());
            // 前台数据要一并清空，否则店铺仍会展示已被删掉的内容。
            $this->publishQuietly($store, $request);

            return count($purged['failed_keys']) > 0
                ? '已断开授权，但有 '.count($purged['failed_keys']).' 个文件未能从 R2 删除，需要手动清理。'
                : '已断开授权。';
        });
    }

    public function sync(Request $request, Organization $organization, Store $store): RedirectResponse
    {
        $this->assertUserScope($request, $organization, $store, 'instagram_feed.sync');

        return $this->run(function () use ($store): string {
            $result = $this->sync->sync($store);
            $mirrorNote = $result['configured']
                ? "已转存 {$result['ready']} 条，待转存 {$result['pending']} 条"
                : 'Cloudflare R2 尚未配置，暂未转存';

            return "已拉取 {$result['fetched']} 条，新增 {$result['created']} 条，{$mirrorNote}。";
        });
    }

    public function mirror(Request $request, Organization $organization, Store $store): RedirectResponse
    {
        $this->assertUserScope($request, $organization, $store, 'instagram_feed.sync');

        return $this->run(function () use ($store): string {
            $result = $this->sync->advanceMirrors($store);
            if (! $result['configured']) {
                throw new InstagramFeedException(
                    'R2_NOT_CONFIGURED',
                    'Cloudflare R2 尚未配置，请先补齐 R2 相关环境变量再转存。',
                    503,
                );
            }

            return "转存完成 {$result['ready']} 条，失败 {$result['failed']} 条，待处理 {$result['pending']} 条。";
        });
    }

    public function retryMirror(Request $request, Organization $organization, Store $store, InstagramMedia $media): RedirectResponse
    {
        $this->assertUserScope($request, $organization, $store, 'instagram_feed.sync');
        abort_unless((int) $media->store_id === (int) $store->id, 404);

        return $this->run(function () use ($media, $store): string {
            $this->sync->retryMirror($media);
            $result = $this->sync->advanceMirrors($store);

            return "已重试，成功 {$result['ready']} 条，失败 {$result['failed']} 条。";
        });
    }

    public function publish(Request $request, Organization $organization, Store $store): RedirectResponse
    {
        $this->assertUserScope($request, $organization, $store, 'instagram_feed.publish');

        return $this->run(function () use ($store, $request): string {
            $result = $this->publisher->publish($store, $request->user());

            return "已发布 {$result['galleries']} 个展示组、共 {$result['items']} 条内容到店铺前台。";
        });
    }

    public function storeGallery(Request $request, Organization $organization, Store $store): RedirectResponse
    {
        $this->assertUserScope($request, $organization, $store, 'instagram_feed.gallery.manage');
        $values = $request->validate([
            'name' => ['required', 'string', 'max:'.(int) config('instagram_feed.gallery.max_name_length', 60)],
        ]);

        return $this->run(fn (): string => '已创建展示组「'.$this->galleries->create($store, $values['name'], $request->user())->name.'」。');
    }

    public function updateGallery(Request $request, Organization $organization, Store $store, InstagramGallery $gallery): RedirectResponse
    {
        $this->assertUserScope($request, $organization, $store, 'instagram_feed.gallery.manage');
        $values = $request->validate([
            'name' => ['required', 'string', 'max:'.(int) config('instagram_feed.gallery.max_name_length', 60)],
        ]);

        return $this->run(function () use ($store, $gallery, $values, $request): string {
            $this->galleries->rename($store, $gallery, $values['name'], $request->user());

            return '已更新展示组名称。';
        });
    }

    public function destroyGallery(Request $request, Organization $organization, Store $store, InstagramGallery $gallery): RedirectResponse
    {
        $this->assertUserScope($request, $organization, $store, 'instagram_feed.gallery.manage');

        return $this->run(function () use ($store, $gallery, $request): string {
            $this->galleries->delete($store, $gallery, $request->user());

            return '已删除展示组。';
        });
    }

    public function addGalleryItems(Request $request, Organization $organization, Store $store, InstagramGallery $gallery): RedirectResponse
    {
        $this->assertUserScope($request, $organization, $store, 'instagram_feed.gallery.manage');
        $values = $this->validateMediaIds($request);

        return $this->run(fn (): string => '已加入 '.$this->galleries->addItems($store, $gallery, $values, $request->user()).' 条内容。');
    }

    public function removeGalleryItems(Request $request, Organization $organization, Store $store, InstagramGallery $gallery): RedirectResponse
    {
        $this->assertUserScope($request, $organization, $store, 'instagram_feed.gallery.manage');
        $values = $this->validateMediaIds($request);

        return $this->run(fn (): string => '已移出 '.$this->galleries->removeItems($store, $gallery, $values, $request->user()).' 条内容。');
    }

    public function reorderGallery(Request $request, Organization $organization, Store $store, InstagramGallery $gallery): RedirectResponse
    {
        $this->assertUserScope($request, $organization, $store, 'instagram_feed.gallery.manage');
        $values = $this->validateMediaIds($request);

        return $this->run(function () use ($store, $gallery, $values, $request): string {
            $this->galleries->reorder($store, $gallery, $values, $request->user());

            return '已保存展示顺序。';
        });
    }

    public function updateMediaProducts(Request $request, Organization $organization, Store $store, InstagramMedia $media): RedirectResponse
    {
        $this->assertUserScope($request, $organization, $store, 'instagram_feed.gallery.manage');
        $values = $request->validate([
            'product_ids' => ['present', 'array', 'max:20'],
            'product_ids.*' => ['string', 'max:255', 'regex:#^gid://shopify/Product/\d+$#'],
        ]);

        return $this->run(function () use ($store, $media, $values, $request): string {
            $this->galleries->setMediaProducts($store, $media, array_values($values['product_ids']), $request->user());

            return '已保存关联商品。';
        });
    }

    /**
     * 统一的动作执行壳：成功走 success flash，业务异常走 error flash。
     *
     * Inertia 页面靠 flash 反馈，不返回 JSON；内部异常不外泄细节。
     *
     * @param  callable(): string  $action
     */
    private function run(callable $action): RedirectResponse
    {
        try {
            return back()->with('success', $action());
        } catch (InstagramFeedException $exception) {
            return back()->with('error', $exception->getMessage());
        } catch (Throwable) {
            return back()->with('error', '操作失败，请稍后重试；如果问题持续，请联系管理员。');
        }
    }

    /** 断开授权时顺带清空前台数据，失败不应盖掉断开成功的结果。 */
    private function publishQuietly(Store $store, Request $request): void
    {
        try {
            $this->publisher->publish($store, $request->user());
        } catch (Throwable) {
            // App 会话可能已随卸载失效，此时前台数据交由下一次发布或卸载 webhook 处理。
        }
    }

    /** @return list<string> */
    private function validateMediaIds(Request $request): array
    {
        $values = $request->validate([
            'media_ids' => ['required', 'array', 'min:1', 'max:500'],
            'media_ids.*' => ['required', 'string', 'uuid'],
        ]);

        return array_values($values['media_ids']);
    }

    /**
     * @param  array<string, array{id: string, title: string, handle: string, image_url: string|null, image_alt: string|null}>  $productMap
     * @return array<string, mixed>
     */
    private function mediaPayload(InstagramMedia $media, array $productMap): array
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

    private function assertUserScope(Request $request, Organization $organization, Store $store, string $permission): void
    {
        abort_unless($store->organization_id === $organization->id, 403);
        abort_unless($request->user()->canAccessStore($store), 403);
        abort_unless($request->user()->hasPermission($permission, $organization, $store), 403);
    }
}
