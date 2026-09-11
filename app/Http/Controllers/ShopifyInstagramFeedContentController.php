<?php

namespace App\Http\Controllers;

use App\Exceptions\InstagramFeedException;
use App\Models\InstagramGallery;
use App\Models\InstagramMedia;
use App\Models\Store;
use App\Services\InstagramFeed\InstagramAccountService;
use App\Services\InstagramFeed\InstagramFeedEmbeddedSession;
use App\Services\InstagramFeed\InstagramFeedPresenter;
use App\Services\InstagramFeed\InstagramFeedPublisher;
use App\Services\InstagramFeed\InstagramGalleryService;
use App\Services\InstagramFeed\InstagramSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Shopify 内嵌页面的内容管理 API。
 *
 * 与 InstagramFeedController（DecoAdmin 后台，Inertia）是同一套业务服务的两个入口：
 * 读侧共用 InstagramFeedPresenter，写侧共用 InstagramFeed 各服务，这里只负责
 * 「解析店铺 -> 校验入参 -> 转交服务 -> 输出 JSON」。
 *
 * 鉴权全部由路由上的 shopify.id-token:instagram_feed 中间件与
 * InstagramFeedEmbeddedSession 完成，信任模型见该服务的类注释。操作者没有 DecoAdmin
 * 用户，所以传给服务层的 actor 一律为 null。
 *
 * 约定：
 * - 成功统一为 {"data": ...}，业务失败统一为 {"error": {"code", "message"}}；
 * - 入参校验失败沿用 Laravel 的 422 {"message", "errors"}；
 * - 写操作只回消息，不回视图数据，由前端按需重新拉取，避免每个写接口都绑定某个页面。
 */
class ShopifyInstagramFeedContentController extends Controller
{
    public function __construct(
        private InstagramFeedEmbeddedSession $session,
        private InstagramFeedPresenter $presenter,
        private InstagramAccountService $accounts,
        private InstagramSyncService $sync,
        private InstagramGalleryService $galleries,
        private InstagramFeedPublisher $publisher,
    ) {}

    /** 概览：账号、转存统计、展示组、App 会话状态。 */
    public function overview(Request $request): JsonResponse
    {
        return $this->read($request, fn (Store $store): array => [
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
                'shopify_domain' => $store->shopify_domain,
            ],
            ...$this->presenter->overview($store),
            'capabilities' => $this->session->capabilities(),
        ]);
    }

    /** 生成 Meta 授权链接。授权要跳出 iframe，在顶层窗口完成。 */
    public function authorizeAccount(Request $request): JsonResponse
    {
        $values = $request->validate([
            'provider' => ['required', 'string', 'in:instagram_login,facebook_login'],
        ]);

        return $this->read($request, fn (Store $store): array => [
            'authorize_url' => $this->accounts->authorizeUrl($store, null, $values['provider']),
        ]);
    }

    public function selectPage(Request $request): JsonResponse
    {
        $values = $request->validate([
            'page_id' => ['required', 'string', 'max:64', 'regex:/^[0-9]+$/'],
        ]);

        return $this->write(
            $request,
            fn (Store $store): string => '已连接 @'.$this->accounts->selectPage($store, null, $values['page_id'])->username,
        );
    }

    public function disconnectAccount(Request $request): JsonResponse
    {
        return $this->write($request, function (Store $store): string {
            $purged = $this->accounts->disconnect($store, null);
            // 前台数据要一并清空，否则店铺仍会展示已被删掉的内容。
            $this->publishQuietly($store);

            return count($purged['failed_keys']) > 0
                ? '已断开授权，但有 '.count($purged['failed_keys']).' 个文件未能从 R2 删除，需要手动清理。'
                : '已断开授权。';
        });
    }

    /** 手动触发一次前台同步。展示组编排完成后不需要它，这里留给"前台没更新"时自助恢复。 */
    public function syncStorefrontNow(Request $request): JsonResponse
    {
        return $this->write($request, function (Store $store): string {
            $result = $this->publisher->publish($store, null);

            return "已把 {$result['galleries']} 个展示组、共 {$result['items']} 条内容同步到店铺前台。";
        });
    }

    public function sync(Request $request): JsonResponse
    {
        return $this->write($request, function (Store $store): string {
            $result = $this->sync->sync($store);
            $mirrorNote = $result['configured']
                ? "已转存 {$result['ready']} 条，待转存 {$result['pending']} 条"
                : 'Cloudflare R2 尚未配置，暂未转存';

            return "已拉取 {$result['fetched']} 条，新增 {$result['created']} 条，{$mirrorNote}。";
        }, syncStorefront: true);
    }

    public function mirror(Request $request): JsonResponse
    {
        return $this->write($request, function (Store $store): string {
            $result = $this->sync->advanceMirrors($store);
            if (! $result['configured']) {
                throw new InstagramFeedException(
                    'R2_NOT_CONFIGURED',
                    'Cloudflare R2 尚未配置，请联系 DecoAdmin 管理员补齐存储配置。',
                    503,
                );
            }

            return "转存完成 {$result['ready']} 条，失败 {$result['failed']} 条，待处理 {$result['pending']} 条。";
        }, syncStorefront: true);
    }

    public function retryMirror(Request $request, InstagramMedia $media): JsonResponse
    {
        return $this->write($request, function (Store $store) use ($media): string {
            $this->assertMediaOwnership($store, $media);
            $this->sync->retryMirror($media);
            $result = $this->sync->advanceMirrors($store);

            return "已重试，成功 {$result['ready']} 条，失败 {$result['failed']} 条。";
        }, syncStorefront: true);
    }

    /** 转存失败日志：商家自己排查用，每条带可行动说明与原始信息。 */
    public function mirrorFailures(Request $request): JsonResponse
    {
        return $this->read($request, fn (Store $store): array => [
            'failures' => $this->presenter->mirrorFailures($store),
        ]);
    }

    /**
     * 把该店铺所有转存失败的内容重新排队。
     *
     * 逐条重试在失败很多时要点很多次，也会撞单条重试的限流，所以给一个整批入口：
     * 只改状态，实际转存交给页面上的自动推进或后台调度。
     */
    public function retryAllMirrorFailures(Request $request): JsonResponse
    {
        return $this->write($request, function (Store $store): string {
            $requeued = InstagramMedia::query()
                ->where('store_id', $store->id)
                ->where('mirror_status', 'failed')
                ->update(['mirror_status' => 'pending', 'mirror_error' => null, 'updated_at' => now()]);

            return $requeued > 0
                ? "已把 {$requeued} 条失败内容重新排队，转存会自动继续。"
                : '没有需要重试的内容。';
        });
    }

    public function showGallery(Request $request, InstagramGallery $gallery): JsonResponse
    {
        return $this->read($request, function (Store $store) use ($request, $gallery): array {
            $this->assertGalleryOwnership($store, $gallery);

            return $this->presenter->galleryDetail($store, $gallery, $request->string('filter')->toString());
        });
    }

    public function storeGallery(Request $request): JsonResponse
    {
        $values = $request->validate([
            'name' => ['required', 'string', 'max:'.(int) config('instagram_feed.gallery.max_name_length', 60)],
        ]);

        return $this->write(
            $request,
            fn (Store $store): string => '已创建展示组「'.$this->galleries->create($store, $values['name'], null)->name.'」。',
            syncStorefront: true,
        );
    }

    public function updateGallery(Request $request, InstagramGallery $gallery): JsonResponse
    {
        $values = $request->validate([
            'name' => ['required', 'string', 'max:'.(int) config('instagram_feed.gallery.max_name_length', 60)],
        ]);

        return $this->write($request, function (Store $store) use ($gallery, $values): string {
            $this->assertGalleryOwnership($store, $gallery);
            $this->galleries->rename($store, $gallery, $values['name'], null);

            return '已更新展示组名称。';
        }, syncStorefront: true);
    }

    public function destroyGallery(Request $request, InstagramGallery $gallery): JsonResponse
    {
        return $this->write($request, function (Store $store) use ($gallery): string {
            $this->assertGalleryOwnership($store, $gallery);
            $this->galleries->delete($store, $gallery, null);

            return '已删除展示组。';
        }, syncStorefront: true);
    }

    public function addGalleryItems(Request $request, InstagramGallery $gallery): JsonResponse
    {
        $mediaIds = $this->validatedMediaIds($request);

        return $this->write($request, function (Store $store) use ($gallery, $mediaIds): string {
            $this->assertGalleryOwnership($store, $gallery);

            return '已加入 '.$this->galleries->addItems($store, $gallery, $mediaIds, null).' 条内容。';
        }, syncStorefront: true);
    }

    public function removeGalleryItems(Request $request, InstagramGallery $gallery): JsonResponse
    {
        $mediaIds = $this->validatedMediaIds($request);

        return $this->write($request, function (Store $store) use ($gallery, $mediaIds): string {
            $this->assertGalleryOwnership($store, $gallery);

            return '已移出 '.$this->galleries->removeItems($store, $gallery, $mediaIds, null).' 条内容。';
        }, syncStorefront: true);
    }

    public function reorderGallery(Request $request, InstagramGallery $gallery): JsonResponse
    {
        $mediaIds = $this->validatedMediaIds($request);

        return $this->write($request, function (Store $store) use ($gallery, $mediaIds): string {
            $this->assertGalleryOwnership($store, $gallery);
            $this->galleries->reorder($store, $gallery, $mediaIds, null);

            return '已保存展示顺序。';
        }, syncStorefront: true);
    }

    public function updateMediaProducts(Request $request, InstagramMedia $media): JsonResponse
    {
        $values = $request->validate([
            'product_ids' => ['present', 'array', 'max:20'],
            'product_ids.*' => ['string', 'max:255', 'regex:#^gid://shopify/Product/\d+$#'],
        ]);

        return $this->write($request, function (Store $store) use ($media, $values): string {
            $this->assertMediaOwnership($store, $media);
            $this->galleries->setMediaProducts($store, $media, array_values($values['product_ids']), null);

            return '已保存关联商品。';
        }, syncStorefront: true);
    }

    /**
     * 读接口外壳：解析店铺后输出 data。
     *
     * @param  callable(Store): array<string, mixed>  $resolver
     */
    private function read(Request $request, callable $resolver): JsonResponse
    {
        try {
            return response()->json(['data' => $resolver($this->session->store($request))]);
        } catch (InstagramFeedException $exception) {
            return $this->failure($exception);
        }
    }

    /**
     * 写接口外壳：解析店铺、执行动作、输出消息。
     *
     * 与后台的 run() 一致：业务异常回可读原因，未预期异常不外泄细节。
     *
     * @param  callable(Store): string  $action
     */
    private function write(Request $request, callable $action, bool $syncStorefront = false): JsonResponse
    {
        try {
            $store = $this->session->store($request);
            $message = $action($store);

            // 前台数据没有"发布"按钮：凡是会改变前台展示的动作，完成后立刻同步一次。
            if ($syncStorefront) {
                $message .= $this->syncStorefront($store);
            }

            return response()->json(['data' => ['message' => $message]]);
        } catch (InstagramFeedException $exception) {
            return $this->failure($exception);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['error' => [
                'code' => 'INSTAGRAM_FEED_ACTION_FAILED',
                'message' => '操作失败，请稍后重试；如果问题持续，请联系管理员。',
            ]], 500);
        }
    }

    /**
     * 把当前内容同步到店铺前台。
     *
     * 失败不能推翻已经成功的主动作（内容已经改了），但也不能静默——否则商家以为
     * 前台已经更新。所以把原因作为后缀附在消息里，同时原因已由 publisher 写进
     * 安装记录的 last_error，运维在后台也能看到。
     */
    private function syncStorefront(Store $store): string
    {
        try {
            $this->publisher->publish($store, null);

            return '';
        } catch (InstagramFeedException $exception) {
            return ' 但前台数据更新失败：'.$exception->getMessage();
        } catch (Throwable $exception) {
            report($exception);

            return ' 但前台数据更新失败，请稍后重试。';
        }
    }

    private function failure(InstagramFeedException $exception): JsonResponse
    {
        return response()->json(
            ['error' => ['code' => $exception->errorCode, 'message' => $exception->getMessage()]],
            $exception->statusCode,
            // 会话失效时告诉 App Bridge 换一个新 id_token 重试。
            $exception->statusCode === 401 ? ['X-Shopify-Retry-Invalid-Session-Request' => '1'] : [],
        );
    }

    /** 断开授权时顺带清空前台数据，失败不应盖掉断开成功的结果。 */
    private function publishQuietly(Store $store): void
    {
        try {
            $this->publisher->publish($store, null);
        } catch (Throwable) {
            // App 会话可能已随卸载失效，此时前台数据交由下一次发布或卸载 webhook 处理。
        }
    }

    /** @return list<string> */
    private function validatedMediaIds(Request $request): array
    {
        $values = $request->validate([
            'media_ids' => ['required', 'array', 'min:1', 'max:500'],
            'media_ids.*' => ['required', 'string', 'uuid'],
        ]);

        return array_values($values['media_ids']);
    }

    /**
     * 隐式绑定按 uuid 取模型，不带店铺条件，所以必须显式确认归属，
     * 否则拿到别的店铺的 uuid 就能跨店铺操作。
     */
    private function assertGalleryOwnership(Store $store, InstagramGallery $gallery): void
    {
        if ((int) $gallery->store_id !== (int) $store->id) {
            throw new InstagramFeedException('GALLERY_NOT_FOUND', '找不到这个展示组。', 404);
        }
    }

    private function assertMediaOwnership(Store $store, InstagramMedia $media): void
    {
        if ((int) $media->store_id !== (int) $store->id) {
            throw new InstagramFeedException('INSTAGRAM_MEDIA_NOT_FOUND', '找不到这条 Instagram 媒体。', 404);
        }
    }
}
