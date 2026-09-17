<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\FinanceCategory;
use App\Models\FinanceEntry;
use App\Models\Organization;
use App\Models\PersonalRequest;
use App\Models\PersonalRequestPayment;
use App\Models\Store;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FinanceService
{
    public function __construct(private readonly AnalyticsQueryService $analytics) {}

    /** @param array<string, mixed> $filters */
    public function dashboard(Organization $organization, User $user, array $filters): array
    {
        $month = preg_match('/^\d{4}-\d{2}$/', (string) ($filters['month'] ?? '')) ? $filters['month'] : now()->format('Y-m');
        $start = CarbonImmutable::createFromFormat('Y-m', $month)->startOfMonth();
        $end = $start->endOfMonth();
        $storeId = filled($filters['store_id'] ?? null) ? (int) $filters['store_id'] : null;
        $store = $storeId ? $this->authorizedStore($organization, $user, $storeId) : null;
        $query = FinanceEntry::query()->where('organization_id', $organization->id)
            ->whereBetween('occurred_on', [$start, $end])
            ->when($store, fn (Builder $query) => $query->where('store_id', $store->id));
        $income = (float) (clone $query)->where('type', 'income')->sum('amount');
        $expense = (float) (clone $query)->where('type', 'expense')->sum('amount');

        return [
            'filters' => ['month' => $month, 'store_id' => $storeId],
            'currency' => $organization->default_currency ?: 'USD',
            'summary' => ['income' => $income, 'expense' => $expense, 'profit' => $income - $expense],
            'monthly' => $this->monthly($organization, $store),
            'store_profit' => $this->storeProfit($organization, $user, $start, $end),
            'categories' => FinanceCategory::query()->where('organization_id', $organization->id)->orderBy('type')->orderBy('name')->get()
                ->map(fn (FinanceCategory $category): array => ['id' => $category->id, 'name' => $category->name, 'type' => $category->type, 'color' => $category->color, 'is_active' => $category->is_active])->all(),
            'stores' => $this->analytics->authorizedStores($organization, $user)->map(fn (Store $item): array => ['id' => $item->id, 'name' => $item->name])->all(),
            'entries' => (clone $query)->with(['category:id,name,type', 'store:id,name', 'creator:id,name'])->latest('occurred_on')->latest('id')->paginate(20)->withQueryString()->through(fn (FinanceEntry $entry): array => $this->entryResource($entry)),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function paymentRequests(Organization $organization, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $windowEnd = $today->addDays(30);

        return PersonalRequest::query()
            ->where('organization_id', $organization->id)
            ->where('kind', 'expense_request')
            ->where('status', 'approved')
            ->where('renewal_status', 'active')
            ->where(function (Builder $query) use ($windowEnd): void {
                $query->where('payment_status', 'pending')
                    ->orWhere(fn (Builder $query) => $query
                        ->where('payment_status', 'paid')
                        ->where('category', 'software')
                        ->whereNotNull('next_renewal_on')
                        ->whereDate('next_renewal_on', '<=', $windowEnd));
            })
            ->with(['submitter:id,name', 'payer:id,name'])
            ->orderByRaw("case when payment_status = 'pending' then 0 else 1 end")
            ->orderByRaw("case when payment_status = 'pending' then desired_date else next_renewal_on end asc")
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn (PersonalRequest $item): array => $this->paymentRequestResource($item, $today))
            ->all();
    }

    /** @param array<string, mixed> $filters */
    public function reimbursementRequests(Organization $organization, array $filters): array
    {
        $paymentStatus = in_array(($filters['payment_status'] ?? 'pending'), ['pending', 'paid', 'all'], true)
            ? (string) ($filters['payment_status'] ?? 'pending')
            : 'pending';
        $base = PersonalRequest::query()
            ->where('organization_id', $organization->id)
            ->where('kind', 'expense')
            ->where('status', 'approved')
            ->whereIn('payment_status', ['pending', 'paid']);
        $summary = [
            'pending' => (clone $base)->where('payment_status', 'pending')->count(),
            'paid' => (clone $base)->where('payment_status', 'paid')->count(),
        ];
        $rows = (clone $base)
            ->when($paymentStatus !== 'all', fn (Builder $query) => $query->where('payment_status', $paymentStatus))
            ->with(['submitter:id,name', 'reviewer:id,name', 'payer:id,name', 'attachments'])
            ->orderByRaw("case when payment_status = 'pending' then 0 else 1 end")
            ->when(
                $paymentStatus === 'paid',
                fn (Builder $query) => $query->orderByDesc('paid_on')->orderByDesc('id'),
                fn (Builder $query) => $query->orderBy('reviewed_at')->orderBy('id'),
            )
            ->paginate(20)
            ->withQueryString();

        return [
            'filters' => ['payment_status' => $paymentStatus],
            'summary' => $summary,
            'reimbursements' => [
                ...$rows->toArray(),
                'data' => collect($rows->items())
                    ->map(fn (PersonalRequest $item): array => $this->reimbursementRequestResource($item))
                    ->all(),
            ],
        ];
    }

    /** @param array<string, mixed> $filters */
    public function paymentHistory(Organization $organization, array $filters): array
    {
        $month = preg_match('/^\d{4}-\d{2}$/', (string) ($filters['month'] ?? '')) ? (string) $filters['month'] : now()->format('Y-m');
        $start = CarbonImmutable::createFromFormat('Y-m', $month)->startOfMonth();
        $end = $start->endOfMonth();
        $query = PersonalRequestPayment::query()
            ->where('organization_id', $organization->id)
            ->whereBetween('paid_on', [$start, $end]);
        $totals = (clone $query)->selectRaw('currency, SUM(amount) as total')->groupBy('currency')->orderBy('currency')->get()
            ->map(fn (PersonalRequestPayment $payment): array => ['currency' => $payment->currency, 'total' => (string) $payment->total])->all();
        $payments = $query->with(['request:id,uuid,reference_no,title,category,submitter_id', 'request.submitter:id,name', 'payer:id,name'])
            ->latest('paid_on')->latest('id')->paginate(20)->withQueryString()
            ->through(fn (PersonalRequestPayment $payment): array => $this->paymentHistoryResource($payment));

        return ['month' => $month, 'totals' => $totals, 'payments' => $payments];
    }

    /** @param array{paid_on: string, payment_reference?: string|null} $values */
    public function recordExpenseRequestPayment(Organization $organization, User $actor, PersonalRequest $request, array $values): PersonalRequest
    {
        abort_unless((int) $request->organization_id === (int) $organization->id && $request->kind === 'expense_request', 404);

        return DB::transaction(function () use ($actor, $organization, $request, $values): PersonalRequest {
            $item = PersonalRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            $renewal = $item->payment_status === 'paid';
            if (
                $item->status !== 'approved'
                || $item->renewal_status !== 'active'
                || ! ($item->payment_status === 'pending' || ($renewal && $item->category === 'software' && $item->renewal_mode === 'manual'))
            ) {
                throw ValidationException::withMessages([
                    'paid_on' => $item->renewal_mode === 'automatic'
                        ? '该项目为自动续费，无需手动记录。'
                        : '该项目当前不能记录付款或续费，请刷新页面后重试。',
                ]);
            }
            $paidOn = CarbonImmutable::parse($values['paid_on'])->startOfDay();
            $renewalDueOn = $renewal && $item->next_renewal_on
                ? CarbonImmutable::parse($item->next_renewal_on)->startOfDay()
                : null;
            if ($renewal && ! $renewalDueOn) {
                throw ValidationException::withMessages(['paid_on' => '当前缺少本次应续费日期，请刷新页面后重试。']);
            }
            if ($renewalDueOn && $paidOn->isBefore($renewalDueOn)) {
                throw ValidationException::withMessages([
                    'paid_on' => "本周期将在 {$renewalDueOn->toDateString()} 到期，当前无需记录续费。",
                ]);
            }
            if ($renewalDueOn && $item->payments()->whereDate('renewal_due_on', $renewalDueOn)->exists()) {
                throw ValidationException::withMessages([
                    'paid_on' => "{$renewalDueOn->toDateString()} 这一续费周期已经记录，无需重复操作。",
                ]);
            }
            if (! $renewal && $item->category === 'software') {
                $item->renewal_anchor_day = $paidOn->day;
                $item->renewal_anchor_month_end = $paidOn->isLastOfMonth();
            }
            $nextRenewalOn = $item->category === 'software'
                ? $this->nextRenewalDate(
                    $renewalDueOn ?? $paidOn,
                    (string) $item->billing_cycle,
                    $item->renewal_anchor_day,
                    $item->renewal_anchor_month_end,
                )
                : null;

            $item->forceFill([
                'payment_status' => 'paid',
                'paid_by' => $actor->id,
                'paid_on' => $paidOn->toDateString(),
                'next_renewal_on' => $nextRenewalOn,
                'payment_reference' => filled($values['payment_reference'] ?? null) ? trim((string) $values['payment_reference']) : null,
            ])->save();
            PersonalRequestPayment::query()->create([
                'organization_id' => $organization->id,
                'personal_request_id' => $item->id,
                'paid_by' => $actor->id,
                'type' => $renewal ? 'manual_renewal' : 'initial_payment',
                'amount' => $item->amount,
                'currency' => $item->currency,
                'paid_on' => $paidOn->toDateString(),
                'renewal_due_on' => $renewalDueOn?->toDateString(),
                'reference' => $item->payment_reference,
            ]);
            $item->progressLogs()->create([
                'user_id' => $actor->id,
                'action' => $renewal ? 'renewed' : 'paid',
                'content' => $item->payment_reference,
            ]);
            $this->audit($organization, $actor, $renewal ? 'expense_request_renewed' : 'expense_request_paid', $item, [
                'reference_no' => $item->reference_no,
                'amount' => $item->amount,
                'currency' => $item->currency,
                'paid_on' => $item->paid_on?->toDateString(),
                'next_renewal_on' => $item->next_renewal_on?->toDateString(),
                'payment_reference' => $item->payment_reference,
            ]);

            return $item->fresh(['payer']);
        });
    }

    /** @param array{paid_on: string, payment_reference?: string|null} $values */
    public function recordExpenseReimbursement(Organization $organization, User $actor, PersonalRequest $request, array $values): PersonalRequest
    {
        abort_unless(
            (int) $request->organization_id === (int) $organization->id
                && $request->kind === 'expense',
            404,
        );

        return DB::transaction(function () use ($actor, $organization, $request, $values): PersonalRequest {
            $item = PersonalRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            abort_unless($item->status === 'approved' && $item->payment_status === 'pending', 409, '该报销申请当前不能重复打款。');
            $paidOn = CarbonImmutable::parse($values['paid_on'])->startOfDay();
            if ($item->expense_date && $paidOn->isBefore($item->expense_date)) {
                throw ValidationException::withMessages([
                    'paid_on' => '打款日期不能早于费用发生日期。',
                ]);
            }

            $reference = filled($values['payment_reference'] ?? null)
                ? trim((string) $values['payment_reference'])
                : null;
            $item->forceFill([
                'payment_status' => 'paid',
                'paid_by' => $actor->id,
                'paid_on' => $paidOn->toDateString(),
                'payment_reference' => $reference,
            ])->save();
            $item->progressLogs()->create([
                'user_id' => $actor->id,
                'action' => 'reimbursed',
                'content' => $reference,
            ]);
            $this->audit($organization, $actor, 'expense_claim_reimbursed', $item, [
                'reference_no' => $item->reference_no,
                'amount' => $item->amount,
                'currency' => $item->currency,
                'paid_on' => $item->paid_on?->toDateString(),
                'payment_reference' => $reference,
            ]);

            return $item->fresh(['payer']);
        });
    }

    public function processAutomaticRenewals(?CarbonImmutable $today = null): int
    {
        $today ??= CarbonImmutable::today();
        $processed = 0;
        PersonalRequest::query()
            ->where('kind', 'expense_request')
            ->where('category', 'software')
            ->where('status', 'approved')
            ->where('renewal_status', 'active')
            ->where('payment_status', 'paid')
            ->where('renewal_mode', 'automatic')
            ->whereDate('next_renewal_on', '<=', $today)
            ->with('organization')
            ->chunkById(100, function ($items) use (&$processed, $today): void {
                foreach ($items as $request) {
                    $cycles = DB::transaction(function () use ($request, $today): int {
                        $item = PersonalRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

                        return $this->recordAutomaticRenewalCycles($item, $today, $request->organization);
                    });
                    if ($cycles > 0) {
                        $processed++;
                    }
                }
            });

        return $processed;
    }

    /** @param array{cancelled_on: string} $values */
    public function cancelSoftwareRenewal(Organization $organization, User $actor, PersonalRequest $request, array $values): PersonalRequest
    {
        abort_unless(
            (int) $request->organization_id === (int) $organization->id
                && $request->kind === 'expense_request'
                && $request->category === 'software',
            404
        );

        $cancelledOn = CarbonImmutable::parse($values['cancelled_on'])->startOfDay();

        return DB::transaction(function () use ($actor, $cancelledOn, $organization, $request): PersonalRequest {
            $item = PersonalRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

            if ($item->renewal_status === 'cancelled') {
                abort_unless($item->cancelled_on?->isSameDay($cancelledOn) === true, 409, '该项目已经取消续费。');

                return $item->fresh(['renewalCanceller']);
            }

            abort_unless($item->status === 'approved' && $item->payment_status === 'paid', 409, '只有已付款的持续软件项目可以取消续费。');

            $latestPaymentOn = $item->payments()->max('paid_on');
            if ($latestPaymentOn && $cancelledOn->isBefore(CarbonImmutable::parse($latestPaymentOn))) {
                throw ValidationException::withMessages([
                    'cancelled_on' => '取消日期不能早于最近一次已记录的付款日期。',
                ]);
            }

            $scheduledRenewalOn = $item->next_renewal_on?->toDateString();
            $automaticCycles = $item->renewal_mode === 'automatic'
                ? $this->recordAutomaticRenewalCycles($item, $cancelledOn, $organization)
                : 0;

            $item->forceFill([
                'renewal_status' => 'cancelled',
                'cancelled_on' => $cancelledOn->toDateString(),
                'cancelled_by' => $actor->id,
                'next_renewal_on' => null,
            ])->save();

            $item->progressLogs()->create([
                'user_id' => $actor->id,
                'action' => 'renewal_cancelled',
                'content' => $automaticCycles > 0
                    ? "取消日前已确认 {$automaticCycles} 个自动续费周期，后续续费已停止。"
                    : '后续续费已停止，已有付款历史保持不变。',
            ]);
            $this->audit($organization, $actor, 'expense_request_renewal_cancelled', $item, [
                'reference_no' => $item->reference_no,
                'cancelled_on' => $item->cancelled_on?->toDateString(),
                'scheduled_renewal_on' => $scheduledRenewalOn,
                'automatic_cycles_recorded' => $automaticCycles,
            ]);

            return $item->fresh(['renewalCanceller']);
        });
    }

    public function revealExpenseRequestPassword(Organization $organization, User $actor, PersonalRequest $request): string
    {
        abort_unless(
            (int) $request->organization_id === (int) $organization->id
                && $request->kind === 'expense_request'
                && $request->category === 'software'
                && filled($request->software_password),
            404
        );
        $this->audit($organization, $actor, 'expense_request_password_viewed', $request, [
            'reference_no' => $request->reference_no,
        ]);

        return (string) $request->software_password;
    }

    /** @param array<string, mixed> $values */
    public function createCategory(Organization $organization, User $actor, array $values): FinanceCategory
    {
        $category = FinanceCategory::query()->create([...$values, 'organization_id' => $organization->id, 'created_by' => $actor->id]);
        $this->audit($organization, $actor, 'finance_category_created', $category, ['name' => $category->name, 'type' => $category->type]);

        return $category;
    }

    /** @param array<string, mixed> $values */
    public function createEntry(Organization $organization, User $actor, array $values): FinanceEntry
    {
        $category = FinanceCategory::query()->where('organization_id', $organization->id)->findOrFail($values['category_id']);
        abort_unless($category->type === $values['type'], 422, '收支类型与分类不一致。');
        $store = filled($values['store_id'] ?? null) ? $this->authorizedStore($organization, $actor, (int) $values['store_id']) : null;
        $entry = FinanceEntry::query()->create([
            ...$values,
            'organization_id' => $organization->id,
            'store_id' => $store?->id,
            'currency' => $organization->default_currency ?: 'USD',
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
        $this->audit($organization, $actor, 'finance_entry_created', $entry, ['uuid' => $entry->uuid, 'type' => $entry->type, 'amount' => $entry->amount, 'store_id' => $entry->store_id]);

        return $entry;
    }

    public function deleteEntry(Organization $organization, User $actor, FinanceEntry $entry): void
    {
        abort_unless($entry->organization_id === $organization->id, 404);
        if ($entry->store_id !== null) {
            $this->authorizedStore($organization, $actor, $entry->store_id);
        }
        $entry->delete();
        $this->audit($organization, $actor, 'finance_entry_deleted', $entry, ['uuid' => $entry->uuid, 'type' => $entry->type, 'amount' => $entry->amount]);
    }

    private function authorizedStore(Organization $organization, User $user, int $storeId): Store
    {
        $store = Store::query()->where('organization_id', $organization->id)->findOrFail($storeId);
        abort_unless($user->canAccessStore($store), 403);

        return $store;
    }

    private function monthly(Organization $organization, ?Store $store): array
    {
        $start = now()->subMonths(5)->startOfMonth();
        $rows = FinanceEntry::query()->where('organization_id', $organization->id)
            ->when($store, fn (Builder $query) => $query->where('store_id', $store->id))
            ->where('occurred_on', '>=', $start)
            ->get(['occurred_on', 'type', 'amount'])
            ->groupBy(fn (FinanceEntry $entry): string => $entry->occurred_on->format('Y-m'));

        return collect(range(0, 5))->map(function (int $offset) use ($start, $rows): array {
            $month = $start->copy()->addMonths($offset);
            $group = $rows->get($month->format('Y-m'), collect());
            $income = (float) $group->where('type', 'income')->sum('amount');
            $expense = (float) $group->where('type', 'expense')->sum('amount');

            return ['month' => $month->format('Y-m'), 'label' => $month->format('m月'), 'income' => $income, 'expense' => $expense, 'profit' => $income - $expense];
        })->all();
    }

    private function storeProfit(Organization $organization, User $user, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $stores = $this->analytics->authorizedStores($organization, $user);
        $rows = FinanceEntry::query()->where('organization_id', $organization->id)->whereIn('store_id', $stores->modelKeys())
            ->whereBetween('occurred_on', [$start, $end])
            ->selectRaw('store_id, type, SUM(amount) as total')->groupBy('store_id', 'type')->get()->groupBy('store_id');

        return $stores->map(function (Store $store) use ($rows): array {
            $group = $rows->get($store->id, collect());
            $income = (float) optional($group->firstWhere('type', 'income'))->total;
            $expense = (float) optional($group->firstWhere('type', 'expense'))->total;

            return ['id' => $store->id, 'name' => $store->name, 'income' => $income, 'expense' => $expense, 'profit' => $income - $expense];
        })->sortByDesc('profit')->values()->all();
    }

    private function entryResource(FinanceEntry $entry): array
    {
        return [
            'id' => $entry->id, 'uuid' => $entry->uuid, 'type' => $entry->type, 'amount' => (string) $entry->amount,
            'currency' => $entry->currency, 'occurred_on' => $entry->occurred_on?->toDateString(), 'description' => $entry->description,
            'reference' => $entry->reference, 'category' => $entry->category ? ['id' => $entry->category->id, 'name' => $entry->category->name] : null,
            'store' => $entry->store ? ['id' => $entry->store->id, 'name' => $entry->store->name] : null,
            'creator' => $entry->creator ? ['id' => $entry->creator->id, 'name' => $entry->creator->name] : null,
            'created_at' => $entry->created_at?->toIso8601String(),
        ];
    }

    private function paymentRequestResource(PersonalRequest $item, CarbonImmutable $today): array
    {
        $renewalDueOn = $item->next_renewal_on ? CarbonImmutable::parse($item->next_renewal_on) : null;

        return [
            'uuid' => $item->uuid,
            'reference_no' => $item->reference_no,
            'title' => $item->title,
            'description' => $item->description,
            'category' => $item->category,
            'amount' => (string) $item->amount,
            'currency' => $item->currency,
            'desired_date' => $item->desired_date?->toDateString(),
            'submitter' => $item->submitter?->name,
            'software_url' => $item->software_url,
            'software_account' => $item->software_account,
            'software_password_set' => filled($item->software_password),
            'software_payment_method' => $item->software_payment_method,
            'renewal_mode' => $item->renewal_mode,
            'billing_cycle' => $item->billing_cycle,
            'approval_required' => $item->approval_required,
            'payment_status' => $item->payment_status,
            'is_new_application' => $item->payment_status === 'pending'
                && $item->reviewed_at?->gte(now()->subMonth()),
            'payer' => $item->payer?->name,
            'paid_on' => $item->paid_on?->toDateString(),
            'next_renewal_on' => $item->next_renewal_on?->toDateString(),
            'following_renewal_on' => $renewalDueOn
                ? $this->nextRenewalDate(
                    $renewalDueOn,
                    (string) $item->billing_cycle,
                    $item->renewal_anchor_day,
                    $item->renewal_anchor_month_end,
                )->toDateString()
                : null,
            'days_until_renewal' => $renewalDueOn ? (int) $today->diffInDays($renewalDueOn, false) : null,
            'can_record_renewal' => $renewalDueOn?->lte($today) ?? false,
            'payment_reference' => $item->payment_reference,
        ];
    }

    private function reimbursementRequestResource(PersonalRequest $item): array
    {
        return [
            'uuid' => $item->uuid,
            'reference_no' => $item->reference_no,
            'title' => $item->title,
            'description' => $item->description,
            'category' => $item->category,
            'amount' => (string) $item->amount,
            'currency' => $item->currency,
            'expense_date' => $item->expense_date?->toDateString(),
            'submitter' => $item->submitter?->name,
            'reviewer' => $item->reviewer?->name,
            'review_note' => $item->review_note,
            'reviewed_at' => $item->reviewed_at?->format('Y-m-d H:i'),
            'payment_status' => $item->payment_status,
            'payer' => $item->payer?->name,
            'paid_on' => $item->paid_on?->toDateString(),
            'payment_reference' => $item->payment_reference,
            'attachments' => $item->attachments->map(fn ($attachment): array => [
                'uuid' => $attachment->uuid,
                'name' => $attachment->original_name,
                'url' => route('personal-requests.attachments.show', [$item, $attachment], false),
            ])->values()->all(),
        ];
    }

    private function paymentHistoryResource(PersonalRequestPayment $payment): array
    {
        return [
            'uuid' => $payment->uuid,
            'type' => $payment->type,
            'amount' => (string) $payment->amount,
            'currency' => $payment->currency,
            'paid_on' => $payment->paid_on?->toDateString(),
            'renewal_due_on' => $payment->renewal_due_on?->toDateString(),
            'reference' => $payment->reference,
            'payer' => $payment->payer?->name ?? '系统自动续费',
            'request' => [
                'uuid' => $payment->request?->uuid,
                'reference_no' => $payment->request?->reference_no,
                'title' => $payment->request?->title,
                'category' => $payment->request?->category,
                'submitter' => $payment->request?->submitter?->name,
            ],
        ];
    }

    private function recordAutomaticRenewalCycles(PersonalRequest $item, CarbonImmutable $through, ?Organization $organization): int
    {
        if (
            $item->status !== 'approved'
            || $item->renewal_status !== 'active'
            || $item->renewal_mode !== 'automatic'
            || $item->payment_status !== 'paid'
            || ! $item->next_renewal_on
            || $item->next_renewal_on->isAfter($through)
        ) {
            return 0;
        }

        $paymentDates = [];
        $nextRenewalOn = CarbonImmutable::parse($item->next_renewal_on);
        while ($nextRenewalOn->lte($through)) {
            $paymentDates[] = $nextRenewalOn;
            $nextRenewalOn = $this->nextRenewalDate(
                $nextRenewalOn,
                (string) $item->billing_cycle,
                $item->renewal_anchor_day,
                $item->renewal_anchor_month_end,
            );
        }

        $paidOn = $paymentDates[array_key_last($paymentDates)];
        $cycles = count($paymentDates);
        $item->forceFill([
            'paid_on' => $paidOn,
            'next_renewal_on' => $nextRenewalOn,
            'payment_reference' => 'AUTO-RENEWAL',
        ])->save();

        foreach ($paymentDates as $paymentDate) {
            PersonalRequestPayment::query()->firstOrCreate(
                ['personal_request_id' => $item->id, 'renewal_due_on' => $paymentDate->toDateString()],
                [
                    'organization_id' => $item->organization_id,
                    'paid_by' => null,
                    'type' => 'automatic_renewal',
                    'amount' => $item->amount,
                    'currency' => $item->currency,
                    'paid_on' => $paymentDate->toDateString(),
                    'reference' => 'AUTO-RENEWAL',
                ]
            );
        }
        $item->progressLogs()->create([
            'user_id' => null,
            'action' => 'automatic_renewed',
            'content' => "系统自动确认 {$cycles} 个续费周期。",
        ]);
        if ($organization) {
            $this->audit($organization, null, 'expense_request_automatic_renewed', $item, [
                'reference_no' => $item->reference_no,
                'cycles' => $cycles,
                'paid_on' => $item->paid_on?->toDateString(),
                'next_renewal_on' => $item->next_renewal_on?->toDateString(),
            ]);
        }

        return $cycles;
    }

    private function nextRenewalDate(
        CarbonImmutable $from,
        string $billingCycle,
        ?int $anchorDay = null,
        ?bool $anchorMonthEnd = null,
    ): CarbonImmutable {
        $months = match ($billingCycle) {
            'monthly' => 1,
            'bimonthly' => 2,
            'quarterly' => 3,
            'annual' => 12,
            default => throw new \InvalidArgumentException("Unsupported billing cycle: {$billingCycle}"),
        };
        $targetMonth = $from->startOfMonth()->addMonths($months);
        $day = $anchorMonthEnd === true
            ? $targetMonth->daysInMonth
            : min($anchorDay ?? $from->day, $targetMonth->daysInMonth);

        return $targetMonth->day($day);
    }

    private function audit(Organization $organization, ?User $actor, string $action, object $subject, array $values): void
    {
        AuditLog::query()->create([
            'uuid' => (string) Str::uuid(), 'organization_id' => $organization->id, 'store_id' => data_get($subject, 'store_id'),
            'user_id' => $actor?->id, 'action' => $action, 'subject_type' => $subject::class, 'subject_id' => data_get($subject, 'id'),
            'new_values' => $values, 'metadata' => ['source' => 'finance_center'],
        ]);
    }
}
