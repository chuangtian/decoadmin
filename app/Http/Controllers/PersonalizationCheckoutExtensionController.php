<?php

namespace App\Http\Controllers;

use App\Exceptions\PersonalizationException;
use App\Models\Store;
use App\Services\Personalization\PersonalizationCheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PersonalizationCheckoutExtensionController extends Controller
{
    public function __invoke(Request $request, PersonalizationCheckoutService $checkout): JsonResponse
    {
        $shop = (string) $request->attributes->get('shopify_shop', '');
        $store = Store::query()
            ->whereRaw('LOWER(shopify_domain) = ?', [strtolower($shop)])
            ->with('organization')
            ->first();
        if (! $store) {
            return $this->response(['error' => [
                'code' => 'STORE_NOT_AVAILABLE',
                'message' => '当前店铺未启用 Checkout 个性化推荐。',
            ]], 404);
        }

        try {
            return $this->response(['data' => $checkout->storefront($store)]);
        } catch (PersonalizationException $exception) {
            return $this->response(['error' => [
                'code' => $exception->errorCode,
                'message' => $exception->getMessage(),
            ]], $exception->statusCode);
        } catch (\Throwable) {
            return $this->response(['error' => [
                'code' => 'PERSONALIZATION_CHECKOUT_UNAVAILABLE',
                'message' => 'Checkout 个性化推荐暂时不可用。',
            ]], 503);
        }
    }

    /** @param array<string, mixed> $payload */
    private function response(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status, [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Headers' => 'Authorization, Content-Type',
            'Access-Control-Allow-Methods' => 'GET, OPTIONS',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
