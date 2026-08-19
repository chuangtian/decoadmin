<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreFinanceCategoryRequest;
use App\Http\Requests\StoreFinanceEntryRequest;
use App\Models\FinanceEntry;
use App\Services\FinanceService;
use App\Support\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FinanceController extends Controller
{
    public function index(Request $request, CurrentOrganization $currentOrganization, FinanceService $finance): Response
    {
        $organization = $currentOrganization->require();

        return Inertia::render('Finance/Index', [
            'organization' => ['id' => $organization->id, 'name' => $organization->name],
            'finance' => $finance->dashboard($organization, $request->user(), $request->only(['month', 'store_id'])),
            'canManage' => $request->user()->hasPermission('finance.manage', $organization),
        ]);
    }

    public function storeCategory(StoreFinanceCategoryRequest $request, CurrentOrganization $currentOrganization, FinanceService $finance): RedirectResponse
    {
        $finance->createCategory($currentOrganization->require(), $request->user(), $request->validated());

        return back()->with('success', '财务分类已创建。');
    }

    public function storeEntry(StoreFinanceEntryRequest $request, CurrentOrganization $currentOrganization, FinanceService $finance): RedirectResponse
    {
        $finance->createEntry($currentOrganization->require(), $request->user(), $request->validated());

        return back()->with('success', '收支记录已保存。');
    }

    public function destroyEntry(Request $request, FinanceEntry $financeEntry, CurrentOrganization $currentOrganization, FinanceService $finance): RedirectResponse
    {
        $finance->deleteEntry($currentOrganization->require(), $request->user(), $financeEntry);

        return back()->with('success', '收支记录已删除。');
    }
}
