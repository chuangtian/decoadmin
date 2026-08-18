<?php

namespace App\Http\Controllers;

use App\Services\Shopify\ShopifyDataQueryService;
use App\Support\CurrentStore;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ShopifyDataController extends Controller
{
    public function __construct(
        private CurrentStore $currentStore,
        private ShopifyDataQueryService $queries,
    ) {}

    public function products(Request $request): Response
    {
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', 'in:active,draft,archived']]);

        return Inertia::render('Products/Index', [
            'products' => $this->queries->products($this->currentStore->require(), $filters['search'] ?? null, $filters['status'] ?? null),
            'filters' => $filters,
        ]);
    }

    public function product(int $product): Response
    {
        return Inertia::render('Products/Show', ['product' => $this->queries->product($this->currentStore->require(), $product)]);
    }

    public function orders(Request $request): Response
    {
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'financial_status' => ['nullable', 'string', 'max:40']]);

        return Inertia::render('Orders/Index', [
            'orders' => $this->queries->orders($this->currentStore->require(), $filters['search'] ?? null, $filters['financial_status'] ?? null),
            'filters' => $filters,
        ]);
    }

    public function order(int $order): Response
    {
        return Inertia::render('Orders/Show', ['order' => $this->queries->order($this->currentStore->require(), $order)]);
    }

    public function customers(Request $request): Response
    {
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'state' => ['nullable', 'string', 'max:40']]);

        return Inertia::render('Customers/Index', [
            'customers' => $this->queries->customers($this->currentStore->require(), $filters['search'] ?? null, $filters['state'] ?? null),
            'filters' => $filters,
        ]);
    }

    public function customer(int $customer): Response
    {
        return Inertia::render('Customers/Show', ['customer' => $this->queries->customer($this->currentStore->require(), $customer)]);
    }

    public function inventory(Request $request): Response
    {
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'tracked' => ['nullable', 'in:0,1']]);
        $tracked = array_key_exists('tracked', $filters) && $filters['tracked'] !== null
            ? (bool) ((int) $filters['tracked'])
            : null;

        return Inertia::render('Inventory/Index', [
            'inventory' => $this->queries->inventory($this->currentStore->require(), $filters['search'] ?? null, $tracked),
            'filters' => $filters,
        ]);
    }

    public function inventoryItem(int $inventoryItem): Response
    {
        return Inertia::render('Inventory/Show', ['inventoryItem' => $this->queries->inventoryItem($this->currentStore->require(), $inventoryItem)]);
    }
}
