<?php

namespace App\Services\Shopify;

use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\Location;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ShopifyDataQueryService
{
    /** @return LengthAwarePaginator<Product> */
    public function products(Store $store, ?string $search, ?string $status): LengthAwarePaginator
    {
        return Product::query()
            ->forOrganization($store->organization_id)
            ->forStore($store)
            ->when($search, fn ($query) => $query->where(fn ($query) => $query
                ->where('title', 'like', "%{$search}%")
                ->orWhere('handle', 'like', "%{$search}%")
                ->orWhere('vendor', 'like', "%{$search}%")))
            ->when($status, fn ($query) => $query->where('status', $status))
            ->withCount('variants')
            ->with(['monitor' => fn ($query) => $query->where('organization_id', $store->organization_id)->where('store_id', $store->id)
                ->select(['id', 'product_id', 'is_enabled', 'low_stock_threshold', 'last_checked_at', 'last_success_at', 'last_error'])])
            ->withMin('variants', 'price')
            ->withMax('variants', 'price')
            ->latest('synced_at')
            ->paginate(25)
            ->withQueryString();
    }

    public function product(Store $store, int $id): Product
    {
        return Product::query()
            ->forOrganization($store->organization_id)
            ->forStore($store)
            ->with(['variants.inventoryItem.levels.location'])
            ->findOrFail($id);
    }

    /** @return LengthAwarePaginator<Order> */
    public function orders(Store $store, ?string $search, ?string $financialStatus, ?string $fulfillmentStatus = null): LengthAwarePaginator
    {
        return Order::query()
            ->forOrganization($store->organization_id)
            ->forStore($store)
            ->when($search, fn ($query) => $query->where(fn ($query) => $query
                ->where('order_number', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")))
            ->when($financialStatus, fn ($query) => $query->where('financial_status', $financialStatus))
            ->when($fulfillmentStatus === 'unfulfilled', fn ($query) => $query
                ->where(fn ($query) => $query->whereNull('fulfillment_status')->orWhere('fulfillment_status', 'unfulfilled')))
            ->when($fulfillmentStatus && $fulfillmentStatus !== 'unfulfilled', fn ($query) => $query->where('fulfillment_status', $fulfillmentStatus))
            ->withCount('items')
            ->latest('created_at_shopify')
            ->paginate(25)
            ->withQueryString();
    }

    public function order(Store $store, int $id): Order
    {
        return Order::query()
            ->forOrganization($store->organization_id)
            ->forStore($store)
            ->with(['items.product:id,title,handle', 'items.variant:id,title,sku'])
            ->findOrFail($id);
    }

    /** @return LengthAwarePaginator<Customer> */
    public function customers(Store $store, ?string $search, ?string $state): LengthAwarePaginator
    {
        return Customer::query()
            ->forOrganization($store->organization_id)
            ->forStore($store)
            ->when($search, fn ($query) => $query->where(fn ($query) => $query
                ->where('first_name', 'like', "%{$search}%")
                ->orWhere('last_name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")))
            ->when($state, fn ($query) => $query->where('state', $state))
            ->latest('updated_at_shopify')
            ->paginate(25)
            ->withQueryString();
    }

    public function customer(Store $store, int $id): Customer
    {
        return Customer::query()
            ->forOrganization($store->organization_id)
            ->forStore($store)
            ->findOrFail($id);
    }

    /** @return LengthAwarePaginator<InventoryItem> */
    public function inventory(Store $store, ?string $search, ?bool $tracked): LengthAwarePaginator
    {
        return InventoryItem::query()
            ->forOrganization($store->organization_id)
            ->forStore($store)
            ->when($search, fn ($query) => $query->where(fn ($query) => $query
                ->where('sku', 'like', "%{$search}%")
                ->orWhereHas('variant', fn ($query) => $query
                    ->where('title', 'like', "%{$search}%")
                    ->orWhereHas('product', fn ($query) => $query->where('title', 'like', "%{$search}%")))))
            ->when($tracked !== null, fn ($query) => $query->where('tracked', $tracked))
            ->with(['variant.product:id,title,handle'])
            ->withCount('levels')
            ->withSum('levels as available_total', 'available')
            ->latest('synced_at')
            ->paginate(25)
            ->withQueryString();
    }

    public function inventoryItem(Store $store, int $id): InventoryItem
    {
        return InventoryItem::query()
            ->forOrganization($store->organization_id)
            ->forStore($store)
            ->with(['variant.product:id,title,handle', 'levels.location'])
            ->findOrFail($id);
    }

    /** @return LengthAwarePaginator<Location> */
    public function locations(Store $store, ?string $search, ?bool $active): LengthAwarePaginator
    {
        return Location::query()
            ->forOrganization($store->organization_id)
            ->forStore($store)
            ->when($search, fn ($query) => $query->where('name', 'like', "%{$search}%"))
            ->when($active !== null, fn ($query) => $query->where('active', $active))
            ->withCount('inventoryLevels')
            ->withSum('inventoryLevels as available_total', 'available')
            ->orderByDesc('active')
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();
    }

    public function location(Store $store, int $id): Location
    {
        return Location::query()
            ->forOrganization($store->organization_id)
            ->forStore($store)
            ->with(['inventoryLevels.inventoryItem.variant.product'])
            ->findOrFail($id);
    }
}
