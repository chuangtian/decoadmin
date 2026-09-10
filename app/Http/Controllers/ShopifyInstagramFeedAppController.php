<?php

namespace App\Http\Controllers;

use App\Exceptions\InstagramFeedException;
use App\Services\InstagramFeed\InstagramFeedAppRegistry;
use App\Services\InstagramFeed\ShopifyInstagramFeedAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * instagram-feed App 在 Shopify Admin 内的入口（App Home，自托管 iframe 模型）。
 *
 * 商家在 Shopify 后台打开应用后就留在这里完成全部操作，不会跳到 DecoAdmin，也不需要
 * 任何“后台授权”动作：壳页面加载 App Bridge，前端拿 session token 调
 * /api/shopify-app/instagram-feed/*，会话由后端按需自动建立。
 */
class ShopifyInstagramFeedAppController extends Controller
{
    /**
     * 内嵌应用的壳页面。
     *
     * 这里刻意不鉴权、也不查任何店铺数据：请求还没有可信身份（id_token 要等
     * App Bridge 在前端取），所以只输出公开的 client id 与接口前缀。真正的身份
     * 校验发生在后续每一个 API 请求上。
     */
    public function management(Request $request, InstagramFeedAppRegistry $registry): View
    {
        return view('shopify-apps.instagram-feed', [
            'clientId' => (string) config('instagram_feed.active.client_id'),
            'environment' => $registry->environment(),
            'apiBase' => '/api/shopify-app/instagram-feed',
        ]);
    }

    public function connection(Request $request, ShopifyInstagramFeedAppService $app): JsonResponse
    {
        $store = $app->connectedStore((string) $request->attributes->get('shopify_shop'));

        return response()->json(['data' => [
            'connected' => $store !== null,
            'environment' => (string) config('instagram_feed.environment'),
            'store' => $store ? ['id' => $store->id, 'name' => $store->name] : null,
        ]]);
    }

    public function bootstrap(Request $request, ShopifyInstagramFeedAppService $app): JsonResponse
    {
        $shop = (string) $request->attributes->get('shopify_shop');
        $store = $app->connectedStore($shop);
        if (! $store) {
            return response()->json(['error' => [
                'code' => 'STORE_NOT_CONNECTED',
                'message' => '该 Shopify 店铺尚未连接 DecoAdmin。',
            ]], 409);
        }

        try {
            $result = $app->bootstrap($store, (string) $request->attributes->get('shopify_id_token'));
        } catch (InstagramFeedException $exception) {
            return response()->json(
                ['error' => ['code' => $exception->errorCode, 'message' => $exception->getMessage()]],
                $exception->statusCode,
                $exception->statusCode === 401 ? ['X-Shopify-Retry-Invalid-Session-Request' => '1'] : [],
            );
        }

        return response()->json(['data' => [
            'environment' => (string) config('instagram_feed.environment'),
            'store' => ['id' => $store->id, 'name' => $store->name, 'shopify_domain' => $store->shopify_domain],
            'app_installation_id' => $result['app_installation_id'],
            'granted_scopes' => $result['granted_scopes'],
        ]]);
    }
}
