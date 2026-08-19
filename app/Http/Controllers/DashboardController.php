<?php

namespace App\Http\Controllers;

use App\Services\DashboardMetricsService;
use App\Support\CurrentOrganization;
use App\Support\CurrentStore;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, CurrentOrganization $currentOrganization, CurrentStore $currentStore, DashboardMetricsService $metrics): Response
    {
        return Inertia::render('Dashboard/Index', [
            'dashboard' => $currentStore->get()
                ? $metrics->forStore($currentStore->get(), $currentOrganization->get(), $request->user())
                : $metrics->empty(),
        ]);
    }
}
