<?php

namespace App\Http\Controllers;

use App\Exceptions\ShopifyOAuthException;
use App\Services\Shopify\ShopifyOAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Cookie;

class ShopifyOAuthController extends Controller
{
    public function callback(Request $request, ShopifyOAuthService $oauth): RedirectResponse
    {
        try {
            $store = $oauth->complete(
                $request->query(),
                $request->cookie(ShopifyOAuthService::STATE_COOKIE),
            );
        } catch (ShopifyOAuthException|ValidationException) {
            abort(403, 'Shopify OAuth 回调验证失败。');
        }

        return to_route('stores.show', $store)
            ->withCookie(Cookie::create(ShopifyOAuthService::STATE_COOKIE)->withValue('')->withExpires(1))
            ->with('success', 'Shopify 店铺连接成功。');
    }
}
