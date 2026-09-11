<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\DesignRequest;
use App\Models\DesignRequestAttachment;
use App\Models\Organization;
use App\Models\User;
use App\Services\Authorization\PersonalPermissionService;
use App\Support\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DesignRequestController extends Controller
{
    private const FULL_ACCESS_ROLES = ['designer', 'developer', 'super-admin'];

    private const TYPES = ['site_banner', 'edm', 'social_media', 'product_detail', 'video', 'other'];

    private const PRIORITIES = ['urgent', 'high', 'medium', 'low'];

    private const STATUSES = ['pending', 'assigned', 'in_progress', 'review', 'completed', 'cancelled'];

    public function __construct(private PersonalPermissionService $permissions) {}

    public function index(Request $request, CurrentOrganization $currentOrganization): Response
    {
        $organization = $currentOrganization->require();
        $user = $request->user();
        abort_unless($user, 401);
        $timezone = $this->timezone($user);

        $hasFullAccessRole = $this->hasFullAccessRole($user, $organization);
        $canViewAll = $hasFullAccessRole && $this->permissions->allows($user, $organization, 'design_requests.view_all');
        $canManage = $hasFullAccessRole && $this->permissions->allows($user, $organization, 'design_requests.manage');
        $filters = $request->validate([
            'tab' => ['nullable', Rule::in(['overview', 'list'])],
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'priority' => ['nullable', Rule::in(self::PRIORITIES)],
            'type' => ['nullable', Rule::in(self::TYPES)],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $activeTab = $canViewAll ? ($filters['tab'] ?? 'list') : 'list';

        $scope = DesignRequest::query()
            ->where('organization_id', $organization->id)
            ->when(! $canViewAll, fn (Builder $query) => $query->where('requester_id', $user->id));
        $allRows = (clone $scope)->get();
        $rows = (clone $scope)
            ->with(['requester:id,name', 'requester.roles:id,name', 'designer:id,name', 'attachments', 'progressLogs.user:id,name'])
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['priority'] ?? null, fn (Builder $query, string $priority) => $query->where('priority', $priority))
            ->when($filters['type'] ?? null, fn (Builder $query, string $type) => $query->where('request_type', $type))
            ->when(trim((string) ($filters['search'] ?? '')) !== '', function (Builder $query) use ($filters): void {
                $keyword = '%'.addcslashes(trim((string) $filters['search']), '%_\\').'%';
                $query->where(fn (Builder $query) => $query
                    ->where('reference_no', 'like', $keyword)
                    ->orWhere('task_name', 'like', $keyword)
                    ->orWhere('description', 'like', $keyword));
            })
            ->orderByRaw("case when status = 'completed' then 1 else 0 end")
            ->orderByRaw("case priority when 'urgent' then 1 when 'high' then 2 when 'medium' then 3 else 4 end")
            ->orderByDesc('requested_on')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('DesignRequests/Index', [
            'scope' => $canViewAll ? 'all' : 'mine',
            'today' => today($timezone)->toDateString(),
            'canCreate' => $this->permissions->allows($user, $organization, 'design_requests.create'),
            'canManage' => $canManage,
            'canViewOverview' => $canViewAll,
            'filters' => [
                'tab' => $activeTab, 'status' => $filters['status'] ?? '',
                'priority' => $filters['priority'] ?? '', 'type' => $filters['type'] ?? '',
                'search' => trim((string) ($filters['search'] ?? '')),
            ],
            'options' => [
                'types' => self::TYPES, 'priorities' => self::PRIORITIES, 'statuses' => self::STATUSES,
            ],
            'summary' => $this->summary($allRows, $timezone),
            'requests' => [
                ...$rows->toArray(),
                'data' => collect($rows->items())->map(fn (DesignRequest $item) => $this->serialize($item, $timezone, $user, $canManage))->all(),
            ],
        ]);
    }

    public function store(Request $request, CurrentOrganization $currentOrganization): RedirectResponse
    {
        $organization = $currentOrganization->require();
        $user = $request->user();
        abort_unless($user, 401);
        $timezone = $this->timezone($user);
        $validated = $request->validate([
            'task_name' => ['required', 'string', 'max:160', 'regex:/\S/u'],
            'request_type' => ['required', Rule::in(self::TYPES)],
            'priority' => ['required', Rule::in(self::PRIORITIES)],
            'description' => ['required', 'string', 'max:5000'],
            'planned_delivery_date' => ['required', 'date', 'after_or_equal:today'],
            'images' => ['nullable', 'array', 'max:10'],
            'images.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:8192'],
        ]);
        $images = $validated['images'] ?? [];
        unset($validated['images']);
        $storedPaths = [];

        try {
            $designRequest = DB::transaction(function () use ($images, $validated, $organization, &$storedPaths, $timezone, $user): DesignRequest {
                $item = DesignRequest::query()->create([
                    ...$validated,
                    'organization_id' => $organization->id,
                    'store_id' => null,
                    'requester_id' => $user->id,
                    'requested_on' => today($timezone)->toDateString(),
                    'status' => 'pending',
                    'revision_count' => 0,
                    'updated_by' => $user->id,
                ]);

                foreach ($images as $index => $image) {
                    $extension = $image->guessExtension() ?: 'img';
                    $filename = Str::uuid().'.'.$extension;
                    $path = $image->storeAs("design-requests/{$organization->id}/{$item->uuid}", $filename, 'local');
                    abort_unless(is_string($path), 500, '任务图片保存失败。');
                    $storedPaths[] = $path;
                    $item->attachments()->create([
                        'uploaded_by' => $user->id,
                        'disk' => 'local',
                        'path' => $path,
                        'original_name' => $image->getClientOriginalName(),
                        'mime_type' => (string) $image->getMimeType(),
                        'size' => (int) $image->getSize(),
                        'sort_order' => $index,
                    ]);
                }

                $this->audit($item, $user, 'design_request_created', [], [
                    'status' => 'pending',
                    'image_count' => count($images),
                ]);

                return $item;
            });
        } catch (\Throwable $exception) {
            foreach ($storedPaths as $path) {
                Storage::disk('local')->delete($path);
            }

            throw $exception;
        }

        return back()->with('success', "任务 {$designRequest->reference_no} 已提交。");
    }

    public function attachment(
        Request $request,
        DesignRequest $designRequest,
        DesignRequestAttachment $attachment,
        CurrentOrganization $currentOrganization,
    ): StreamedResponse {
        $organization = $currentOrganization->require();
        $user = $request->user();
        abort_unless($user && (int) $designRequest->organization_id === (int) $organization->id, 404);
        abort_unless(
            (int) $designRequest->requester_id === (int) $user->id
                || ($this->hasFullAccessRole($user, $organization)
                    && $this->permissions->allows($user, $organization, 'design_requests.view_all')),
            404,
        );
        abort_unless((int) $attachment->design_request_id === (int) $designRequest->id, 404);
        abort_unless(Storage::disk($attachment->disk)->exists($attachment->path), 404);
        $extension = pathinfo($attachment->path, PATHINFO_EXTENSION) ?: 'img';

        return Storage::disk($attachment->disk)->response(
            $attachment->path,
            $attachment->uuid.'.'.$extension,
            [
                'Content-Type' => $attachment->mime_type,
                'Content-Disposition' => 'inline; filename="'.$attachment->uuid.'.'.$extension.'"',
                'Cache-Control' => 'private, max-age=3600',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    public function update(Request $request, DesignRequest $designRequest, CurrentOrganization $currentOrganization): RedirectResponse
    {
        $organization = $currentOrganization->require();
        $user = $request->user();
        abort_unless($user && (int) $designRequest->organization_id === (int) $organization->id, 404);
        abort_unless(
            $this->hasFullAccessRole($user, $organization)
                && $this->permissions->allows($user, $organization, 'design_requests.manage'),
            403,
        );
        abort_unless((int) $designRequest->designer_id === (int) $user->id, 403);
        abort_if(in_array($designRequest->status, ['completed', 'cancelled'], true), 409, '已结束的任务不能继续处理。');
        $validated = $request->validate([
            'action' => ['required', Rule::in(['progress', 'complete'])],
            'progress_note' => ['nullable', 'string', 'max:3000', 'required_if:action,progress'],
        ]);
        $note = trim((string) ($validated['progress_note'] ?? ''));
        $completed = $validated['action'] === 'complete';
        $timezone = $this->timezone($user);

        DB::transaction(function () use ($completed, $designRequest, $note, $timezone, $user): void {
            $old = $designRequest->only(['status', 'actual_delivery_date', 'delivery_note']);
            $designRequest->forceFill([
                'status' => $completed ? 'completed' : 'in_progress',
                'actual_delivery_date' => $completed ? today($timezone)->toDateString() : null,
                'delivery_note' => $note !== '' ? $note : $designRequest->delivery_note,
                'updated_by' => $user->id,
            ])->save();
            $designRequest->progressLogs()->create([
                'user_id' => $user->id,
                'action' => $completed ? 'completed' : 'progress',
                'content' => $note !== '' ? $note : null,
            ]);
            $this->audit($designRequest, $user, $completed ? 'design_request_completed' : 'design_request_progressed', $old, $designRequest->only(array_keys($old)));
        });

        return back()->with('success', $completed
            ? "任务 {$designRequest->reference_no} 已完成。"
            : "任务 {$designRequest->reference_no} 的进展已提交。");
    }

    public function accept(Request $request, DesignRequest $designRequest, CurrentOrganization $currentOrganization): RedirectResponse
    {
        $organization = $currentOrganization->require();
        $user = $request->user();
        abort_unless($user && (int) $designRequest->organization_id === (int) $organization->id, 404);
        abort_unless(
            $this->hasFullAccessRole($user, $organization)
                && $this->permissions->allows($user, $organization, 'design_requests.manage'),
            403,
        );

        $accepted = DB::transaction(function () use ($designRequest, $organization, $user): bool {
            $item = DesignRequest::query()
                ->whereKey($designRequest->getKey())
                ->where('organization_id', $organization->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($item->designer_id !== null) {
                return (int) $item->designer_id === (int) $user->id;
            }

            $old = $item->only(['designer_id', 'status']);
            $item->forceFill([
                'designer_id' => $user->id,
                'status' => 'assigned',
                'updated_by' => $user->id,
            ])->save();
            $item->progressLogs()->create([
                'user_id' => $user->id,
                'action' => 'accepted',
                'content' => null,
            ]);
            $this->audit($item, $user, 'design_request_accepted', $old, $item->only(['designer_id', 'status']));

            return true;
        });

        if (! $accepted) {
            return back()->withErrors(['accept' => '该任务已被其他人接受。']);
        }

        return back()->with('success', "任务 {$designRequest->reference_no} 已由你接受。");
    }

    private function timezone(User $user): string
    {
        $timezone = filled($user->timezone) ? (string) $user->timezone : (string) config('app.timezone', 'UTC');

        return in_array($timezone, timezone_identifiers_list(\DateTimeZone::ALL_WITH_BC), true) ? $timezone : 'UTC';
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
            'status_counts' => collect(self::STATUSES)->mapWithKeys(fn (string $status) => [$status => $rows->where('status', $status)->count()])->all(),
        ];
    }

    private function serialize(DesignRequest $item, string $timezone, User $user, bool $canManage): array
    {
        $today = CarbonImmutable::today($timezone);
        $comparison = $item->actual_delivery_date ?? $today;
        $delayDays = $comparison->gt($item->planned_delivery_date) ? $item->planned_delivery_date->diffInDays($comparison) : 0;

        return [
            'uuid' => $item->uuid, 'reference_no' => $item->reference_no,
            'task_name' => $item->task_name ?: Str::limit($item->description, 80),
            'request_type' => $item->request_type, 'priority' => $item->priority,
            'description' => $item->description,
            'requester' => $item->requester?->name, 'designer_id' => $item->designer_id,
            'requester_role' => $this->requesterRole($item),
            'designer' => $item->designer?->name,
            'requested_on' => $item->requested_on->toDateString(),
            'planned_delivery_date' => $item->planned_delivery_date->toDateString(),
            'actual_delivery_date' => $item->actual_delivery_date?->toDateString(),
            'status' => $item->status, 'is_delayed' => $delayDays > 0, 'delay_days' => $delayDays,
            'can_accept' => $canManage && $item->designer_id === null && $item->status === 'pending',
            'can_process' => $canManage
                && (int) $item->designer_id === (int) $user->id
                && ! in_array($item->status, ['completed', 'cancelled'], true),
            'progress_logs' => $item->progressLogs->map(fn ($log) => [
                'uuid' => $log->uuid,
                'action' => $log->action,
                'content' => $log->content,
                'user' => $log->user?->name ?? '已离职用户',
                'created_at' => $log->created_at?->timezone($timezone)->format('Y-m-d H:i'),
            ])->values()->all(),
            'images' => $item->attachments->map(fn (DesignRequestAttachment $attachment) => [
                'uuid' => $attachment->uuid,
                'name' => $attachment->original_name,
                'mime_type' => $attachment->mime_type,
                'size' => $attachment->size,
                'url' => route('design-requests.attachments.show', [$item, $attachment], false),
            ])->values()->all(),
        ];
    }

    private function requesterRole(DesignRequest $item): string
    {
        if (! $item->requester) {
            return '未分配角色';
        }

        $roles = $item->requester->roles
            ->filter(fn ($role) => (int) $role->pivot->organization_id === (int) $item->organization_id)
            ->pluck('name')
            ->unique()
            ->sort()
            ->implode('、');

        return $roles !== '' ? $roles : '未分配角色';
    }

    private function hasFullAccessRole(User $user, Organization $organization): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->roles()
            ->where('user_roles.organization_id', $organization->getKey())
            ->whereIn('roles.slug', self::FULL_ACCESS_ROLES)
            ->exists();
    }

    private function audit(DesignRequest $item, User $user, string $action, array $old, array $new): void
    {
        AuditLog::query()->create([
            'organization_id' => $item->organization_id, 'store_id' => null,
            'user_id' => $user->id, 'action' => $action,
            'subject_type' => DesignRequest::class, 'subject_id' => $item->id,
            'old_values' => $old, 'new_values' => $new,
            'metadata' => ['scope' => 'personal', 'reference_no' => $item->reference_no],
        ]);
    }
}
