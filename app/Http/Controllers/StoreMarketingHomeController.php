<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Services\Marketing\MarketingModuleCatalog;
use Inertia\Inertia;
use Inertia\Response;

class StoreMarketingHomeController extends Controller
{
    public function __invoke(Store $store, MarketingModuleCatalog $catalog): Response
    {
        $this->authorize('view', $store);
        $installation = $store->appInstallations()
            ->where('status', 'active')
            ->whereNotNull('access_token_encrypted')
            ->whereHas('app', fn ($query) => $query->where('handle', config('aftership.active.handle')))
            ->first();

        abort_unless($installation, 403, '当前店铺尚未安装 Deco Marketing 应用。');

        return Inertia::render('Marketing/Home', [
            'store' => [
                'id' => $store->getKey(),
                'name' => $store->name,
                'shopify_domain' => $store->shopify_domain,
            ],
            'modules' => $catalog->forInstallation($installation),
        ]);
    }
}
