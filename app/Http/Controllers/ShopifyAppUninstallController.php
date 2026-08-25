<?php

namespace App\Http\Controllers;

use App\Exceptions\ShopifyApiException;
use App\Models\Store;
use App\Services\Shopify\ShopifyAppUninstallService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ShopifyAppUninstallController extends Controller
{
    public function __invoke(
        Request $request,
        Store $store,
        ShopifyAppUninstallService $uninstaller,
    ): RedirectResponse {
        $this->authorize('disconnect', $store);

        try {
            $uninstaller->uninstall($store, $request->user());
        } catch (ShopifyApiException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Shopify 应用已真正卸载，访问权限和令牌已撤销。');
    }
}
