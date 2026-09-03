<?php

namespace App\Http\Controllers;

use App\Exceptions\ShopifyOAuthException;
use App\Services\Shopify\ShopifyOAuthService;
use App\Support\CurrentOrganization;
use App\Support\CurrentStore;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

class DiscountManagerOAuthController extends Controller
{
    public function redirect(Request $request, CurrentOrganization $organizations, CurrentStore $stores, ShopifyOAuthService $oauth): Response
    {
        $store = $stores->require();
        $organization = $organizations->require();
        $values = $request->validate(['store_id' => ['required', 'integer']]);
        abort_unless((int) $values['store_id'] === (int) $store->id, 409, '当前店铺已切换，请刷新后重试。');
        abort_unless((int) $store->organization_id === (int) $organization->id && $request->user()->canAccessStore($store), 403);
        abort_unless($request->user()->hasPermission('discounts.manage', $organization, $store), 403);
        $this->authorize('connect', $store);
        try {
            $authorization = $oauth->begin($organization, $request->user(), $store, ['read_discounts', 'write_discounts']);
        } catch (ShopifyOAuthException $exception) {
            return back()->with('error', $exception->getMessage());
        }
        $cookie = new Cookie(ShopifyOAuthService::STATE_COOKIE, $authorization['state'],
            now()->addMinutes((int) config('shopify.state_ttl_minutes', 10)), '/', null,
            str_starts_with($authorization['state_record']->redirect_uri, 'https://'), true, false, Cookie::SAMESITE_LAX);

        return Inertia::location($authorization['authorization_url'])->withCookie($cookie);
    }
}
