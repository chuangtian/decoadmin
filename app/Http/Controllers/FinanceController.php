<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreFinanceCategoryRequest;
use App\Http\Requests\StoreFinanceEntryRequest;
use App\Models\FinanceEntry;
use App\Models\PersonalRequest;
use App\Services\BusinessNotificationService;
use App\Services\FinanceService;
use App\Support\CurrentOrganization;
use App\Support\CurrentStore;
use Carbon\CarbonImmutable;
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

    public function renewals(Request $request, CurrentOrganization $currentOrganization, CurrentStore $currentStore, FinanceService $finance): Response
    {
        $organization = $currentOrganization->require();
        $store = $currentStore->require();
        abort_unless((int) $store->organization_id === (int) $organization->id, 404);
        $timezone = $store->timezone ?: 'UTC';
        $today = CarbonImmutable::today($timezone);

        return Inertia::render('Finance/Renewals', [
            'organization' => ['id' => $organization->id, 'name' => $organization->name],
            'canManage' => $request->user()->hasPermission('finance.manage', $organization),
            'today' => $today->toDateString(),
            'windowEnd' => $today->addDays(30)->toDateString(),
            'timezone' => $timezone,
            'paymentRequests' => $finance->paymentRequests($organization, $today),
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

    public function reimbursements(Request $request, CurrentOrganization $currentOrganization, FinanceService $finance): Response
    {
        $organization = $currentOrganization->require();
        $filters = $request->validate([
            'payment_status' => ['nullable', 'in:pending,paid,all'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $data = $finance->reimbursementRequests($organization, $filters);

        return Inertia::render('Finance/Reimbursements', [
            'organization' => ['id' => $organization->id, 'name' => $organization->name],
            'canManage' => $request->user()->hasPermission('finance.manage', $organization),
            'today' => now()->toDateString(),
            ...$data,
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

    public function recordExpenseRequestPayment(Request $request, PersonalRequest $personalRequest, CurrentOrganization $currentOrganization, CurrentStore $currentStore, FinanceService $finance, BusinessNotificationService $notifications): RedirectResponse
    {
        $organization = $currentOrganization->require();
        $store = $currentStore->require();
        abort_unless((int) $store->organization_id === (int) $organization->id, 404);
        $today = CarbonImmutable::today($store->timezone ?: 'UTC')->toDateString();
        $validated = $request->validate([
            'paid_on' => ['required', 'date', "before_or_equal:{$today}"],
            'payment_reference' => ['nullable', 'string', 'max:180'],
        ]);
        $renewal = $personalRequest->payment_status === 'paid';
        $renewalDueOn = $renewal ? $personalRequest->next_renewal_on?->toDateString() : null;
        $user = $request->user();
        $finance->recordExpenseRequestPayment($organization, $user, $personalRequest, $validated);
        $personalRequest->refresh();
        $nextRenewalOn = $personalRequest->next_renewal_on?->toDateString();
        $paymentId = (int) $personalRequest->payments()->latest('id')->value('id');
        $notifications->notify(
            $organization,
            [$personalRequest->submitter_id],
            $user,
            $renewal ? 'expense_request.renewed' : 'expense_request.paid',
            $renewal ? '软件续费已完成' : '费用申请已付款',
            $renewal
                ? "{$personalRequest->reference_no}：{$personalRequest->title}，本周期 {$renewalDueOn}，下次续费 {$nextRenewalOn}"
                : "{$personalRequest->reference_no}：{$personalRequest->title}",
            route('expense-requests.index', [], false),
            $personalRequest,
            "personal-request:{$personalRequest->uuid}:payment:{$paymentId}",
        );

        return back()->with(
            'success',
            $renewal
                ? "续费已记录：本周期 {$renewalDueOn}，下次续费 {$nextRenewalOn}。"
                : '付款已确认。',
        );
    }

    public function recordExpenseReimbursement(Request $request, PersonalRequest $personalRequest, CurrentOrganization $currentOrganization, FinanceService $finance, BusinessNotificationService $notifications): RedirectResponse
    {
        $validated = $request->validate([
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'payment_reference' => ['nullable', 'string', 'max:180'],
        ]);
        $organization = $currentOrganization->require();
        $user = $request->user();
        $item = $finance->recordExpenseReimbursement($organization, $user, $personalRequest, $validated);
        $notifications->notify(
            $organization,
            [$item->submitter_id],
            $user,
            'expense.reimbursed',
            '报销款已打款',
            "{$item->reference_no}：{$item->title}，{$item->currency} {$item->amount}",
            route('expense-claims.index', [], false),
            $item,
            "personal-request:{$item->uuid}:reimbursed",
        );

        return back()->with('success', '报销已确认，打款信息已记录。');
    }
}
