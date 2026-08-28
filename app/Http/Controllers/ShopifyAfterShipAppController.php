<?php

namespace App\Http\Controllers;

use App\Exceptions\ShopifyOAuthException;
use App\Models\Store;
use App\Services\AfterShip\AfterShipAppRegistryService;
use App\Services\Shopify\ShopifyOAuthService;
use App\Support\CurrentOrganization;
use App\Support\CurrentStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

class ShopifyAfterShipAppController extends Controller
{
    public function launch(
        Request $request,
        ShopifyOAuthService $oauth,
        AfterShipAppRegistryService $registry,
        CurrentOrganization $currentOrganization,
        CurrentStore $currentStore,
    ): Response {
        try {
            $shopDomain = $oauth->normalizeShopDomain((string) $request->query('shop'));
            $app = $registry->configuredApp();
        } catch (ValidationException) {
            abort(422, 'Shopify 未提供有效的店铺身份。');
        } catch (ShopifyOAuthException $exception) {
            abort(503, $exception->getMessage());
        }

        $user = $request->user();
        abort_unless($user, 401);
        $store = Store::query()
            ->where('shopify_domain', $shopDomain)
            ->where('status', 'active')
            ->whereHas('organization', fn ($query) => $query->where('status', 'active'))
            ->when(! $user->isSuperAdmin(), fn ($query) => $query
                ->whereHas('members', fn ($query) => $query->whereKey($user->getKey())))
            ->with(['organization', 'shopifyConnection'])
            ->first();

        abort_unless($store && $store->organization, 403, '当前 DecoAdmin 账号没有访问该 Shopify 店铺的权限，请联系管理员分配店铺。');

        $request->session()->put([
            'current_organization_id' => $store->organization_id,
            'current_store_id' => $store->getKey(),
        ]);
        $currentOrganization->set($store->organization);
        $currentStore->set($store);

        $installed = $store->appInstallations()
            ->where('app_id', $app->getKey())
            ->where('status', 'active')
            ->whereNotNull('access_token_encrypted')
            ->exists();
        if ($installed) {
            return redirect()->route('stores.marketing.home', $store);
        }

        $this->authorize('connect', $store);
        abort_unless(
            $store->shopifyConnection && in_array($store->shopifyConnection->status, ['connected', 'warning'], true),
            409,
            '请先在 DecoAdmin 为当前店铺建立有效的 Shopify Commerce Hub 连接。',
        );

        try {
            $authorization = $oauth->beginForApp($store->organization, $user, $store, $app);
        } catch (ShopifyOAuthException $exception) {
            abort(503, $exception->getMessage());
        }

        return redirect()->away($authorization['authorization_url'])
            ->withCookie($this->stateCookie($authorization));
    }

    public function callback(
        Request $request,
        ShopifyOAuthService $oauth,
    ): RedirectResponse {
        try {
            $store = $oauth->complete(
                $request->query(),
                $request->cookie(ShopifyOAuthService::STATE_COOKIE),
                (string) config('aftership.active.handle'),
            );
        } catch (ShopifyOAuthException|ValidationException) {
            abort(403, 'Shopify OAuth 回调验证失败。');
        }

        $request->session()->put([
            'current_organization_id' => $store->organization_id,
            'current_store_id' => $store->getKey(),
        ]);

        return redirect()->route('stores.marketing.home', $store)
            ->withCookie(Cookie::create(ShopifyOAuthService::STATE_COOKIE)->withValue('')->withExpires(1))
            ->with('success', 'Deco AfterShip 已连接当前 Shopify 店铺。');
    }

    /** @param array{state: string, state_record: mixed} $authorization */
    private function stateCookie(array $authorization): Cookie
    {
        $secure = str_starts_with((string) $authorization['state_record']->redirect_uri, 'https://');

        return new Cookie(
            ShopifyOAuthService::STATE_COOKIE,
            $authorization['state'],
            now()->addMinutes((int) config('shopify.state_ttl_minutes', 10)),
            '/',
            null,
            $secure,
            true,
            false,
            Cookie::SAMESITE_LAX,
        );
    }
}
