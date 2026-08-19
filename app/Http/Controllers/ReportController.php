<?php

namespace App\Http\Controllers;

use App\Http\Requests\AnalyticsFilterRequest;
use App\Services\ReportCenterService;
use App\Support\CurrentStore;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function index(AnalyticsFilterRequest $request, CurrentStore $currentStore, ReportCenterService $reports): Response
    {
        $store = $currentStore->require();
        $this->authorize('view', $store);

        return Inertia::render('Reports/Index', [
            'store' => ['id' => $store->id, 'name' => $store->name, 'currency' => $store->currency ?: 'USD'],
            'report' => $reports->report($store, $request->filters()),
            'canExport' => Gate::allows('permission', 'reports.export'),
        ]);
    }

    public function export(AnalyticsFilterRequest $request, string $format, CurrentStore $currentStore, ReportCenterService $reports): StreamedResponse
    {
        abort_unless(in_array($format, ['csv', 'excel'], true), 404);
        $store = $currentStore->require();
        $this->authorize('view', $store);

        return $reports->export($store, $request->filters(), $format);
    }
}
