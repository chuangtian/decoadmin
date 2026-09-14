<?php

namespace App\Http\Controllers;

use App\Models\StoreAlert;
use App\Services\StoreAlertQueryService;
use App\Services\StoreOperationalAlertService;
use App\Support\CurrentOrganization;
use App\Support\CurrentStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StoreAlertController extends Controller
{
    public function open(Request $request, StoreAlert $storeAlert, CurrentOrganization $currentOrganization): RedirectResponse
    {
        $organization = $currentOrganization->require();
        $user = $request->user();
        $storeAlert->loadMissing('store');

        abort_unless(
            $user
            && (int) $storeAlert->organization_id === (int) $organization->id
            && $user->hasPermission('alerts.view', $organization, $storeAlert->store),
            404,
        );

        $request->session()->put('current_store_id', $storeAlert->store_id);

        return redirect()->route('alerts.index');
    }

    public function index(Request $request, CurrentStore $currentStore, StoreAlertQueryService $alerts): Response
    {
        $filters = $request->validate([
            'status' => ['nullable', 'in:open,acknowledged,resolved'],
            'type' => ['nullable', 'in:connection,sync,webhook,discount,product'],
        ]);

        return Inertia::render('Alerts/Index', [
            'alerts' => $alerts->paginate($currentStore->require(), $filters['status'] ?? null, $filters['type'] ?? null),
            'filters' => $filters,
        ]);
    }

    public function scan(CurrentStore $currentStore, StoreOperationalAlertService $alerts): RedirectResponse
    {
        $result = $alerts->scan($currentStore->require());

        return back()->with('success', "检查完成，新增 {$result['created']} 条异常告警。");
    }

    public function acknowledge(Request $request, StoreAlert $storeAlert, CurrentStore $currentStore, StoreAlertQueryService $alerts): RedirectResponse
    {
        $alerts->acknowledge($currentStore->require(), $storeAlert, $request->user());

        return back()->with('success', '告警已确认。');
    }

    public function resolve(Request $request, StoreAlert $storeAlert, CurrentStore $currentStore, StoreAlertQueryService $alerts): RedirectResponse
    {
        $alerts->resolve($currentStore->require(), $storeAlert, $request->user());

        return back()->with('success', '告警已标记为已解决。');
    }
}
