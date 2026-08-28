<?php

namespace App\Http\Controllers;

use App\Exceptions\PersonalizationException;
use App\Services\Personalization\ShopifyPersonalizationAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ShopifyPersonalizationAppController extends Controller
{
    public function management(Request $request, ShopifyPersonalizationAppService $app): RedirectResponse
    {
        try {
            $app->managementStore($request->user(), (string) $request->query('shop'));
        } catch (PersonalizationException $exception) {
            abort($exception->statusCode, $exception->getMessage());
        }

        // The dedicated management UI is introduced in a later stage. Until then,
        // a verified launch lands in the existing application center.
        return redirect()->route('app-center.index');
    }

    public function connection(Request $request, ShopifyPersonalizationAppService $app): JsonResponse
    {
        try {
            $store = $app->connectedStore((string) $request->attributes->get('shopify_shop'));
        } catch (PersonalizationException $exception) {
            return $this->error($exception);
        }

        return response()->json(['data' => [
            'connected' => (bool) $store,
            'environment' => (string) config('personalization.environment'),
            'store' => $store ? [
                'id' => $store->id,
                'organization_id' => $store->organization_id,
                'name' => $store->name,
                'shopify_domain' => $store->shopify_domain,
                'status' => $store->status,
            ] : null,
        ]]);
    }

    public function bootstrap(Request $request, ShopifyPersonalizationAppService $app): JsonResponse
    {
        try {
            $store = $app->connectedStore((string) $request->attributes->get('shopify_shop'));
            if (! $store) {
                throw new PersonalizationException(
                    'STORE_NOT_CONNECTED',
                    '该 Shopify 店铺尚未连接 DecoAdmin Commerce Hub。',
                    409,
                );
            }
            $result = $app->bootstrap($store, (string) $request->attributes->get('shopify_id_token'));
        } catch (PersonalizationException $exception) {
            return $this->error($exception);
        }

        return response()->json(['data' => [
            'connected' => true,
            'environment' => (string) config('personalization.environment'),
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
                'shopify_domain' => $store->shopify_domain,
            ],
            'app_installation_id' => $result['app_installation_id'],
            'granted_scopes' => $result['granted_scopes'],
        ]]);
    }

    private function error(PersonalizationException $exception): JsonResponse
    {
        $headers = $exception->statusCode === 401
            ? ['X-Shopify-Retry-Invalid-Session-Request' => '1']
            : [];

        return response()->json(['error' => [
            'code' => $exception->errorCode,
            'message' => $exception->getMessage(),
        ]], $exception->statusCode, $headers);
    }
}
