<?php

namespace App\Http\Controllers;

use App\Services\MetaAds\MetaAdsCreativeLibraryService;
use App\Services\MetaAds\MetaAdsManualSyncService;
use App\Services\MetaAds\MetaAdsOverviewService;
use App\Services\MetaAds\MetaAdsStatusService;
use App\Support\CurrentStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PaidAdvertisingFacebookController extends Controller
{
    public function index(
        Request $request,
        CurrentStore $currentStore,
        MetaAdsStatusService $status,
        MetaAdsOverviewService $overview,
    ): Response {
        $store = $currentStore->require();

        return Inertia::render('PaidAdvertising/Facebook', [
            'store' => [
                'id' => $store->getKey(),
                'name' => $store->name,
            ],
            'metaAdsStatus' => $status->forStore($store),
            'overview' => $overview->forStore($store, $request->only([
                'account', 'date_from', 'date_to', 'compare',
            ])),
            'canSync' => $request->user()?->hasPermission('sync.run', $store->organization, $store) ?? false,
        ]);
    }

    public function status(CurrentStore $currentStore, MetaAdsStatusService $status): JsonResponse
    {
        return response()->json([
            'data' => $status->forStore($currentStore->require()),
        ])->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
        ]);
    }

    public function data(Request $request, CurrentStore $currentStore, MetaAdsOverviewService $overview): JsonResponse
    {
        return response()->json([
            'data' => $overview->forStore($currentStore->require(), $request->only([
                'account', 'date_from', 'date_to', 'compare',
            ])),
        ])->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
        ]);
    }

    public function creatives(
        Request $request,
        CurrentStore $currentStore,
        MetaAdsCreativeLibraryService $creativeLibrary,
    ): JsonResponse {
        return response()->json([
            'data' => $creativeLibrary->forStore($currentStore->require(), $request->only([
                'account', 'date_from', 'date_to', 'page',
            ])),
        ])->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
        ]);
    }

    public function copies(
        Request $request,
        CurrentStore $currentStore,
        MetaAdsCreativeLibraryService $creativeLibrary,
    ): JsonResponse {
        return response()->json([
            'data' => $creativeLibrary->forStore($currentStore->require(), [
                ...$request->only(['account', 'date_from', 'date_to', 'page']),
                'content' => 'copy',
            ]),
        ])->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
        ]);
    }

    public function sync(
        Request $request,
        CurrentStore $currentStore,
        MetaAdsManualSyncService $manualSync,
        MetaAdsStatusService $status,
    ): JsonResponse {
        $store = $currentStore->require();
        $filters = $request->validate([
            'account' => ['sometimes', 'string', 'max:64', 'regex:/^(all|act_[A-Za-z0-9_-]+|[0-9]+)$/'],
            'date_from' => ['sometimes', 'required_with:date_to', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'required_with:date_from', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);
        $result = $manualSync->queue($store, $filters);

        if (! $result['configured']) {
            return response()->json([
                'message' => '当前店铺尚未配置 Meta Access Token。',
                'settings_url' => route('store-settings.credentials', ['provider' => 'meta_ads']),
            ], 422);
        }

        return response()->json([
            'message' => $result['already_running'] ? 'Meta Ads 数据正在同步中。' : 'Meta Ads 同步任务已提交。',
            'sync' => $result,
            'status' => $status->forStore($store),
        ], 202);
    }
}
