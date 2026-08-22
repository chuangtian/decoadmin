<?php

namespace App\Http\Controllers;

use App\Services\CampaignThemeOverviewService;
use App\Services\CampaignThemeRefreshService;
use App\Services\CampaignThemeReviewService;
use App\Support\CurrentOrganization;
use App\Support\CurrentStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CampaignThemeController extends Controller
{
    public function __invoke(
        Request $request,
        CurrentOrganization $currentOrganization,
        CurrentStore $currentStore,
        CampaignThemeOverviewService $overview,
        CampaignThemeReviewService $review,
        CampaignThemeRefreshService $refresh,
    ): Response {
        $organization = $currentOrganization->require();
        $store = $currentStore->require();

        $activeTab = in_array($request->string('tab')->toString(), ['overview', 'review', 'planning', 'calendar'], true)
            ? $request->string('tab')->toString()
            : 'overview';
        $comparison = $request->query('compare', 'auto');

        return Inertia::render('CampaignThemes/Index', [
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
                'currency' => $store->currency ?: 'USD',
                'timezone' => $store->timezone ?: 'UTC',
            ],
            'activeTab' => $activeTab,
            'overview' => $overview->overview($organization, $store),
            'review' => $activeTab === 'review'
                ? $review->review(
                    $organization,
                    $store,
                    $request->integer('activity') ?: null,
                    is_numeric($comparison) ? (int) $comparison : (string) $comparison,
                )
                : null,
            'refreshStatus' => [
                ...$refresh->status($store),
                'can_run' => $request->user()->hasPermission('sync.run', $organization, $store),
            ],
        ]);
    }

    public function refresh(
        Request $request,
        CurrentOrganization $currentOrganization,
        CurrentStore $currentStore,
        CampaignThemeRefreshService $refresh,
    ): RedirectResponse {
        $organization = $currentOrganization->require();
        $store = $currentStore->require();

        abort_unless((int) $store->organization_id === (int) $organization->id, 404);
        abort_unless($request->user()->hasPermission('sync.run', $organization, $store), 403);

        $queued = $refresh->enqueue($store, $request->user());

        return back()->with(
            $queued ? 'success' : 'info',
            $queued ? '飞书活动数据更新任务已提交。' : '当前店铺已有飞书活动数据更新任务正在执行。',
        );
    }
}
