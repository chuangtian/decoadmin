<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreFinanceCategoryRequest;
use App\Http\Requests\StoreFinanceEntryRequest;
use App\Models\FinanceEntry;
use App\Models\PersonalRequest;
use App\Services\FinanceService;
use App\Support\CurrentOrganization;
use Illuminate\Http\JsonResponse;
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

    public function renewals(Request $request, CurrentOrganization $currentOrganization, FinanceService $finance): Response
    {
        $organization = $currentOrganization->require();

        return Inertia::render('Finance/Renewals', [
            'organization' => ['id' => $organization->id, 'name' => $organization->name],
            'canManage' => $request->user()->hasPermission('finance.manage', $organization),
            'paymentRequests' => $finance->paymentRequests($organization),
        ]);
    }

    public function renewalHistory(Request $request, CurrentOrganization $currentOrganization, FinanceService $finance): Response
    {
        $organization = $currentOrganization->require();
        $filters = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        return Inertia::render('Finance/RenewalHistory', [
            'organization' => ['id' => $organization->id, 'name' => $organization->name],
            'history' => $finance->paymentHistory($organization, $filters),
        ]);
    }

    public function revealExpenseRequestPassword(Request $request, PersonalRequest $personalRequest, CurrentOrganization $currentOrganization, FinanceService $finance): JsonResponse
    {
        $value = $finance->revealExpenseRequestPassword($currentOrganization->require(), $request->user(), $personalRequest);

        return response()->json(['data' => ['value' => $value]])->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
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

    public function recordExpenseRequestPayment(Request $request, PersonalRequest $personalRequest, CurrentOrganization $currentOrganization, FinanceService $finance): RedirectResponse
    {
        $validated = $request->validate([
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'payment_reference' => ['nullable', 'string', 'max:180'],
        ]);
        $renewal = $personalRequest->payment_status === 'paid';
        $finance->recordExpenseRequestPayment($currentOrganization->require(), $request->user(), $personalRequest, $validated);

        return back()->with('success', $renewal ? '续费记录已保存，下次续费日期已顺延。' : '付款已确认。');
    }
}
