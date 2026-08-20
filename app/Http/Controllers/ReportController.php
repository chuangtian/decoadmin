<?php

namespace App\Http\Controllers;

use App\Http\Requests\AnalyticsFilterRequest;
use App\Services\ReportCenterService;
use App\Support\CurrentStore;
use Illuminate\Http\RedirectResponse;
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
            'reports' => $reports->catalog($store, $request->user()),
        ]);
    }

    public function show(AnalyticsFilterRequest $request, string $report, CurrentStore $currentStore, ReportCenterService $reports): Response
    {
        $store = $currentStore->require();
        $this->authorize('view', $store);

        return Inertia::render('Reports/Show', [
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
                'currency' => $store->currency ?: 'USD',
                'timezone' => $store->timezone ?: 'UTC',
            ],
            'report' => $reports->detail($store, $report, $request->filters(), $request->user()),
            'canExport' => Gate::allows('permission', 'reports.export'),
        ]);
    }

    public function pin(AnalyticsFilterRequest $request, string $report, CurrentStore $currentStore, ReportCenterService $reports): RedirectResponse
    {
        $store = $currentStore->require();
        $this->authorize('view', $store);
        $validated = $request->validate([
            'pinned' => ['required', 'boolean'],
        ]);
        $reports->setPinned($store, $request->user(), $report, (bool) $validated['pinned']);

        return back();
    }

    public function export(AnalyticsFilterRequest $request, string $report, string $format, CurrentStore $currentStore, ReportCenterService $reports): StreamedResponse
    {
        abort_unless(in_array($format, ['csv', 'excel'], true), 404);
        $store = $currentStore->require();
        $this->authorize('view', $store);

        return $reports->export($store, $request->filters(), $format, $report);
    }
}
