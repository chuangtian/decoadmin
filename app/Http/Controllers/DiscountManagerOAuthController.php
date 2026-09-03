<?php

namespace App\Http\Controllers;

use App\Exceptions\DiscountManagerException;
use App\Services\Discounts\DiscountManagerOAuthService;
use App\Support\CurrentOrganization;
use App\Support\CurrentStore;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

class DiscountManagerOAuthController extends Controller
{
    public function redirect(
        Request $request,
        CurrentOrganization $currentOrganization,
        CurrentStore $currentStore,
        DiscountManagerOAuthService $oauth,
    ): Response {
        $organization = $currentOrganization->require();
        $store = $currentStore->require();
        abort_unless($request->user()->hasPermission('discounts.manage', $organization, $store), 403);
        try {
            $authorization = $oauth->begin($organization, $request->user(), $store);
        } catch (DiscountManagerException $exception) {
            return back()->with('error', $exception->getMessage());
        }
        $cookie = new Cookie(
            DiscountManagerOAuthService::STATE_COOKIE,
            $authorization['state'],
            now()->addMinutes(10),
            '/',
            null,
            true,
            true,
            false,
            Cookie::SAMESITE_LAX,
        );

        return redirect()->away($authorization['authorization_url'])->withCookie($cookie);
    }

    public function callback(Request $request, DiscountManagerOAuthService $oauth): Response
    {
        try {
            $oauth->complete($request->query(), $request->cookie(DiscountManagerOAuthService::STATE_COOKIE));
        } catch (DiscountManagerException) {
            abort(403, '折扣管理 Shopify OAuth 回调验证失败。');
        }

        return redirect()->route('discounts.index')
            ->withCookie(Cookie::create(DiscountManagerOAuthService::STATE_COOKIE)->withValue('')->withExpires(1))
            ->with('success', '当前店铺的 Shopify 折扣管理已连接。');
    }
}
