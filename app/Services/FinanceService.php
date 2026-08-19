<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\FinanceCategory;
use App\Models\FinanceEntry;
use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
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

    private function audit(Organization $organization, User $actor, string $action, object $subject, array $values): void
    {
        AuditLog::query()->create([
            'uuid' => (string) Str::uuid(), 'organization_id' => $organization->id, 'store_id' => data_get($subject, 'store_id'),
            'user_id' => $actor->id, 'action' => $action, 'subject_type' => $subject::class, 'subject_id' => data_get($subject, 'id'),
            'new_values' => $values, 'metadata' => ['source' => 'finance_center'],
        ]);
    }
}
