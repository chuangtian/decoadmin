<?php

namespace App\Http\Controllers;

use App\Http\Requests\ClearPaidAdvertisingOverallGoalRequest;
use App\Http\Requests\ConfigurePaidAdvertisingOverallGoalRequest;
use App\Http\Requests\DestroyPaidAdvertisingGoalBoardRequest;
use App\Http\Requests\RefreshPaidAdvertisingGoalRequest;
use App\Http\Requests\StorePaidAdvertisingGoalBoardRequest;
use App\Models\PaidAdvertisingGoalBoard;
use App\Services\PaidAdvertisingGoalRefreshService;
use App\Services\PaidAdvertisingGoalService;
use App\Support\CurrentOrganization;
use App\Support\CurrentStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PaidAdvertisingGoalController extends Controller
{
    public function index(
        Request $request,
        CurrentOrganization $currentOrganization,
        CurrentStore $currentStore,
        PaidAdvertisingGoalService $goals,
    ): Response {
        $organization = $currentOrganization->require();
        $store = $currentStore->require();

        return Inertia::render('PaidAdvertising/Goals', [
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
            ],
            'goalPage' => $goals->page(
                $organization,
                $store,
                $request->string('tab')->toString(),
                $request->only(['period_mode', 'month', 'date_from', 'date_to']),
            ),
            'canManage' => $request->user()->hasPermission('store.update', $organization, $store),
            'canRefresh' => $request->user()->hasPermission('sync.run', $organization, $store),
        ]);
    }

    public function refresh(
        RefreshPaidAdvertisingGoalRequest $request,
        CurrentOrganization $currentOrganization,
        CurrentStore $currentStore,
        PaidAdvertisingGoalRefreshService $refresh,
    ): JsonResponse {
        $organization = $currentOrganization->require();
        $store = $currentStore->require();

        abort_unless((int) $store->organization_id === (int) $organization->id, 404);
        abort_unless($request->user()->hasPermission('sync.run', $organization, $store), 403);

        $run = $refresh->enqueue(
            $organization,
            $store,
            $request->user(),
            PaidAdvertisingGoalRefreshService::SOURCE_MANUAL_REFRESH,
            $request->validated('tab'),
        );

        return response()->json([
            'queued' => true,
            'sync' => $refresh->present($run),
        ], 202);
    }

    public function refreshStatus(
        Request $request,
        string $syncRun,
        CurrentOrganization $currentOrganization,
        CurrentStore $currentStore,
        PaidAdvertisingGoalRefreshService $refresh,
    ): JsonResponse {
        $organization = $currentOrganization->require();
        $store = $currentStore->require();

        abort_unless((int) $store->organization_id === (int) $organization->id, 404);
        abort_unless($request->user()->hasPermission('sync.run', $organization, $store), 403);

        return response()->json([
            'sync' => $refresh->present($refresh->findRun($organization, $store, $syncRun)),
        ]);
    }

    public function configureOverall(
        ConfigurePaidAdvertisingOverallGoalRequest $request,
        CurrentOrganization $currentOrganization,
        CurrentStore $currentStore,
        PaidAdvertisingGoalService $goals,
        PaidAdvertisingGoalRefreshService $refresh,
    ): RedirectResponse {
        $organization = $currentOrganization->require();
        $store = $currentStore->require();
        $actor = $request->user();
        $goals->configureOverall(
            $organization,
            $store,
            $actor,
            $request->validated(),
        );

        $refresh->enqueue(
            $organization,
            $store,
            $actor,
            PaidAdvertisingGoalRefreshService::SOURCE_OVERALL_CONFIGURED,
            PaidAdvertisingGoalRefreshService::TARGET_OVERALL,
        );

        return to_route('paid-advertising.goals', $this->goalPageRedirectQuery($request))
            ->with('success', 'App Token 已保存，首次数据同步已在后台启动。');
    }

    public function clearOverall(
        ClearPaidAdvertisingOverallGoalRequest $request,
        CurrentOrganization $currentOrganization,
        CurrentStore $currentStore,
        PaidAdvertisingGoalService $goals,
    ): RedirectResponse {
        $goals->clearOverall(
            $currentOrganization->require(),
            $currentStore->require(),
            $request->user(),
        );

        return to_route('paid-advertising.goals')
            ->with('success', '总目标数据和飞书配置已清除，请重新配置飞书表格。');
    }

    public function store(
        StorePaidAdvertisingGoalBoardRequest $request,
        CurrentOrganization $currentOrganization,
        CurrentStore $currentStore,
        PaidAdvertisingGoalService $goals,
        PaidAdvertisingGoalRefreshService $refresh,
    ): RedirectResponse {
        $organization = $currentOrganization->require();
        $store = $currentStore->require();
        $actor = $request->user();
        $board = $goals->create(
            $organization,
            $store,
            $actor,
            $request->validated(),
        );
        $refresh->enqueue(
            $organization,
            $store,
            $actor,
            PaidAdvertisingGoalRefreshService::SOURCE_GOAL_BOARD_CREATED,
            $goals->tabKey($board),
        );

        return to_route('paid-advertising.goals', $this->goalPageRedirectQuery(
            $request,
            $goals->tabKey($board),
        ))
            ->with('success', '目标页签已添加。');
    }

    public function destroy(
        DestroyPaidAdvertisingGoalBoardRequest $request,
        PaidAdvertisingGoalBoard $goalBoard,
        CurrentOrganization $currentOrganization,
        CurrentStore $currentStore,
        PaidAdvertisingGoalService $goals,
    ): RedirectResponse {
        $goals->delete(
            $currentOrganization->require(),
            $currentStore->require(),
            $request->user(),
            $goalBoard,
        );

        return to_route('paid-advertising.goals')
            ->with('success', '目标页签、飞书配置和同步数据已删除。');
    }

    /** @return array<string, string> */
    private function goalPageRedirectQuery(Request $request, ?string $tab = null): array
    {
        $query = $tab === null ? [] : ['tab' => $tab];
        $mode = $request->string('period_mode')->toString();

        if ($mode === 'month') {
            $query['period_mode'] = 'month';
            $query['month'] = $request->string('month')->toString();

            return $query;
        }

        if ($mode === 'range') {
            $query['period_mode'] = 'range';
            $query['date_from'] = $request->string('date_from')->toString();
            $query['date_to'] = $request->string('date_to')->toString();
        }

        return $query;
    }
}
