<?php

namespace App\Http\Controllers;

use App\Services\AnalyticsQueryService;
use App\Support\CurrentOrganization;
use App\Support\CurrentStore;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AnalyticsController extends Controller
{
    public function sales(Request $request, CurrentStore $currentStore, AnalyticsQueryService $analytics): Response
    {
        $store = $currentStore->require();
        $this->authorize('view', $store);
        $days = (int) $request->integer('days', 30);

        return Inertia::render('Analytics/Sales', [
            'store' => ['id' => $store->id, 'name' => $store->name, 'currency' => $store->currency ?: 'USD'],
            'analytics' => $analytics->sales($store, $days),
        ]);
    }

    public function stores(Request $request, CurrentOrganization $currentOrganization, AnalyticsQueryService $analytics): Response
    {
        $organization = $currentOrganization->require();
        $days = (int) $request->integer('days', 30);

        return Inertia::render('Analytics/Stores', [
            'organization' => ['id' => $organization->id, 'name' => $organization->name],
            'comparison' => $analytics->storeComparison($organization, $request->user(), $days),
        ]);
    }
}
