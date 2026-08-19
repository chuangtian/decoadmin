<?php

namespace App\Http\Controllers;

use App\Services\DashboardMetricsService;
use App\Support\CurrentStore;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(CurrentStore $currentStore, DashboardMetricsService $metrics): Response
    {
        return Inertia::render('Dashboard/Index', [
            'dashboard' => $currentStore->get()
                ? $metrics->forStore($currentStore->get())
                : $metrics->empty(),
        ]);
    }
}
