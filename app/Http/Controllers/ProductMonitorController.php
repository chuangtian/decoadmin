<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\Shopify\Products\ShopifyProductMonitorService;
use App\Support\CurrentOrganization;
use App\Support\CurrentStore;
use Illuminate\Http\Request;

class ProductMonitorController extends Controller
{
    public function update(Request $request, int $product, CurrentOrganization $organizations, CurrentStore $stores, ShopifyProductMonitorService $monitors)
    {
        $store = $stores->require();
        abort_unless((int) $store->organization_id === (int) $organizations->require()->id, 403);
        $data = $request->validate(['is_enabled' => ['required', 'boolean'], 'low_stock_threshold' => ['required', 'integer', 'min:0', 'max:1000000']]);
        $record = Product::forOrganization($store->organization_id)->forStore($store)->findOrFail($product);
        $monitors->configure($store, $request->user(), $record, $data['is_enabled'], $data['low_stock_threshold']);

        return back()->with('success', $data['is_enabled'] ? '已设为重点；首次检查建立基线，后续异常或状态变化将通知。' : '已取消重点监控。');
    }
}
