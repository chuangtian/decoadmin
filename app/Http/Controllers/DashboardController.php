<?php

namespace App\Http\Controllers;

use App\Http\Requests\AnalyticsFilterRequest;
use App\Services\DashboardMetricsService;
use App\Support\CurrentOrganization;
use App\Support\CurrentStore;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(AnalyticsFilterRequest $request, CurrentOrganization $currentOrganization, CurrentStore $currentStore, DashboardMetricsService $metrics): Response
    {
        return Inertia::render('Dashboard/Index', [
            'dashboard' => $currentStore->get()
                ? $metrics->forStore($currentStore->get(), $currentOrganization->get(), $request->user(), $request->filters())
                : $metrics->empty(),
        ]);
    }
}
