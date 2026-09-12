<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\DesignRequest;
use App\Models\DesignRequestAttachment;
use App\Models\Organization;
use App\Models\User;
use App\Services\Authorization\PersonalPermissionService;
use App\Services\BusinessNotificationService;
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

    public function __construct(
        private PersonalPermissionService $permissions,
        private BusinessNotificationService $notifications,
    ) {}

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
        $allRows = (clone $scope)->with('designer:id,name')->get();
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

        $this->notifications->notify(
            $organization,
            $this->notifications->usersWithPermission($organization, 'design_requests.manage', self::FULL_ACCESS_ROLES),
            $user,
            'design_request.submitted',
            '有新的设计需求',
            "{$user->name} 提交了 {$designRequest->reference_no}：{$designRequest->task_name}",
            route('design-requests.index', ['tab' => 'list', 'search' => $designRequest->reference_no], false),
            $designRequest,
            "design-request:{$designRequest->uuid}:submitted",
        );

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
        abort_unless(in_array($designRequest->status, ['assigned', 'in_progress'], true), 409, '当前任务状态不能提交进展或交付。');
        $validated = $request->validate([
            'action' => ['required', Rule::in(['progress', 'submit_delivery'])],
            'progress_note' => ['required', 'string', 'max:3000', 'regex:/\S/u'],
        ]);
        $note = trim((string) $validated['progress_note']);
        $submitted = $validated['action'] === 'submit_delivery';

        $progressLogId = DB::transaction(function () use ($submitted, $designRequest, $note, $user): int {
            $old = $designRequest->only(['status', 'delivery_submitted_at', 'reviewed_at', 'delivery_note']);
            $designRequest->forceFill([
                'status' => $submitted ? 'review' : 'in_progress',
                'delivery_submitted_at' => $submitted ? now() : $designRequest->delivery_submitted_at,
                'reviewed_at' => $submitted ? null : $designRequest->reviewed_at,
                'delivery_note' => $submitted ? $note : $designRequest->delivery_note,
                'updated_by' => $user->id,
            ])->save();
            $progressLog = $designRequest->progressLogs()->create([
                'user_id' => $user->id,
                'action' => $submitted ? 'delivery_submitted' : 'progress',
                'content' => $note,
            ]);
            $this->audit($designRequest, $user, $submitted ? 'design_request_delivery_submitted' : 'design_request_progressed', $old, $designRequest->only(array_keys($old)));

            return (int) $progressLog->id;
        });

        $this->notifications->notify(
            $organization,
            [$designRequest->requester_id],
            $user,
            $submitted ? 'design_request.delivery_submitted' : 'design_request.progressed',
            $submitted ? '设计需求等待验收' : '设计需求有新进展',
            "{$designRequest->reference_no}：{$designRequest->task_name}",
            route('design-requests.index', ['tab' => 'list', 'search' => $designRequest->reference_no], false),
            $designRequest,
            "design-request:{$designRequest->uuid}:progress:{$progressLogId}",
        );

        return back()->with('success', $submitted
            ? "任务 {$designRequest->reference_no} 已提交交付，等待提报人验收。"
            : "任务 {$designRequest->reference_no} 的进展已提交。");
    }

    public function review(Request $request, DesignRequest $designRequest, CurrentOrganization $currentOrganization): RedirectResponse
    {
        $organization = $currentOrganization->require();
        $user = $request->user();
        abort_unless(
            $user
                && (int) $designRequest->organization_id === (int) $organization->id
                && (int) $designRequest->requester_id === (int) $user->id,
            404,
        );
        $validated = $request->validate([
            'action' => ['required', Rule::in(['confirm', 'revision'])],
            'review_note' => ['nullable', 'string', 'max:3000', 'required_if:action,revision', 'regex:/\S/u'],
        ]);
        $note = trim((string) ($validated['review_note'] ?? ''));
        $confirmed = $validated['action'] === 'confirm';
        $timezone = $this->timezone($user);

        $result = DB::transaction(function () use ($confirmed, $designRequest, $note, $organization, $timezone, $user): array {
            $item = DesignRequest::query()
                ->whereKey($designRequest->getKey())
                ->where('organization_id', $organization->getKey())
                ->where('requester_id', $user->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless($item->status === 'review' && $item->designer_id !== null, 409, '该任务当前不在待验收状态。');

            $old = $item->only(['status', 'revision_count', 'actual_delivery_date', 'reviewed_at', 'completed_at']);
            $reviewedAt = CarbonImmutable::now();
            $item->forceFill([
                'status' => $confirmed ? 'completed' : 'in_progress',
                'revision_count' => $confirmed ? $item->revision_count : $item->revision_count + 1,
                'actual_delivery_date' => $confirmed ? $reviewedAt->timezone($timezone)->toDateString() : null,
                'reviewed_at' => $reviewedAt,
                'completed_at' => $confirmed ? $reviewedAt : null,
                'updated_by' => $user->id,
            ])->save();
            $progressLog = $item->progressLogs()->create([
                'user_id' => $user->id,
                'action' => $confirmed ? 'completed' : 'revision_requested',
                'content' => $note !== '' ? $note : null,
            ]);
            $this->audit($item, $user, $confirmed ? 'design_request_confirmed' : 'design_request_revision_requested', $old, $item->only(array_keys($old)));

            return [
                'designer_id' => (int) $item->designer_id,
                'progress_log_id' => (int) $progressLog->id,
                'revision_count' => (int) $item->revision_count,
            ];
        });

        $this->notifications->notify(
            $organization,
            [$result['designer_id']],
            $user,
            $confirmed ? 'design_request.confirmed' : 'design_request.revision_requested',
            $confirmed ? '设计需求验收通过' : '设计需求需要改稿',
            $confirmed
                ? "{$designRequest->reference_no}：{$designRequest->task_name} 已验收完成"
                : "{$designRequest->reference_no}：{$designRequest->task_name} 需要第 {$result['revision_count']} 次改稿",
            route('design-requests.index', ['tab' => 'list', 'search' => $designRequest->reference_no], false),
            $designRequest,
            "design-request:{$designRequest->uuid}:review:{$result['progress_log_id']}",
        );

        return back()->with('success', $confirmed
            ? "任务 {$designRequest->reference_no} 已验收完成。"
            : "任务 {$designRequest->reference_no} 已退回改稿。"
        );
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

            $old = $item->only(['designer_id', 'status', 'accepted_at']);
            $item->forceFill([
                'designer_id' => $user->id,
                'status' => 'assigned',
                'accepted_at' => now(),
                'updated_by' => $user->id,
            ])->save();
            $item->progressLogs()->create([
                'user_id' => $user->id,
                'action' => 'accepted',
                'content' => null,
            ]);
            $this->audit($item, $user, 'design_request_accepted', $old, $item->only(array_keys($old)));

            return true;
        });

        if (! $accepted) {
            return back()->withErrors(['accept' => '该任务已被其他人接受。']);
        }

        $this->notifications->notify(
            $organization,
            [$designRequest->requester_id],
            $user,
            'design_request.accepted',
            '设计需求已被接受',
            "{$user->name} 已接受 {$designRequest->reference_no}：{$designRequest->task_name}",
            route('design-requests.index', ['tab' => 'list', 'search' => $designRequest->reference_no], false),
            $designRequest,
            "design-request:{$designRequest->uuid}:accepted",
        );

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
        $onTime = $completed->filter(fn (DesignRequest $item) => $this->wasSubmittedOnTime($item, $timezone))->count();
        $turnaround = $completed->filter->delivery_submitted_at
            ->map(function (DesignRequest $item): float {
                $startedAt = $item->accepted_at ?? $item->created_at;
                $hours = $startedAt->diffInHours($item->delivery_submitted_at, false);

                return max(0, $hours / 24);
            });
        $designerPerformance = $rows
            ->filter(fn (DesignRequest $item) => $item->designer_id !== null)
            ->groupBy('designer_id')
            ->map(function (Collection $items) use ($timezone): array {
                $completedItems = $items->where('status', 'completed');
                $onTimeCount = $completedItems->filter(fn (DesignRequest $item) => $this->wasSubmittedOnTime($item, $timezone))->count();

                return [
                    'designer_id' => (int) $items->first()->designer_id,
                    'designer' => $items->first()->designer?->name ?? '已离职设计师',
                    'total' => $items->count(),
                    'in_progress' => $items->whereIn('status', ['assigned', 'in_progress'])->count(),
                    'pending_review' => $items->where('status', 'review')->count(),
                    'completed' => $completedItems->count(),
                    'on_time_rate' => $completedItems->count() ? round($onTimeCount / $completedItems->count() * 100, 1) : null,
                    'average_revisions' => $completedItems->isNotEmpty() ? round((float) $completedItems->average('revision_count'), 1) : null,
                ];
            })
            ->sortByDesc('completed')
            ->values()
            ->all();

        return [
            'total' => $rows->count(),
            'pending' => $rows->where('status', 'pending')->count(),
            'in_progress' => $rows->whereIn('status', ['assigned', 'in_progress', 'review'])->count(),
            'completed' => $completed->count(),
            'overdue' => $overdue,
            'on_time_rate' => $completed->count() ? round($onTime / $completed->count() * 100, 1) : null,
            'average_turnaround_days' => $turnaround->isNotEmpty() ? round($turnaround->average(), 1) : null,
            'status_counts' => collect(self::STATUSES)->mapWithKeys(fn (string $status) => [$status => $rows->where('status', $status)->count()])->all(),
            'designer_performance' => $designerPerformance,
        ];
    }

    private function wasSubmittedOnTime(DesignRequest $item, string $timezone): bool
    {
        $deliveryDate = $item->delivery_submitted_at?->timezone($timezone)->toDateString()
            ?? $item->actual_delivery_date?->toDateString();

        return $deliveryDate !== null && $deliveryDate <= $item->planned_delivery_date->toDateString();
    }

    private function serialize(DesignRequest $item, string $timezone, User $user, bool $canManage): array
    {
        $today = CarbonImmutable::today($timezone);
        $comparison = in_array($item->status, ['review', 'completed'], true)
            ? ($item->delivery_submitted_at?->timezone($timezone)->startOfDay() ?? $item->actual_delivery_date ?? $today)
            : $today;
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
            'submitted_at' => $item->created_at?->timezone($timezone)->format('Y-m-d H:i'),
            'planned_delivery_date' => $item->planned_delivery_date->toDateString(),
            'actual_delivery_date' => $item->actual_delivery_date?->toDateString(),
            'accepted_at' => $item->accepted_at?->timezone($timezone)->format('Y-m-d H:i'),
            'delivery_submitted_at' => $item->delivery_submitted_at?->timezone($timezone)->format('Y-m-d H:i'),
            'reviewed_at' => $item->reviewed_at?->timezone($timezone)->format('Y-m-d H:i'),
            'completed_at' => $item->completed_at?->timezone($timezone)->format('Y-m-d H:i'),
            'revision_count' => $item->revision_count,
            'status' => $item->status, 'is_delayed' => $delayDays > 0, 'delay_days' => $delayDays,
            'can_accept' => $canManage && $item->designer_id === null && $item->status === 'pending',
            'can_process' => $canManage
                && (int) $item->designer_id === (int) $user->id
                && in_array($item->status, ['assigned', 'in_progress'], true),
            'can_review' => (int) $item->requester_id === (int) $user->id && $item->status === 'review',
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
