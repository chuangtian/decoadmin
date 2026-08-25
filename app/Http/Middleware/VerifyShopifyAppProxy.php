<?php

namespace App\Http\Middleware;

use App\Models\Store;
use App\Support\CurrentOrganization;
use App\Support\CurrentStore;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyShopifyAppProxy
{
    public function __construct(
        private CurrentOrganization $currentOrganization,
        private CurrentStore $currentStore,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $signature = (string) $request->query('signature', '');
        $shop = strtolower((string) $request->query('shop', ''));
        $timestamp = $request->query('timestamp');
        $secret = (string) config('student_discount.active.client_secret', '');

        if ($signature === '' || $secret === '' || ! is_numeric($timestamp)
            || abs(now()->timestamp - (int) $timestamp) > 300
            || preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/', $shop) !== 1) {
            return $this->unauthorized();
        }

        $parameters = $request->query();
        unset($parameters['signature']);
        $pieces = [];
        foreach ($parameters as $key => $value) {
            $values = is_array($value) ? $value : [$value];
            $pieces[] = $key.'='.implode(',', array_map('strval', $values));
        }
        sort($pieces, SORT_STRING);
        $calculated = hash_hmac('sha256', implode('', $pieces), $secret);
        if (! hash_equals($signature, $calculated)) {
            return $this->unauthorized();
        }

        $store = Store::query()
            ->where('shopify_domain', $shop)
            ->where('status', 'active')
            ->with('organization')
            ->first();
        if (! $store || ! $store->organization || $store->organization->status !== 'active') {
            return response()->json(['error' => [
                'code' => 'STORE_NOT_AVAILABLE',
                'message' => '当前店铺未启用学生优惠服务。',
            ]], 404);
        }

        $this->currentOrganization->set($store->organization);
        $this->currentStore->set($store);
        $request->attributes->set('student_discount_store', $store);

        return $next($request);
    }

    private function unauthorized(): JsonResponse
    {
        return response()->json(['error' => [
            'code' => 'INVALID_APP_PROXY_SIGNATURE',
            'message' => '请求签名无效或已过期。',
        ]], 401);
    }
}
