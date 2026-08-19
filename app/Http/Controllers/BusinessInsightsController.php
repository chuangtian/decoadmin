<?php

namespace App\Http\Controllers;

use App\Http\Requests\AnalyticsFilterRequest;
use App\Services\BusinessInsightsService;
use App\Support\CurrentStore;
use Inertia\Inertia;
use Inertia\Response;

class BusinessInsightsController extends Controller
{
    public function __invoke(
        AnalyticsFilterRequest $request,
        CurrentStore $currentStore,
        BusinessInsightsService $insights,
    ): Response {
        $store = $currentStore->require();
        $this->authorize('view', $store);

        return Inertia::render('Business/Insights', [
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
                'currency' => $store->currency ?: 'USD',
                'timezone' => $store->timezone ?: 'UTC',
            ],
            'insights' => $insights->overview($store, $request->filters()),
        ]);
    }
}
