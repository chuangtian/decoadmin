<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Services\Shopify\ShopifyDataPrivacyService;
use App\Services\Shopify\ShopifyDataQueryService;
use App\Support\CurrentOrganization;
use App\Support\CurrentStore;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ShopifyDataController extends Controller
{
    public function __construct(
        private CurrentOrganization $currentOrganization,
        private CurrentStore $currentStore,
        private ShopifyDataQueryService $queries,
        private ShopifyDataPrivacyService $privacy,
    ) {}

    public function products(Request $request): Response
    {
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', 'in:active,draft,archived']]);

        return Inertia::render('Products/Index', [
            'products' => $this->queries->products($this->store(), $filters['search'] ?? null, $filters['status'] ?? null),
            'filters' => $filters,
        ]);
    }

    public function product(int $product): Response
    {
        return Inertia::render('Products/Show', ['product' => $this->queries->product($this->store(), $product)]);
    }

    public function orders(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'financial_status' => ['nullable', 'string', 'max:40'],
            'fulfillment_status' => ['nullable', 'in:fulfilled,partial,restocked,unfulfilled'],
        ]);

        return Inertia::render('Orders/Index', [
            'orders' => $this->privacy->maskOrders($this->queries->orders(
                $this->store(),
                $filters['search'] ?? null,
                $filters['financial_status'] ?? null,
                $filters['fulfillment_status'] ?? null,
            )),
            'filters' => $filters,
        ]);
    }

    public function order(int $order): Response
    {
        return Inertia::render('Orders/Show', ['order' => $this->privacy->maskOrder($this->queries->order($this->store(), $order))]);
    }

    public function customers(Request $request): Response
    {
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'state' => ['nullable', 'string', 'max:40']]);

        return Inertia::render('Customers/Index', [
            'customers' => $this->privacy->maskCustomers(
                $this->queries->customers($this->store(), $filters['search'] ?? null, $filters['state'] ?? null),
            ),
            'filters' => $filters,
        ]);
    }

    public function customer(int $customer): Response
    {
        return Inertia::render('Customers/Show', ['customer' => $this->privacy->maskCustomer($this->queries->customer($this->store(), $customer))]);
    }

    public function locations(Request $request): Response
    {
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'active' => ['nullable', 'in:0,1']]);
        $active = array_key_exists('active', $filters) && $filters['active'] !== null
            ? (bool) ((int) $filters['active'])
            : null;

        return Inertia::render('Locations/Index', [
            'locations' => $this->queries->locations($this->store(), $filters['search'] ?? null, $active),
            'filters' => $filters,
        ]);
    }

    public function location(int $location): Response
    {
        return Inertia::render('Locations/Show', ['location' => $this->queries->location($this->store(), $location)]);
    }

    private function store(): Store
    {
        $organization = $this->currentOrganization->require();
        $store = $this->currentStore->require();

        abort_unless((int) $store->organization_id === (int) $organization->getKey(), 403);

        return $store;
    }
}
