<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Services\Shopify\ShopifyConnectionHealthService;
use Illuminate\Http\RedirectResponse;

class ShopifyConnectionHealthController extends Controller
{
    public function __invoke(Store $store, ShopifyConnectionHealthService $health): RedirectResponse
    {
        $this->authorize('connect', $store);
        $connection = $store->shopifyConnection;

        if (! $connection) {
            return back()->with('error', '当前店铺尚未建立 Shopify Connection。');
        }

        $result = $health->check($connection);

        return back()->with(
            $result['success'] ? 'success' : 'error',
            $result['message'],
        );
    }
}
