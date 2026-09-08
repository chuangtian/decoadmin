<?php

namespace App\Http\Controllers;

use App\Exceptions\InstagramFeedException;
use App\Models\Organization;
use App\Models\Store;
use App\Services\InstagramFeed\InstagramFeedOAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * instagram-feed App 的 Shopify 授权码授权入口。
 *
 * 与 Commerce Hub 的 /shopify/oauth/callback 同构：发起端在 DecoAdmin 后台（需要登录
 * 与 instagram_feed.connect 权限），回调端由 Shopify 直接把浏览器打回来，因此不能挂
 * auth，只能靠 state cookie + oauth_states 记录 + HMAC 验签确认身份。
 */
class InstagramFeedShopifyOAuthController extends Controller
{
    public function redirect(
        Request $request,
        Organization $organization,
        Store $store,
        InstagramFeedOAuthService $oauth,
    ): SymfonyResponse {
        $this->assertStoreScope($request, $organization, $store);

        try {
            $authorization = $oauth->begin($organization, $request->user(), $store);
        } catch (InstagramFeedException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $cookie = new Cookie(
            InstagramFeedOAuthService::STATE_COOKIE,
            $authorization['state'],
            now()->addMinutes((int) config('instagram_feed.oauth_state_ttl_minutes', config('shopify.state_ttl_minutes', 10))),
            '/',
            null,
            str_starts_with($authorization['redirect_uri'], 'https://'),
            true,
            false,
            Cookie::SAMESITE_LAX,
        );

        return Inertia::location($authorization['authorization_url'])->withCookie($cookie);
    }

    public function callback(Request $request, InstagramFeedOAuthService $oauth): RedirectResponse
    {
        try {
            $result = $oauth->complete(
                $request->query(),
                $request->cookie(InstagramFeedOAuthService::STATE_COOKIE),
            );
        } catch (InstagramFeedException $exception) {
            abort($exception->statusCode === 403 ? 403 : 400, $exception->getMessage());
        }

        return redirect()->to($result['intended_url'])
            ->withCookie(Cookie::create(InstagramFeedOAuthService::STATE_COOKIE)->withValue('')->withExpires(1))
            ->with('success', 'Instagram Feed App 的 Shopify 授权已完成。');
    }

    public function verify(
        Request $request,
        Organization $organization,
        Store $store,
        InstagramFeedOAuthService $oauth,
    ): RedirectResponse {
        $this->assertStoreScope($request, $organization, $store);

        $result = $oauth->verify($store, $request->user());

        return back()->with(
            $result['status'] === 'connected' ? 'success' : 'error',
            $result['message'],
        );
    }

    private function assertStoreScope(Request $request, Organization $organization, Store $store): void
    {
        abort_unless($store->organization_id === $organization->id, 403);
        abort_unless($request->user()->canAccessStore($store), 403);
        abort_unless($request->user()->hasPermission('instagram_feed.connect', $organization, $store), 403);
    }
}
