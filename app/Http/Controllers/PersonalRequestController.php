<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\PersonalRequest;
use App\Models\PersonalRequestAttachment;
use App\Models\User;
use App\Services\Authorization\PersonalPermissionService;
use App\Services\BusinessNotificationService;
use App\Services\FinanceService;
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

class PersonalRequestController extends Controller
{
    private const TECH_CATEGORIES = ['bug', 'feature', 'data', 'automation', 'access', 'other'];

    private const EXPENSE_CATEGORIES = ['travel', 'advertising', 'software', 'office', 'entertainment', 'logistics', 'other'];

    private const PRIORITIES = ['urgent', 'high', 'medium', 'low'];

    private const TECH_STATUSES = ['pending_approval', 'approved', 'rejected', 'assigned', 'in_progress', 'completed'];

    public function __construct(
        private PersonalPermissionService $permissions,
        private BusinessNotificationService $notifications,
    ) {}

    public function technicalIndex(Request $request, CurrentOrganization $context): Response
    {
        $organization = $context->require();
        $user = $request->user();
        abort_unless($user, 401);
        $canViewAll = $this->hasRole($user, $organization, ['developer', 'super-admin'])
            && $this->permissions->allows($user, $organization, 'technical_requests.view_all');
        $canManage = $canViewAll && $this->permissions->allows($user, $organization, 'technical_requests.manage');
        $timezone = $this->timezone($user);
        $filters = $request->validate([
            'tab' => ['nullable', Rule::in(['overview', 'list'])],
            'status' => ['nullable', Rule::in(self::TECH_STATUSES)],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $activeTab = $canViewAll ? ($filters['tab'] ?? 'list') : 'list';
        $scope = PersonalRequest::query()->where('organization_id', $organization->id)->where('kind', 'technical')
            ->when(! $canViewAll, fn (Builder $query) => $query->where('submitter_id', $user->id));
        $allRows = (clone $scope)
            ->with(['assignee:id,name', 'progressLogs:id,personal_request_id,action,created_at'])
            ->get();
        $query = (clone $scope)
            ->with(['submitter:id,name', 'assignee:id,name', 'attachments', 'progressLogs.user:id,name'])
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when(trim((string) ($filters['search'] ?? '')) !== '', function (Builder $query) use ($filters): void {
                $keyword = '%'.addcslashes(trim((string) $filters['search']), '%_\\').'%';
                $query->where(fn (Builder $query) => $query->where('reference_no', 'like', $keyword)->orWhere('title', 'like', $keyword)->orWhere('description', 'like', $keyword));
            })
            ->orderByRaw("case when status = 'completed' then 1 else 0 end")
            ->orderByDesc('created_at')->paginate(20)->withQueryString();

        return Inertia::render('Requests/Technical', [
            'scope' => $canViewAll ? 'all' : 'mine',
            'canCreate' => $this->permissions->allows($user, $organization, 'technical_requests.create'),
            'canManage' => $canManage,
            'canViewOverview' => $canViewAll,
            'today' => CarbonImmutable::today($timezone)->toDateString(),
            'filters' => [
                'tab' => $activeTab,
                'status' => $filters['status'] ?? '',
                'search' => trim((string) ($filters['search'] ?? '')),
            ],
            'options' => ['categories' => self::TECH_CATEGORIES, 'priorities' => self::PRIORITIES],
            'summary' => $this->technicalSummary($allRows, $timezone),
            'requests' => [...$query->toArray(), 'data' => collect($query->items())->map(fn (PersonalRequest $item) => $this->serialize($item, $user, $canManage))->all()],
        ]);
    }

    public function expenseIndex(Request $request, CurrentOrganization $context): Response
    {
        $organization = $context->require();
        $user = $request->user();
        abort_unless($user, 401);
        $canViewAll = $this->permissions->allows($user, $organization, 'expense_claims.view_all');
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['pending_approval', 'rejected', 'pending_reimbursement', 'reimbursed'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $status = $filters['status'] ?? '';
        $query = PersonalRequest::query()->where('organization_id', $organization->id)->where('kind', 'expense')
            ->when(! $canViewAll, fn (Builder $query) => $query->where('submitter_id', $user->id))
            ->with(['submitter:id,name', 'reviewer:id,name', 'payer:id,name', 'attachments'])
            ->when($status === 'pending_approval', fn (Builder $query) => $query->where('status', 'pending_approval'))
            ->when($status === 'rejected', fn (Builder $query) => $query->where('status', 'rejected'))
            ->when($status === 'pending_reimbursement', fn (Builder $query) => $query->where('status', 'approved')->where('payment_status', 'pending'))
            ->when($status === 'reimbursed', fn (Builder $query) => $query->where('status', 'approved')->where('payment_status', 'paid'))
            ->orderByDesc('created_at')->paginate(20)->withQueryString();

        return Inertia::render('Requests/Expenses', [
            'scope' => $canViewAll ? 'all' : 'mine',
            'canCreate' => $this->permissions->allows($user, $organization, 'expense_claims.create'),
            'filters' => ['status' => $status],
            'options' => ['categories' => self::EXPENSE_CATEGORIES],
            'requests' => [...$query->toArray(), 'data' => collect($query->items())->map(fn (PersonalRequest $item) => $this->serialize($item, $user, false))->all()],
        ]);
    }

    public function expenseRequestIndex(Request $request, CurrentOrganization $context): Response
    {
        $organization = $context->require();
        $user = $request->user();
        abort_unless($user, 401);
        $canViewAll = $this->hasRole($user, $organization, ['super-admin'])
            && $this->permissions->allows($user, $organization, 'expense_requests.view_all');
        $filters = $request->validate(['status' => ['nullable', 'string', 'max:30'], 'page' => ['nullable', 'integer', 'min:1']]);
        $query = PersonalRequest::query()->where('organization_id', $organization->id)->where('kind', 'expense_request')
            ->when(! $canViewAll, fn (Builder $query) => $query->where('submitter_id', $user->id))
            ->with(['submitter:id,name', 'reviewer:id,name', 'payer:id,name', 'attachments'])
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->orderByDesc('created_at')->paginate(20)->withQueryString();

        return Inertia::render('Requests/CostApplications', [
            'scope' => $canViewAll ? 'all' : 'mine',
            'canCreate' => $this->permissions->allows($user, $organization, 'expense_requests.create'),
            'today' => now()->toDateString(),
            'filters' => ['status' => $filters['status'] ?? ''],
            'options' => ['categories' => self::EXPENSE_CATEGORIES],
            'requests' => [...$query->toArray(), 'data' => collect($query->items())->map(fn (PersonalRequest $item) => $this->serialize($item, $user, false))->all()],
        ]);
    }

    public function approvalIndex(Request $request, CurrentOrganization $context): Response
    {
        $organization = $context->require();
        $user = $request->user();
        abort_unless($user, 401);
        abort_unless($this->hasRole($user, $organization, ['super-admin']), 403);
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['pending_approval', 'approved', 'rejected'])],
            'kind' => ['nullable', Rule::in(['technical', 'expense_request', 'expense'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $query = PersonalRequest::query()->where('organization_id', $organization->id)
            ->whereIn('kind', ['technical', 'expense_request', 'expense'])
            ->with(['submitter:id,name', 'reviewer:id,name', 'payer:id,name', 'attachments'])
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status), fn (Builder $query) => $query->where('status', 'pending_approval'))
            ->when($filters['kind'] ?? null, fn (Builder $query, string $kind) => $query->where('kind', $kind))
            ->orderByRaw("case when status = 'pending_approval' then 0 else 1 end")
            ->orderByDesc('created_at')->paginate(20)->withQueryString();

        return Inertia::render('Requests/Approvals', [
            'canManage' => $this->permissions->allows($user, $organization, 'request_approvals.manage'),
            'filters' => ['status' => $filters['status'] ?? 'pending_approval', 'kind' => $filters['kind'] ?? ''],
            'requests' => [...$query->toArray(), 'data' => collect($query->items())->map(fn (PersonalRequest $item) => $this->serialize($item, $user, false))->all()],
        ]);
    }

    public function storeTechnical(Request $request, CurrentOrganization $context): RedirectResponse
    {
        $organization = $context->require();
        $user = $request->user();
        abort_unless($user, 401);
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:180', 'regex:/\S/u'],
            'category' => ['required', Rule::in(self::TECH_CATEGORIES)],
            'priority' => ['required', Rule::in(self::PRIORITIES)],
            'desired_date' => ['required', 'date', 'after_or_equal:today'],
            'description' => ['required', 'string', 'max:5000'],
            'images' => ['nullable', 'array', 'max:10'],
            'images.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:8192'],
        ]);
        $images = $validated['images'] ?? [];
        unset($validated['images']);
        $item = $this->createWithAttachments($organization, $user, ['kind' => 'technical', 'status' => 'pending_approval', ...$validated], $images);
        $this->audit($item, $user, 'technical_request_created');
        $this->notifyApprovers($organization, $user, $item, '新的技术需求待审批');

        return back()->with('success', "技术需求 {$item->reference_no} 已提交审批。");
    }

    public function storeExpense(Request $request, CurrentOrganization $context): RedirectResponse
    {
        $organization = $context->require();
        $user = $request->user();
        abort_unless($user, 401);
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:180', 'regex:/\S/u'],
            'category' => ['required', Rule::in(self::EXPENSE_CATEGORIES)],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999.99'],
            'currency' => ['required', Rule::in(['CNY', 'USD', 'EUR', 'GBP'])],
            'expense_date' => ['required', 'date', 'before_or_equal:today'],
            'description' => ['required', 'string', 'max:5000'],
            'images' => ['required', 'array', 'min:1', 'max:10'],
            'images.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:8192'],
        ]);
        $images = $validated['images'];
        unset($validated['images']);
        $item = $this->createWithAttachments($organization, $user, ['kind' => 'expense', 'status' => 'pending_approval', ...$validated], $images);
        $this->audit($item, $user, 'expense_claim_created');
        $this->notifyApprovers($organization, $user, $item, '新的报销申请待审批');

        return back()->with('success', "报销申请 {$item->reference_no} 已提交审批。");
    }

    public function storeExpenseRequest(Request $request, CurrentOrganization $context): RedirectResponse
    {
        $organization = $context->require();
        $user = $request->user();
        abort_unless($user, 401);
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:180', 'regex:/\S/u'],
            'category' => ['required', Rule::in(self::EXPENSE_CATEGORIES)],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999.99'],
            'currency' => ['required', Rule::in(['CNY', 'USD', 'EUR', 'GBP'])],
            'desired_date' => ['required', 'date', 'after_or_equal:today'],
            'description' => ['required', 'string', 'max:5000'],
            'approval_required' => ['sometimes', 'boolean'],
            'software_url' => ['exclude_unless:category,software', 'nullable', 'url:http,https', 'max:500'],
            'software_account' => ['exclude_unless:category,software', 'nullable', 'string', 'max:255'],
            'software_password' => ['exclude_unless:category,software', 'nullable', 'string', 'max:500'],
            'software_payment_method' => ['exclude_unless:category,software', 'required', 'string', 'max:255', 'regex:/\S/u'],
            'renewal_mode' => ['exclude_unless:category,software', 'required', Rule::in(['automatic', 'manual'])],
            'billing_cycle' => ['exclude_unless:category,software', 'required', Rule::in(['monthly', 'annual'])],
            'images' => ['nullable', 'array', 'max:10'],
            'images.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:8192'],
        ]);
        $images = $validated['images'] ?? [];
        unset($validated['images']);
        $approvalRequired = (bool) ($validated['approval_required'] ?? true);
        $item = $this->createWithAttachments($organization, $user, [
            ...$validated,
            'kind' => 'expense_request',
            'status' => $approvalRequired ? 'pending_approval' : 'approved',
            'approval_required' => $approvalRequired,
            'payment_status' => $approvalRequired ? null : 'pending',
            'reviewed_at' => $approvalRequired ? null : now(),
        ], $images);
        $this->audit($item, $user, $approvalRequired ? 'expense_request_created' : 'expense_request_created_without_approval');

        if ($approvalRequired) {
            $this->notifyApprovers($organization, $user, $item, '新的费用申请待审批');
        } else {
            $item->progressLogs()->create([
                'user_id' => $user->id,
                'action' => 'approval_exempted',
                'content' => '免审批，直接进入财务待付款。',
            ]);
            $this->notifyFinancePaymentRequired($organization, $user, $item);
        }

        return back()->with('success', $approvalRequired
            ? "费用申请 {$item->reference_no} 已提交审批。"
            : "费用申请 {$item->reference_no} 已免审批并提交财务付款。");
    }

    public function cancelExpenseRequestRenewal(
        Request $request,
        PersonalRequest $personalRequest,
        CurrentOrganization $context,
        FinanceService $finance,
    ): RedirectResponse {
        $organization = $context->require();
        $user = $request->user();
        abort_unless($user && (int) $personalRequest->organization_id === (int) $organization->id, 404);
        abort_unless(
            (int) $personalRequest->submitter_id === (int) $user->id
                || $this->hasRole($user, $organization, ['super-admin']),
            403
        );
        $validated = $request->validate([
            'cancelled_on' => ['required', 'date', 'before_or_equal:today'],
        ]);

        $item = $finance->cancelSoftwareRenewal($organization, $user, $personalRequest, $validated);
        $recipients = array_values(array_unique([
            ...$this->notifications->usersWithPermission($organization, 'finance.manage'),
            (int) $item->submitter_id,
        ]));
        $this->notifications->notify(
            $organization,
            $recipients,
            $user,
            'expense_request.renewal_cancelled',
            '持续付费项目已取消',
            "{$item->reference_no}：{$item->title}，取消日期 {$item->cancelled_on?->toDateString()}",
            route('finance.renewal-history', ['month' => $item->cancelled_on?->format('Y-m')], false),
            $item,
            "personal-request:{$item->uuid}:renewal-cancelled:{$item->cancelled_on?->toDateString()}",
        );

        return back()->with('success', '续费已取消；取消日前已经发生的付款记录仍保留在财务历史中。');
    }

    public function review(Request $request, PersonalRequest $personalRequest, CurrentOrganization $context): RedirectResponse
    {
        $organization = $context->require();
        $user = $request->user();
        abort_unless($user && (int) $personalRequest->organization_id === (int) $organization->id, 404);
        abort_unless($this->hasRole($user, $organization, ['super-admin']), 403);
        abort_unless($this->permissions->allows($user, $organization, 'request_approvals.manage'), 403);
        abort_unless($personalRequest->status === 'pending_approval', 409, '该申请已经审批。');
        $validated = $request->validate([
            'action' => ['required', Rule::in(['approve', 'reject'])],
            'note' => ['nullable', 'string', 'max:2000', 'required_if:action,reject'],
        ], [
            'note.required_if' => '驳回时必须填写审批意见。',
        ]);
        $approved = $validated['action'] === 'approve';
        $note = trim((string) ($validated['note'] ?? ''));
        DB::transaction(function () use ($approved, $note, $personalRequest, $user): void {
            $personalRequest->forceFill([
                'status' => $approved ? 'approved' : 'rejected',
                'payment_status' => $approved && in_array($personalRequest->kind, ['expense_request', 'expense'], true) ? 'pending' : null,
                'reviewer_id' => $user->id,
                'review_note' => $note !== '' ? $note : null,
                'reviewed_at' => now(),
            ])->save();
            $personalRequest->progressLogs()->create(['user_id' => $user->id, 'action' => $approved ? 'approved' : 'rejected', 'content' => $note !== '' ? $note : null]);
            $this->audit($personalRequest, $user, $approved ? 'personal_request_approved' : 'personal_request_rejected');
        });

        $kindLabel = match ($personalRequest->kind) {
            'technical' => '技术需求',
            'expense_request' => '费用申请',
            default => '报销申请',
        };
        $this->notifications->notify(
            $organization,
            [$personalRequest->submitter_id],
            $user,
            "{$personalRequest->kind}.reviewed",
            $approved ? "{$kindLabel}审批已通过" : "{$kindLabel}审批未通过",
            "{$personalRequest->reference_no}：{$personalRequest->title}",
            $this->personalRequestUrl($personalRequest),
            $personalRequest,
            "personal-request:{$personalRequest->uuid}:reviewed:{$personalRequest->status}",
        );

        if ($approved && $personalRequest->kind === 'technical') {
            $this->notifications->notify(
                $organization,
                $this->notifications->usersWithPermission($organization, 'technical_requests.manage', ['developer', 'super-admin']),
                $user,
                'technical_request.approved',
                '有新的技术需求可以接受',
                "{$personalRequest->reference_no}：{$personalRequest->title}",
                route('technical-requests.index', ['search' => $personalRequest->reference_no], false),
                $personalRequest,
                "personal-request:{$personalRequest->uuid}:available",
            );
        }

        if ($approved && $personalRequest->kind === 'expense_request') {
            $this->notifyFinancePaymentRequired($organization, $user, $personalRequest);
        }

        if ($approved && $personalRequest->kind === 'expense') {
            $this->notifyFinanceReimbursementRequired($organization, $user, $personalRequest);
        }

        return back()->with('success', $approved ? '申请已通过。' : '申请已驳回。');
    }

    public function acceptTechnical(Request $request, PersonalRequest $personalRequest, CurrentOrganization $context): RedirectResponse
    {
        $organization = $context->require();
        $user = $request->user();
        abort_unless($user && $personalRequest->kind === 'technical' && (int) $personalRequest->organization_id === (int) $organization->id, 404);
        abort_unless($this->hasRole($user, $organization, ['developer', 'super-admin']) && $this->permissions->allows($user, $organization, 'technical_requests.manage'), 403);
        $accepted = DB::transaction(function () use ($personalRequest, $user): bool {
            $item = PersonalRequest::query()->whereKey($personalRequest->id)->lockForUpdate()->firstOrFail();
            if ($item->assignee_id !== null) {
                return (int) $item->assignee_id === (int) $user->id;
            }
            if ($item->status !== 'approved') {
                return false;
            }
            $item->forceFill(['assignee_id' => $user->id, 'status' => 'assigned', 'accepted_at' => now()])->save();
            $item->progressLogs()->create(['user_id' => $user->id, 'action' => 'accepted']);
            $this->audit($item, $user, 'technical_request_accepted');

            return true;
        });

        if ($accepted) {
            $this->notifications->notify(
                $organization,
                [$personalRequest->submitter_id],
                $user,
                'technical_request.accepted',
                '技术需求已被接受',
                "{$user->name} 已接受 {$personalRequest->reference_no}：{$personalRequest->title}",
                route('technical-requests.index', ['search' => $personalRequest->reference_no], false),
                $personalRequest,
                "personal-request:{$personalRequest->uuid}:accepted",
            );
        }

        return $accepted ? back()->with('success', '技术需求已接受。') : back()->withErrors(['accept' => '该需求当前无法接受。']);
    }

    public function progressTechnical(Request $request, PersonalRequest $personalRequest, CurrentOrganization $context): RedirectResponse
    {
        $organization = $context->require();
        $user = $request->user();
        abort_unless($user && $personalRequest->kind === 'technical' && (int) $personalRequest->organization_id === (int) $organization->id, 404);
        abort_unless((int) $personalRequest->assignee_id === (int) $user->id && ! in_array($personalRequest->status, ['completed', 'rejected'], true), 403);
        $validated = $request->validate(['action' => ['required', Rule::in(['progress', 'complete'])], 'note' => ['nullable', 'string', 'max:3000', 'required_if:action,progress']]);
        $complete = $validated['action'] === 'complete';
        $note = trim((string) ($validated['note'] ?? ''));
        $progressLogId = DB::transaction(function () use ($complete, $note, $personalRequest, $user): int {
            $personalRequest->forceFill(['status' => $complete ? 'completed' : 'in_progress', 'completed_at' => $complete ? now() : null])->save();
            $progressLog = $personalRequest->progressLogs()->create(['user_id' => $user->id, 'action' => $complete ? 'completed' : 'progress', 'content' => $note !== '' ? $note : null]);
            $this->audit($personalRequest, $user, $complete ? 'technical_request_completed' : 'technical_request_progressed');

            return (int) $progressLog->id;
        });

        $this->notifications->notify(
            $organization,
            [$personalRequest->submitter_id],
            $user,
            $complete ? 'technical_request.completed' : 'technical_request.progressed',
            $complete ? '技术需求已完成' : '技术需求有新进展',
            "{$personalRequest->reference_no}：{$personalRequest->title}",
            route('technical-requests.index', ['search' => $personalRequest->reference_no], false),
            $personalRequest,
            "personal-request:{$personalRequest->uuid}:progress:{$progressLogId}",
        );

        return back()->with('success', $complete ? '技术需求已完成。' : '进展已提交。');
    }

    public function attachment(Request $request, PersonalRequest $personalRequest, PersonalRequestAttachment $attachment, CurrentOrganization $context): StreamedResponse
    {
        $organization = $context->require();
        $user = $request->user();
        abort_unless($user && (int) $personalRequest->organization_id === (int) $organization->id && (int) $attachment->personal_request_id === (int) $personalRequest->id, 404);
        $viewAllPermission = match ($personalRequest->kind) {
            'expense' => 'expense_claims.view_all',
            'expense_request' => 'expense_requests.view_all',
            default => 'technical_requests.view_all',
        };
        $canSee = (int) $personalRequest->submitter_id === (int) $user->id
            || (int) $personalRequest->assignee_id === (int) $user->id
            || $this->permissions->allows($user, $organization, 'request_approvals.view')
            || ($personalRequest->kind === 'expense' && $this->permissions->allows($user, $organization, 'finance.view'))
            || $this->permissions->allows($user, $organization, $viewAllPermission);
        abort_unless($canSee && Storage::disk($attachment->disk)->exists($attachment->path), 404);

        return Storage::disk($attachment->disk)->response($attachment->path, $attachment->uuid.'.'.(pathinfo($attachment->path, PATHINFO_EXTENSION) ?: 'img'), [
            'Content-Type' => $attachment->mime_type, 'Content-Disposition' => 'inline', 'Cache-Control' => 'private, max-age=3600', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function createWithAttachments(Organization $organization, User $user, array $attributes, array $images): PersonalRequest
    {
        $paths = [];
        try {
            return DB::transaction(function () use ($attributes, $images, $organization, &$paths, $user): PersonalRequest {
                $item = PersonalRequest::query()->create(['organization_id' => $organization->id, 'submitter_id' => $user->id, ...$attributes]);
                foreach ($images as $index => $image) {
                    $path = $image->storeAs("personal-requests/{$organization->id}/{$item->uuid}", Str::uuid().'.'.($image->guessExtension() ?: 'img'), 'local');
                    abort_unless(is_string($path), 500, '附件保存失败。');
                    $paths[] = $path;
                    $item->attachments()->create(['uploaded_by' => $user->id, 'disk' => 'local', 'path' => $path, 'original_name' => $image->getClientOriginalName(), 'mime_type' => (string) $image->getMimeType(), 'size' => (int) $image->getSize(), 'sort_order' => $index]);
                }

                return $item;
            });
        } catch (\Throwable $exception) {
            foreach ($paths as $path) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }
    }

    private function serialize(PersonalRequest $item, User $user, bool $canManage): array
    {
        return [
            'uuid' => $item->uuid, 'reference_no' => $item->reference_no, 'kind' => $item->kind,
            'title' => $item->title, 'description' => $item->description, 'category' => $item->category,
            'priority' => $item->priority, 'desired_date' => $item->desired_date?->toDateString(),
            'amount' => $item->amount, 'currency' => $item->currency, 'expense_date' => $item->expense_date?->toDateString(),
            'software_url' => $item->software_url,
            'software_account' => $item->software_account,
            'software_password_set' => filled($item->software_password),
            'software_payment_method' => $item->software_payment_method,
            'renewal_mode' => $item->renewal_mode,
            'billing_cycle' => $item->billing_cycle,
            'renewal_status' => $item->renewal_status,
            'payment_status' => $item->payment_status,
            'payer' => $item->payer?->name,
            'paid_on' => $item->paid_on?->toDateString(),
            'next_renewal_on' => $item->next_renewal_on?->toDateString(),
            'cancelled_on' => $item->cancelled_on?->toDateString(),
            'payment_reference' => $item->payment_reference,
            'status' => $item->status, 'approval_required' => $item->approval_required,
            'submitter' => $item->submitter?->name, 'assignee' => $item->assignee?->name,
            'reviewer' => $item->reviewer?->name, 'review_note' => $item->review_note,
            'created_at' => $item->created_at?->format('Y-m-d H:i'), 'reviewed_at' => $item->reviewed_at?->format('Y-m-d H:i'), 'completed_at' => $item->completed_at?->format('Y-m-d H:i'),
            'can_accept' => $canManage && $item->status === 'approved' && $item->assignee_id === null,
            'can_process' => $canManage && (int) $item->assignee_id === (int) $user->id && ! in_array($item->status, ['completed', 'rejected'], true),
            'can_cancel_renewal' => $item->kind === 'expense_request'
                && $item->category === 'software'
                && $item->status === 'approved'
                && $item->payment_status === 'paid'
                && $item->renewal_status === 'active',
            'attachments' => $item->attachments->map(fn (PersonalRequestAttachment $attachment) => ['uuid' => $attachment->uuid, 'name' => $attachment->original_name, 'url' => route('personal-requests.attachments.show', [$item, $attachment], false)])->values()->all(),
            'progress_logs' => $item->relationLoaded('progressLogs') ? $item->progressLogs->map(fn ($log) => ['uuid' => $log->uuid, 'action' => $log->action, 'content' => $log->content, 'user' => $log->user?->name, 'created_at' => $log->created_at?->format('Y-m-d H:i')])->values()->all() : [],
        ];
    }

    private function hasRole(User $user, Organization $organization, array $slugs): bool
    {
        return $user->isSuperAdmin() || $user->roles()->where('user_roles.organization_id', $organization->id)->whereIn('roles.slug', $slugs)->exists();
    }

    private function notifyApprovers(Organization $organization, User $user, PersonalRequest $item, string $title): void
    {
        $this->notifications->notify(
            $organization,
            $this->notifications->usersWithPermission($organization, 'request_approvals.manage', ['super-admin']),
            $user,
            "{$item->kind}.submitted",
            $title,
            "{$user->name} 提交了 {$item->reference_no}：{$item->title}",
            route('request-approvals.index', [], false),
            $item,
            "personal-request:{$item->uuid}:submitted",
        );
    }

    private function notifyFinancePaymentRequired(Organization $organization, User $user, PersonalRequest $item): void
    {
        $this->notifications->notify(
            $organization,
            $this->notifications->usersWithPermission($organization, 'finance.manage'),
            $user,
            'expense_request.payment_required',
            '有新的费用申请待付款',
            "{$item->reference_no}：{$item->title}",
            route('finance.renewals', [], false),
            $item,
            "personal-request:{$item->uuid}:payment-required",
        );
    }

    private function notifyFinanceReimbursementRequired(Organization $organization, User $user, PersonalRequest $item): void
    {
        $this->notifications->notify(
            $organization,
            $this->notifications->usersWithPermission($organization, 'finance.manage'),
            $user,
            'expense.reimbursement_required',
            '有新的报销申请待打款',
            "{$item->reference_no}：{$item->title}",
            route('finance.reimbursements', [], false),
            $item,
            "personal-request:{$item->uuid}:reimbursement-required",
        );
    }

    private function personalRequestUrl(PersonalRequest $item): string
    {
        return match ($item->kind) {
            'technical' => route('technical-requests.index', ['search' => $item->reference_no], false),
            'expense_request' => route('expense-requests.index', [], false),
            default => route('expense-claims.index', [], false),
        };
    }

    /** @param Collection<int, PersonalRequest> $rows */
    private function technicalSummary(Collection $rows, string $timezone): array
    {
        $today = CarbonImmutable::today($timezone);
        $active = $rows->whereNotIn('status', ['completed', 'rejected']);
        $completed = $rows->where('status', 'completed');
        $overdue = $active
            ->filter(fn (PersonalRequest $item) => $item->desired_date?->lt($today) === true)
            ->count();
        $onTime = $completed
            ->filter(fn (PersonalRequest $item) => $this->wasTechnicalRequestCompletedOnTime($item, $timezone))
            ->count();
        $turnaround = $completed
            ->filter(fn (PersonalRequest $item) => $item->completed_at !== null)
            ->map(fn (PersonalRequest $item): float => $this->technicalTurnaroundDays($item));
        $developerPerformance = $rows
            ->filter(fn (PersonalRequest $item) => $item->assignee_id !== null)
            ->groupBy('assignee_id')
            ->map(function (Collection $items) use ($today, $timezone): array {
                $completedItems = $items->where('status', 'completed');
                $onTimeCount = $completedItems
                    ->filter(fn (PersonalRequest $item) => $this->wasTechnicalRequestCompletedOnTime($item, $timezone))
                    ->count();
                $completedTurnaround = $completedItems
                    ->filter(fn (PersonalRequest $item) => $item->completed_at !== null)
                    ->map(fn (PersonalRequest $item): float => $this->technicalTurnaroundDays($item));
                $overdueCount = $items
                    ->whereNotIn('status', ['completed', 'rejected'])
                    ->filter(fn (PersonalRequest $item) => $item->desired_date?->lt($today) === true)
                    ->count();

                return [
                    'developer_id' => (int) $items->first()->assignee_id,
                    'developer' => $items->first()->assignee?->name ?? '已离职开发人员',
                    'total' => $items->count(),
                    'in_progress' => $items->whereIn('status', ['assigned', 'in_progress'])->count(),
                    'completed' => $completedItems->count(),
                    'overdue' => $overdueCount,
                    'on_time_rate' => $completedItems->count()
                        ? round($onTimeCount / $completedItems->count() * 100, 1)
                        : null,
                    'average_turnaround_days' => $completedTurnaround->isNotEmpty()
                        ? round((float) $completedTurnaround->average(), 1)
                        : null,
                ];
            })
            ->sortBy([
                ['completed', 'desc'],
                ['on_time_rate', 'desc'],
                ['developer', 'asc'],
            ])
            ->values()
            ->all();

        return [
            'total' => $rows->count(),
            'pending_approval' => $rows->where('status', 'pending_approval')->count(),
            'unassigned' => $rows->where('status', 'approved')->count(),
            'in_progress' => $rows->whereIn('status', ['assigned', 'in_progress'])->count(),
            'completed' => $completed->count(),
            'overdue' => $overdue,
            'on_time_rate' => $completed->count() ? round($onTime / $completed->count() * 100, 1) : null,
            'average_turnaround_days' => $turnaround->isNotEmpty() ? round((float) $turnaround->average(), 1) : null,
            'status_counts' => collect(self::TECH_STATUSES)
                ->mapWithKeys(fn (string $status) => [$status => $rows->where('status', $status)->count()])
                ->all(),
            'developer_performance' => $developerPerformance,
        ];
    }

    private function wasTechnicalRequestCompletedOnTime(PersonalRequest $item, string $timezone): bool
    {
        return $item->completed_at !== null
            && $item->desired_date !== null
            && $item->completed_at->timezone($timezone)->toDateString() <= $item->desired_date->toDateString();
    }

    private function technicalTurnaroundDays(PersonalRequest $item): float
    {
        if ($item->completed_at === null) {
            return 0.0;
        }

        $startedAt = $item->accepted_at
            ?? $item->progressLogs->firstWhere('action', 'accepted')?->created_at
            ?? $item->reviewed_at
            ?? $item->created_at;
        $hours = $startedAt->diffInHours($item->completed_at, false);

        return max(0, $hours / 24);
    }

    private function timezone(User $user): string
    {
        $timezone = filled($user->timezone) ? (string) $user->timezone : (string) config('app.timezone', 'UTC');

        return in_array($timezone, timezone_identifiers_list(\DateTimeZone::ALL_WITH_BC), true) ? $timezone : 'UTC';
    }

    private function audit(PersonalRequest $item, User $user, string $action): void
    {
        AuditLog::query()->create(['organization_id' => $item->organization_id, 'store_id' => null, 'user_id' => $user->id, 'action' => $action, 'subject_type' => PersonalRequest::class, 'subject_id' => $item->id, 'metadata' => ['scope' => 'personal', 'reference_no' => $item->reference_no]]);
    }
}
