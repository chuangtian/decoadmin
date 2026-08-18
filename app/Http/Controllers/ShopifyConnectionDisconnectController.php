<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Services\Shopify\ShopifyConnectionLifecycleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ShopifyConnectionDisconnectController extends Controller
{
    public function __invoke(
        Request $request,
        Store $store,
        ShopifyConnectionLifecycleService $lifecycle,
    ): RedirectResponse {
        $this->authorize('disconnect', $store);
        $connection = $store->shopifyConnection;

        if (! $connection) {
            return back()->with('error', '当前店铺尚未建立 Shopify Connection。');
        }

        if ($connection->status === 'disconnected') {
            return back()->with('info', '当前 Shopify Connection 已处于断开状态。');
        }

        $lifecycle->markDisconnected(
            $connection,
            '管理员主动断开 Shopify 连接。',
            $request->user(),
        );

        return back()->with('success', 'Shopify Connection 已断开，历史记录和授权信息已保留。');
    }
}
