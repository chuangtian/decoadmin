<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\DesignRequest;
use App\Models\Store;
use App\Models\User;
use App\Support\CurrentOrganization;
use App\Support\CurrentStore;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DesignRequestController extends Controller
{
    private const TYPES = ['site_banner', 'edm', 'social_media', 'product_detail', 'video', 'other'];

    private const PRIORITIES = ['urgent', 'high', 'medium', 'low'];

    private const STATUSES = ['pending', 'assigned', 'in_progress', 'review', 'completed', 'cancelled'];

    public function index(Request $request, CurrentOrganization $currentOrganization, CurrentStore $currentStore): Response
    {
        $organization = $currentOrganization->require();
        $store = $currentStore->require();
        $user = $request->user();
        abort_unless($user && (int) $store->organization_id === (int) $organization->id, 404);

        $canViewAll = $user->hasPermission('design_requests.view_all', $organization, $store);
        $canManage = $user->hasPermission('design_requests.manage', $organization, $store);
        $filters = $request->validate([
            'tab' => ['nullable', Rule::in(['overview', 'list'])],
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'priority' => ['nullable', Rule::in(self::PRIORITIES)],
            'type' => ['nullable', Rule::in(self::TYPES)],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $scope = DesignRequest::query()
            ->where('organization_id', $organization->id)
            ->where('store_id', $store->id)
            ->when(! $canViewAll, fn (Builder $query) => $query->where('requester_id', $user->id));
        $allRows = (clone $scope)->with(['requester:id,name', 'designer:id,name'])->get();
        $rows = (clone $scope)
            ->with(['requester:id,name', 'designer:id,name'])
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['priority'] ?? null, fn (Builder $query, string $priority) => $query->where('priority', $priority))
            ->when($filters['type'] ?? null, fn (Builder $query, string $type) => $query->where('request_type', $type))
            ->when(trim((string) ($filters['search'] ?? '')) !== '', function (Builder $query) use ($filters): void {
                $keyword = '%'.addcslashes(trim((string) $filters['search']), '%_\\').'%';
                $query->where(fn (Builder $query) => $query
                    ->where('reference_no', 'like', $keyword)
                    ->orWhere('description', 'like', $keyword));
            })
            ->orderByRaw("case priority when 'urgent' then 1 when 'high' then 2 when 'medium' then 3 else 4 end")
            ->orderByDesc('requested_on')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('DesignRequests/Index', [
            'store' => ['id' => $store->id, 'name' => $store->name, 'timezone' => $store->timezone ?: 'UTC'],
            'scope' => $canViewAll ? 'all' : 'mine',
            'today' => today($store->timezone ?: 'UTC')->toDateString(),
            'canCreate' => $user->hasPermission('design_requests.create', $organization, $store),
            'canManage' => $canManage,
            'filters' => [
                'tab' => $filters['tab'] ?? 'list', 'status' => $filters['status'] ?? '',
                'priority' => $filters['priority'] ?? '', 'type' => $filters['type'] ?? '',
                'search' => trim((string) ($filters['search'] ?? '')),
            ],
            'options' => [
                'types' => self::TYPES, 'priorities' => self::PRIORITIES, 'statuses' => self::STATUSES,
                'designers' => $canManage ? $this->designers($store) : [],
            ],
            'summary' => $this->summary($allRows, $store->timezone ?: 'UTC'),
            'requests' => [
                ...$rows->toArray(),
                'data' => collect($rows->items())->map(fn (DesignRequest $item) => $this->serialize($item, $store->timezone ?: 'UTC'))->all(),
            ],
        ]);
    }

    public function store(Request $request, CurrentOrganization $currentOrganization, CurrentStore $currentStore): RedirectResponse
    {
        $organization = $currentOrganization->require();
        $store = $currentStore->require();
        $user = $request->user();
        abort_unless($user && (int) $store->organization_id === (int) $organization->id, 404);
        $validated = $request->validate([
            'request_type' => ['required', Rule::in(self::TYPES)],
            'priority' => ['required', Rule::in(self::PRIORITIES)],
            'description' => ['required', 'string', 'max:5000'],
            'requester_department' => ['nullable', 'string', 'max:120'],
            'quantity' => ['required', 'integer', 'min:1', 'max:999'],
            'planned_delivery_date' => ['required', 'date', 'after_or_equal:today'],
        ]);

        $designRequest = DB::transaction(function () use ($validated, $organization, $store, $user): DesignRequest {
            $item = DesignRequest::query()->create([
                ...$validated,
                'organization_id' => $organization->id,
                'store_id' => $store->id,
                'requester_id' => $user->id,
                'requested_on' => today($store->timezone ?: 'UTC')->toDateString(),
                'status' => 'pending',
                'revision_count' => 0,
                'updated_by' => $user->id,
            ]);
            $this->audit($item, $user, 'design_request_created', [], ['status' => 'pending']);

            return $item;
        });

        return back()->with('success', "需求 {$designRequest->reference_no} 已提交。");
    }

    public function update(Request $request, DesignRequest $designRequest, CurrentOrganization $currentOrganization, CurrentStore $currentStore): RedirectResponse
    {
        $organization = $currentOrganization->require();
        $store = $currentStore->require();
        $user = $request->user();
        abort_unless($user && (int) $designRequest->organization_id === (int) $organization->id && (int) $designRequest->store_id === (int) $store->id, 404);
        abort_unless($user->hasPermission('design_requests.manage', $organization, $store), 403);
        $validated = $request->validate([
            'designer_id' => ['nullable', 'integer'],
            'status' => ['required', Rule::in(self::STATUSES)],
            'planned_delivery_date' => ['required', 'date', 'after_or_equal:'.$designRequest->requested_on->toDateString()],
            'actual_delivery_date' => ['nullable', 'date', 'after_or_equal:'.$designRequest->requested_on->toDateString()],
            'revision_count' => ['required', 'integer', 'min:0', 'max:999'],
            'delivery_note' => ['nullable', 'string', 'max:3000'],
        ]);
        if ($validated['designer_id'] !== null) {
            abort_unless(collect($this->designers($store))->contains('id', (int) $validated['designer_id']), 422);
        }
        if ($validated['status'] === 'completed' && blank($validated['actual_delivery_date'])) {
            return back()->withErrors(['actual_delivery_date' => '完成需求时请填写实际交付日期。']);
        }
        if (in_array($validated['status'], ['assigned', 'in_progress', 'review', 'completed'], true) && $validated['designer_id'] === null) {
            return back()->withErrors(['designer_id' => '进入处理流程前请先分配设计师。']);
        }

        DB::transaction(function () use ($validated, $designRequest, $user): void {
            $old = $designRequest->only(['designer_id', 'status', 'planned_delivery_date', 'actual_delivery_date', 'revision_count', 'delivery_note']);
            $designRequest->fill([...$validated, 'updated_by' => $user->id])->save();
            $this->audit($designRequest, $user, 'design_request_updated', $old, $designRequest->only(array_keys($old)));
        });

        return back()->with('success', "需求 {$designRequest->reference_no} 已更新。");
    }

    /** @return list<array{id: int, name: string}> */
    private function designers(Store $store): array
    {
        $organization = $store->organization;

        return $store->members()->select('users.id', 'users.name')->orderBy('users.name')->get()
            ->filter(fn (User $member) => $member->hasPermission('design_requests.manage', $organization, $store))
            ->map(fn (User $member) => ['id' => (int) $member->id, 'name' => $member->name])
            ->values()->all();
    }

    /** @param Collection<int, DesignRequest> $rows */
    private function summary(Collection $rows, string $timezone): array
    {
        $today = CarbonImmutable::today($timezone);
        $active = $rows->whereNotIn('status', ['completed', 'cancelled']);
        $completed = $rows->where('status', 'completed');
        $overdue = $active->filter(fn (DesignRequest $item) => $item->planned_delivery_date->lt($today))->count();
        $onTime = $completed->filter(fn (DesignRequest $item) => $item->actual_delivery_date && $item->actual_delivery_date->lte($item->planned_delivery_date))->count();
        $turnaround = $completed->filter->actual_delivery_date
            ->map(fn (DesignRequest $item) => $item->requested_on->diffInDays($item->actual_delivery_date));

        return [
            'total' => $rows->count(),
            'pending' => $rows->where('status', 'pending')->count(),
            'in_progress' => $rows->whereIn('status', ['assigned', 'in_progress', 'review'])->count(),
            'completed' => $completed->count(),
            'overdue' => $overdue,
            'on_time_rate' => $completed->count() ? round($onTime / $completed->count() * 100, 1) : null,
            'average_turnaround_days' => $turnaround->isNotEmpty() ? round($turnaround->average(), 1) : null,
            'revision_count' => (int) $rows->sum('revision_count'),
            'status_counts' => collect(self::STATUSES)->mapWithKeys(fn (string $status) => [$status => $rows->where('status', $status)->count()])->all(),
        ];
    }

    private function serialize(DesignRequest $item, string $timezone): array
    {
        $today = CarbonImmutable::today($timezone);
        $comparison = $item->actual_delivery_date ?? $today;
        $delayDays = $comparison->gt($item->planned_delivery_date) ? $item->planned_delivery_date->diffInDays($comparison) : 0;

        return [
            'uuid' => $item->uuid, 'reference_no' => $item->reference_no,
            'request_type' => $item->request_type, 'priority' => $item->priority,
            'description' => $item->description, 'requester_department' => $item->requester_department,
            'requester' => $item->requester?->name, 'designer_id' => $item->designer_id,
            'designer' => $item->designer?->name, 'quantity' => $item->quantity,
            'requested_on' => $item->requested_on->toDateString(),
            'planned_delivery_date' => $item->planned_delivery_date->toDateString(),
            'actual_delivery_date' => $item->actual_delivery_date?->toDateString(),
            'status' => $item->status, 'is_delayed' => $delayDays > 0, 'delay_days' => $delayDays,
            'revision_count' => $item->revision_count, 'delivery_note' => $item->delivery_note,
        ];
    }

    private function audit(DesignRequest $item, User $user, string $action, array $old, array $new): void
    {
        AuditLog::query()->create([
            'organization_id' => $item->organization_id, 'store_id' => $item->store_id,
            'user_id' => $user->id, 'action' => $action,
            'subject_type' => DesignRequest::class, 'subject_id' => $item->id,
            'old_values' => $old, 'new_values' => $new,
            'metadata' => ['scope' => 'store', 'reference_no' => $item->reference_no],
        ]);
    }
}
