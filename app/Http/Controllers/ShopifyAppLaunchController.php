<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Services\Shopify\ShopifyOAuthService;
use App\Support\CurrentOrganization;
use App\Support\CurrentStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ShopifyAppLaunchController extends Controller
{
    public function __invoke(
        Request $request,
        ShopifyOAuthService $oauth,
        CurrentOrganization $currentOrganization,
        CurrentStore $currentStore,
    ): RedirectResponse {
        try {
            $shopDomain = $oauth->normalizeShopDomain((string) $request->query('shop'));
        } catch (ValidationException) {
            abort(422, 'Shopify 未提供有效的店铺身份。');
        }

        $user = $request->user();
        $store = Store::query()
            ->where('shopify_domain', $shopDomain)
            ->whereHas('appInstallations', fn ($query) => $query->where('status', 'active'))
            ->when(! $user->isSuperAdmin(), fn ($query) => $query
                ->whereHas('members', fn ($query) => $query->whereKey($user->getKey())))
            ->with('organization')
            ->first();

        abort_unless($store && $store->organization, 403, '当前 decoAdmin 账号没有访问该 Shopify 店铺的权限，请联系管理员分配店铺。');

        $request->session()->put([
            'current_organization_id' => $store->organization_id,
            'current_store_id' => $store->getKey(),
        ]);
        $currentOrganization->set($store->organization);
        $currentStore->set($store);

        return redirect()->route('stores.marketing.home', $store);
    }
}
