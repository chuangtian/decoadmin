<?php

namespace App\Http\Controllers;

use App\Exceptions\StudentDiscountException;
use App\Models\Store;
use App\Services\StudentDiscount\ShopifyStudentDiscountAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ShopifyStudentDiscountAppController extends Controller
{
    public function management(Request $request, ShopifyStudentDiscountAppService $app): RedirectResponse
    {
        try {
            $store = $app->managementStore($request->user(), (string) $request->query('shop'));
        } catch (StudentDiscountException $exception) {
            abort($exception->statusCode, $exception->getMessage());
        }

        return redirect()->route('student-discounts.index', [$store->organization_id, $store->id]);
    }

    public function connection(Request $request): JsonResponse
    {
        $shop = (string) $request->attributes->get('shopify_shop');
        $store = Store::query()
            ->where('shopify_domain', $shop)
            ->where('status', 'active')
            ->first();

        return response()->json(['data' => [
            'connected' => (bool) $store,
            'environment' => (string) config('student_discount.environment'),
            'store' => $store ? [
                'id' => $store->id,
                'organization_id' => $store->organization_id,
                'name' => $store->name,
                'shopify_domain' => $store->shopify_domain,
                'status' => $store->status,
            ] : null,
        ]]);
    }

    public function bootstrap(Request $request, ShopifyStudentDiscountAppService $app): JsonResponse
    {
        $shop = (string) $request->attributes->get('shopify_shop');
        $store = Store::query()
            ->where('shopify_domain', $shop)
            ->where('status', 'active')
            ->first();
        if (! $store) {
            return response()->json(['error' => [
                'code' => 'STORE_NOT_CONNECTED',
                'message' => '该 Shopify 店铺尚未连接 DecoAdmin。',
            ]], 409);
        }

        try {
            $result = $app->bootstrap($store, (string) $request->attributes->get('shopify_id_token'));

            return response()->json(['data' => [
                'connected' => true,
                'environment' => (string) config('student_discount.environment'),
                'store' => ['id' => $store->id, 'name' => $store->name, 'shopify_domain' => $store->shopify_domain],
                'app_installation_id' => $result['app_installation_id'],
                'proxy_path' => $result['proxy_path'],
            ]]);
        } catch (StudentDiscountException $exception) {
            $headers = $exception->statusCode === 401 ? ['X-Shopify-Retry-Invalid-Session-Request' => '1'] : [];

            return response()->json(['error' => [
                'code' => $exception->errorCode,
                'message' => $exception->getMessage(),
            ]], $exception->statusCode, $headers);
        }
    }
}
