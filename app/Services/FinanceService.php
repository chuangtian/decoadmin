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
    public function paymentRequests(Organization $organization): array
    {
        return PersonalRequest::query()
            ->where('organization_id', $organization->id)
            ->where('kind', 'expense_request')
            ->where('status', 'approved')
            ->where(function (Builder $query): void {
                $query->where('payment_status', 'pending')
                    ->orWhere(fn (Builder $query) => $query->where('payment_status', 'paid')->where('category', 'software'));
            })
            ->with(['submitter:id,name', 'payer:id,name'])
            ->orderByRaw("case when payment_status = 'pending' then 0 else 1 end")
            ->orderByRaw("case when payment_status = 'pending' then desired_date else next_renewal_on end asc")
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn (PersonalRequest $item): array => $this->paymentRequestResource($item))
            ->all();
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
            abort_unless(
                $item->status === 'approved'
                    && ($item->payment_status === 'pending' || ($renewal && $item->category === 'software' && $item->renewal_mode === 'manual')),
                409,
                '该费用申请当前不能付款。'
            );
            $paidOn = CarbonImmutable::parse($values['paid_on'])->startOfDay();
            abort_unless(! $renewal || ! $item->paid_on || $paidOn->isAfter($item->paid_on), 422, '续费日期必须晚于上次付款日期。');
            $nextRenewalOn = $item->category === 'software'
                ? $this->nextRenewalDate($paidOn, (string) $item->billing_cycle)
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

    public function processAutomaticRenewals(?CarbonImmutable $today = null): int
    {
        $today ??= CarbonImmutable::today();
        $processed = 0;
        PersonalRequest::query()
            ->where('kind', 'expense_request')
            ->where('category', 'software')
            ->where('status', 'approved')
            ->where('payment_status', 'paid')
            ->where('renewal_mode', 'automatic')
            ->whereDate('next_renewal_on', '<=', $today)
            ->with('organization')
            ->chunkById(100, function ($items) use (&$processed, $today): void {
                foreach ($items as $request) {
                    $renewed = DB::transaction(function () use ($request, $today): bool {
                        $item = PersonalRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
                        if ($item->renewal_mode !== 'automatic' || $item->payment_status !== 'paid' || ! $item->next_renewal_on || $item->next_renewal_on->isAfter($today)) {
                            return false;
                        }
                        $paymentDates = [];
                        $nextRenewalOn = CarbonImmutable::parse($item->next_renewal_on);
                        while ($nextRenewalOn->lte($today)) {
                            $paymentDates[] = $nextRenewalOn;
                            $nextRenewalOn = $this->nextRenewalDate($nextRenewalOn, (string) $item->billing_cycle);
                        }
                        $paidOn = $paymentDates[array_key_last($paymentDates)];
                        $cycles = count($paymentDates);
                        $item->forceFill([
                            'paid_on' => $paidOn,
                            'next_renewal_on' => $nextRenewalOn,
                            'payment_reference' => 'AUTO-RENEWAL',
                        ])->save();
                        foreach ($paymentDates as $paymentDate) {
                            PersonalRequestPayment::query()->create([
                                'organization_id' => $item->organization_id,
                                'personal_request_id' => $item->id,
                                'paid_by' => null,
                                'type' => 'automatic_renewal',
                                'amount' => $item->amount,
                                'currency' => $item->currency,
                                'paid_on' => $paymentDate->toDateString(),
                                'reference' => 'AUTO-RENEWAL',
                            ]);
                        }
                        $item->progressLogs()->create([
                            'user_id' => null,
                            'action' => 'automatic_renewed',
                            'content' => "系统自动确认 {$cycles} 个续费周期。",
                        ]);
                        $organization = $request->organization;
                        if ($organization) {
                            $this->audit($organization, null, 'expense_request_automatic_renewed', $item, [
                                'reference_no' => $item->reference_no,
                                'cycles' => $cycles,
                                'paid_on' => $item->paid_on?->toDateString(),
                                'next_renewal_on' => $item->next_renewal_on?->toDateString(),
                            ]);
                        }

                        return true;
                    });
                    if ($renewed) {
                        $processed++;
                    }
                }
            });

        return $processed;
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

    private function paymentRequestResource(PersonalRequest $item): array
    {
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
            'renewal_mode' => $item->renewal_mode,
            'billing_cycle' => $item->billing_cycle,
            'payment_status' => $item->payment_status,
            'is_new_application' => $item->payment_status === 'pending'
                && $item->reviewed_at?->gte(now()->subMonth()),
            'payer' => $item->payer?->name,
            'paid_on' => $item->paid_on?->toDateString(),
            'next_renewal_on' => $item->next_renewal_on?->toDateString(),
            'payment_reference' => $item->payment_reference,
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

    private function nextRenewalDate(CarbonImmutable $paidOn, string $billingCycle): CarbonImmutable
    {
        return $billingCycle === 'monthly' ? $paidOn->addMonthNoOverflow() : $paidOn->addYearNoOverflow();
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
