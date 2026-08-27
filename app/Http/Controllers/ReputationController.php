<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreReputationMentionRequest;
use App\Http\Requests\StoreReputationResourceRequest;
use App\Http\Requests\StoreReputationRiskRequest;
use App\Http\Requests\UpdateReputationMentionRequest;
use App\Http\Requests\UpdateReputationResourceRequest;
use App\Http\Requests\UpdateReputationRiskRequest;
use App\Http\Requests\UpsertReputationGoalsRequest;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\ReputationMention;
use App\Models\ReputationResourceRequest;
use App\Models\ReputationRisk;
use App\Models\ReputationSyncRun;
use App\Models\Store;
use App\Services\Reputation\ReputationDashboardService;
use App\Services\Reputation\ReputationSyncManager;
use App\Services\Reputation\ReputationWorkflowService;
use App\Support\CurrentOrganization;
use App\Support\CurrentStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReputationController extends Controller
{
    public function overview(
        Request $request,
        CurrentOrganization $currentOrganization,
        CurrentStore $currentStore,
        ReputationDashboardService $dashboard,
    ): Response {
        [$organization, $store] = $this->scope($currentOrganization, $currentStore);
        $filters = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'tab' => ['nullable', 'in:targets,reviews,reddit,threads'],
            'source' => ['nullable', 'in:trustpilot,website,facebook,reddit,threads,multiple'],
            'rating' => ['nullable', 'integer', 'between:1,5'],
            'status' => ['nullable', 'in:pending,done'],
            'comparison' => ['nullable', 'in:none,previous,custom'],
            'compare_date_from' => ['nullable', 'date', 'required_if:comparison,custom'],
            'compare_date_to' => ['nullable', 'date', 'required_if:comparison,custom'],
            'search' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'between:10,50'],
        ]);

        return Inertia::render('Reputation/Overview', [
            'store' => ['id' => $store->id, 'name' => $store->name, 'timezone' => $store->timezone ?: 'UTC'],
            'dashboard' => $dashboard->overview(
                $store,
                $filters,
                $request->user()?->hasPermission('alerts.manage', $organization, $store) ?? false,
            ),
            'canSync' => $request->user()?->hasPermission('sync.run', $organization, $store) ?? false,
            'canManage' => $request->user()?->hasPermission('alerts.manage', $organization, $store) ?? false,
        ]);
    }

    public function riskSync(
        Request $request,
        CurrentOrganization $currentOrganization,
        CurrentStore $currentStore,
        ReputationDashboardService $dashboard,
    ): Response {
        [$organization, $store] = $this->scope($currentOrganization, $currentStore);
        $filters = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'status' => ['nullable', 'in:pending,processing,watching,resolved,dismissed'],
            'severity' => ['nullable', 'in:low,medium,high,critical'],
            'source' => ['nullable', 'in:trustpilot,website,facebook,reddit,threads,multiple'],
        ]);

        return Inertia::render('Reputation/RiskSync', [
            'store' => ['id' => $store->id, 'name' => $store->name, 'timezone' => $store->timezone ?: 'UTC'],
            'dashboard' => $dashboard->riskSync($store, $filters),
            'canSync' => $request->user()?->hasPermission('sync.run', $organization, $store) ?? false,
            'canManage' => $request->user()?->hasPermission('alerts.manage', $organization, $store) ?? false,
        ]);
    }

    public function sync(
        Request $request,
        CurrentOrganization $currentOrganization,
        CurrentStore $currentStore,
        ReputationSyncManager $manager,
    ): RedirectResponse {
        [$organization, $store] = $this->scope($currentOrganization, $currentStore);
        abort_unless($request->user()?->hasPermission('sync.run', $organization, $store), 403);

        $run = $manager->queue($store, 'manual', $request->user());
        AuditLog::query()->create([
            'organization_id' => (int) $organization->id,
            'store_id' => (int) $store->id,
            'user_id' => (int) $request->user()->id,
            'action' => 'reputation_sync_requested',
            'subject_type' => ReputationSyncRun::class,
            'subject_id' => (int) $run->id,
            'metadata' => ['scope' => 'store', 'source' => 'manual'],
        ]);

        return back()->with('success', '舆情数据已进入后台同步队列。');
    }

    public function syncStatus(
        CurrentOrganization $currentOrganization,
        CurrentStore $currentStore,
        ReputationDashboardService $dashboard,
        string $syncRun,
    ): JsonResponse {
        [, $store] = $this->scope($currentOrganization, $currentStore);

        return response()->json(['data' => $dashboard->syncStatus($store, $syncRun)]);
    }

    public function storeRisk(
        StoreReputationRiskRequest $request,
        CurrentOrganization $currentOrganization,
        CurrentStore $currentStore,
        ReputationWorkflowService $workflow,
    ): RedirectResponse {
        [, $store] = $this->scope($currentOrganization, $currentStore);
        $workflow->createRisk($store, $request->user(), $request->validated());

        return back()->with('success', '风险项已添加。');
    }

    public function storeMention(
        StoreReputationMentionRequest $request,
        CurrentOrganization $currentOrganization,
        CurrentStore $currentStore,
        ReputationWorkflowService $workflow,
    ): RedirectResponse {
        [, $store] = $this->scope($currentOrganization, $currentStore);
        $workflow->createMention($store, $request->user(), $request->validated());

        return back()->with('success', '评价已录入当前项目数据库。');
    }

    public function updateMention(
        UpdateReputationMentionRequest $request,
        ReputationMention $reputationMention,
        CurrentOrganization $currentOrganization,
        CurrentStore $currentStore,
        ReputationWorkflowService $workflow,
    ): RedirectResponse {
        [, $store] = $this->scope($currentOrganization, $currentStore);
        $workflow->updateMention($store, $request->user(), $reputationMention, $request->validated());

        return back()->with('success', '评价跟进信息已更新。');
    }

    public function updateRisk(
        UpdateReputationRiskRequest $request,
        ReputationRisk $reputationRisk,
        CurrentOrganization $currentOrganization,
        CurrentStore $currentStore,
        ReputationWorkflowService $workflow,
    ): RedirectResponse {
        [, $store] = $this->scope($currentOrganization, $currentStore);
        $workflow->updateRisk($store, $request->user(), $reputationRisk, $request->validated());

        return back()->with('success', '风险项已更新。');
    }

    public function storeResource(
        StoreReputationResourceRequest $request,
        CurrentOrganization $currentOrganization,
        CurrentStore $currentStore,
        ReputationWorkflowService $workflow,
    ): RedirectResponse {
        [, $store] = $this->scope($currentOrganization, $currentStore);
        $workflow->createResource($store, $request->user(), $request->validated());

        return back()->with('success', '资源支持需求已添加。');
    }

    public function updateResource(
        UpdateReputationResourceRequest $request,
        ReputationResourceRequest $reputationResource,
        CurrentOrganization $currentOrganization,
        CurrentStore $currentStore,
        ReputationWorkflowService $workflow,
    ): RedirectResponse {
        [, $store] = $this->scope($currentOrganization, $currentStore);
        $workflow->updateResource($store, $request->user(), $reputationResource, $request->validated());

        return back()->with('success', '资源支持需求已更新。');
    }

    public function upsertGoals(
        UpsertReputationGoalsRequest $request,
        CurrentOrganization $currentOrganization,
        CurrentStore $currentStore,
        ReputationWorkflowService $workflow,
    ): RedirectResponse {
        [, $store] = $this->scope($currentOrganization, $currentStore);
        $validated = $request->validated();
        $workflow->upsertGoals($store, $request->user(), $validated['month'], $validated['targets']);

        return back()->with('success', '本月舆情目标已保存。');
    }

    /** @return array{0: Organization, 1: Store} */
    private function scope(CurrentOrganization $currentOrganization, CurrentStore $currentStore): array
    {
        $organization = $currentOrganization->require();
        $store = $currentStore->require();
        abort_unless((int) $store->organization_id === (int) $organization->id, 404);

        return [$organization, $store];
    }
}
