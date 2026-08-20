<?php

namespace App\Http\Controllers;

use App\Http\Resources\StoreResource;
use App\Support\CurrentStore;
use Inertia\Inertia;
use Inertia\Response;

class StoreStatusController extends Controller
{
    public function __invoke(CurrentStore $currentStore): Response
    {
        $store = $currentStore->require();
        $this->authorize('view', $store);

        $store->load(['shopifyConnection', 'appInstallations.app', 'latestSyncJob'])
            ->loadCount(['appInstallations as installed_apps_count' => fn ($query) => $query->where('status', 'active')]);

        return Inertia::render('Stores/Settings/Status', [
            'store' => new StoreResource($store),
        ]);
    }
}
