<?php

namespace App\Http\Controllers;

use App\Exceptions\InstagramFeedException;
use App\Models\InstagramGallery;
use App\Models\InstagramMedia;
use App\Models\Organization;
use App\Models\Store;
use App\Services\InstagramFeed\InstagramAccountService;
use App\Services\InstagramFeed\InstagramFeedPresenter;
use App\Services\InstagramFeed\InstagramFeedPublisher;
use App\Services\InstagramFeed\InstagramGalleryService;
use App\Services\InstagramFeed\InstagramSyncService;
use App\Services\SystemSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Instagram Feed 的 DecoAdmin 后台。
 *
 * 路由已经带了 organization.access / store.access / permission 中间件，这里再做一次
 * 控制器内校验（双保险），并把所有业务动作转交给服务层。
 */
class InstagramFeedController extends Controller
{
    public function __construct(
        private InstagramAccountService $accounts,
        private InstagramSyncService $sync,
        private InstagramGalleryService $galleries,
        private InstagramFeedPublisher $publisher,
        private InstagramFeedPresenter $presenter,
        private SystemSettingsService $settings,
    ) {}

    public function index(Request $request, Organization $organization, Store $store): Response
    {
        $this->assertUserScope($request, $organization, $store, 'instagram_feed.view');

        return Inertia::render('InstagramFeed/Index', [
            'organization' => ['id' => $organization->id, 'name' => $organization->name],
            'store' => ['id' => $store->id, 'name' => $store->name, 'shopify_domain' => $store->shopify_domain],
            ...$this->presenter->overview($store),
            'permissions' => [
                'connect' => $request->user()->hasPermission('instagram_feed.connect', $organization, $store),
                'sync' => $request->user()->hasPermission('instagram_feed.sync', $organization, $store),
                'manageGallery' => $request->user()->hasPermission('instagram_feed.gallery.manage', $organization, $store),
                'publish' => $request->user()->hasPermission('instagram_feed.publish', $organization, $store),
            ],
            'credentials' => $this->credentialsForFrontend($request, $organization, $store),
        ]);
    }

    /** 单个展示组的编辑页：左侧候选、右侧组内、拖拽排序。 */
    public function showGallery(Request $request, Organization $organization, Store $store, InstagramGallery $gallery): Response
    {
        $this->assertUserScope($request, $organization, $store, 'instagram_feed.gallery.manage');
        abort_unless((int) $gallery->store_id === (int) $store->id, 404);

        return Inertia::render('InstagramFeed/Gallery', [
            'organization' => ['id' => $organization->id, 'name' => $organization->name],
            'store' => ['id' => $store->id, 'name' => $store->name],
            ...$this->presenter->galleryDetail($store, $gallery, $request->string('filter')->toString()),
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
     * 「应用配置」页签的数据。Meta 应用凭证和 R2 存储凭证是平台级配置，
     * 不属于单个店铺，因此这里用系统设置权限而不是 instagram_feed.* 权限。
     * 没有查看权限时返回 null，前端连页签都不显示。
     *
     * @return array<string, mixed>|null
     */
    private function credentialsForFrontend(Request $request, Organization $organization, Store $store): ?array
    {
        if (! $request->user()->hasPermission('system.settings.view', $organization, $store)) {
            return null;
        }

        $canUpdate = $request->user()->hasPermission('system.settings.update', $organization, $store);

        return [
            'can_update' => $canUpdate,
            'meta' => $this->settings->sectionForFrontend('instagram_meta', $canUpdate),
            'r2' => $this->settings->sectionForFrontend('instagram_r2', $canUpdate),
            'callbacks' => [
                'instagram' => (string) config('instagram_feed.instagram.redirect_uri'),
                'facebook' => (string) config('instagram_feed.facebook.redirect_uri'),
            ],
            'endpoints' => [
                'meta' => route('system.settings.instagram-meta.update'),
                'r2' => route('system.settings.instagram-r2.update'),
            ],
        ];
    }

    private function assertUserScope(Request $request, Organization $organization, Store $store, string $permission): void
    {
        abort_unless($store->organization_id === $organization->id, 403);
        abort_unless($request->user()->canAccessStore($store), 403);
        abort_unless($request->user()->hasPermission($permission, $organization, $store), 403);
    }
}
