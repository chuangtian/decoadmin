<?php

namespace App\Http\Controllers;

use App\Exceptions\InstagramFeedException;
use App\Services\InstagramFeed\ShopifyInstagramFeedAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * instagram-feed App 在 Shopify Admin 内的入口。
 *
 * App Home 扩展先查 connection 确认店铺已接入 DecoAdmin，再调 bootstrap 建立
 * 本 App 的 Shopify 会话，最后把用户引到 DecoAdmin 后台。
 */
class ShopifyInstagramFeedAppController extends Controller
{
    public function management(Request $request, ShopifyInstagramFeedAppService $app): RedirectResponse
    {
        try {
            $store = $app->managementStore($request->user(), (string) $request->query('shop'));
        } catch (InstagramFeedException $exception) {
            abort($exception->statusCode, $exception->getMessage());
        }

        return redirect()->route('instagram-feed.index', [$store->organization_id, $store->id]);
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
