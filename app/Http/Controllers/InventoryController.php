<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Services\Shopify\ShopifyDataQueryService;
use App\Support\CurrentOrganization;
use App\Support\CurrentStore;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InventoryController extends Controller
{
    public function __construct(
        private CurrentOrganization $currentOrganization,
        private CurrentStore $currentStore,
        private ShopifyDataQueryService $queries,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'tracked' => ['nullable', 'in:0,1'],
        ]);
        $tracked = array_key_exists('tracked', $filters) && $filters['tracked'] !== null
            ? (bool) ((int) $filters['tracked'])
            : null;

        return Inertia::render('Inventory/Index', [
            'inventory' => $this->queries->inventory(
                $this->store(),
                $filters['search'] ?? null,
                $tracked,
            ),
            'filters' => $filters,
        ]);
    }

    public function show(int $inventoryItem): Response
    {
        return Inertia::render('Inventory/Show', [
            'inventoryItem' => $this->queries->inventoryItem($this->store(), $inventoryItem),
        ]);
    }

    private function store(): Store
    {
        $organization = $this->currentOrganization->require();
        $store = $this->currentStore->require();

        abort_unless((int) $store->organization_id === (int) $organization->getKey(), 403);

        return $store;
    }
}
