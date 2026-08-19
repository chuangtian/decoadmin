<?php

namespace App\Http\Controllers;

use App\Http\Requests\AnalyticsFilterRequest;
use App\Services\AnalyticsOverviewInsightsService;
use App\Services\AnalyticsQueryService;
use App\Support\CurrentOrganization;
use App\Support\CurrentStore;
use Inertia\Inertia;
use Inertia\Response;

class AnalyticsController extends Controller
{
    public function overview(
        AnalyticsFilterRequest $request,
        CurrentStore $currentStore,
        AnalyticsQueryService $analytics,
        AnalyticsOverviewInsightsService $insights,
    ): Response {
        $store = $currentStore->require();
        $this->authorize('view', $store);
        $filters = $request->filters();
        $overview = $analytics->operationsOverview($store, $filters);

        return Inertia::render('Analytics/Overview', [
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
                'currency' => $store->currency ?: 'USD',
                'timezone' => $store->timezone ?: 'UTC',
            ],
            'overview' => $overview,
            'insights' => $insights->forStore($store, $overview['period'], $overview['customers'], $filters),
        ]);
    }

    public function sales(AnalyticsFilterRequest $request, CurrentStore $currentStore, AnalyticsQueryService $analytics): Response
    {
        $store = $currentStore->require();
        $this->authorize('view', $store);

        return Inertia::render('Analytics/Sales', [
            'store' => ['id' => $store->id, 'name' => $store->name, 'currency' => $store->currency ?: 'USD'],
            'analytics' => $analytics->sales($store, $request->filters()),
        ]);
    }

    public function stores(AnalyticsFilterRequest $request, CurrentOrganization $currentOrganization, AnalyticsQueryService $analytics): Response
    {
        $organization = $currentOrganization->require();

        return Inertia::render('Analytics/Stores', [
            'organization' => ['id' => $organization->id, 'name' => $organization->name],
            'comparison' => $analytics->storeComparison($organization, $request->user(), $request->filters()),
        ]);
    }
}
