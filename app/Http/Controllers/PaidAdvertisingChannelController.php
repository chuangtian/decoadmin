<?php

namespace App\Http\Controllers;

use App\Services\Advertising\AdvertisingChannelManualSyncService;
use App\Services\Advertising\AdvertisingChannelStatusService;
use App\Services\Advertising\GoogleAdsOverviewService;
use App\Services\Advertising\GoogleAdsPerformanceTableService;
use App\Services\Advertising\GoogleAdsWeeklyReportService;
use App\Services\Advertising\TikTokAdsOverviewService;
use App\Support\CurrentStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PaidAdvertisingChannelController extends Controller
{
    public function index(
        Request $request,
        string $channel,
        CurrentStore $currentStore,
        AdvertisingChannelStatusService $status,
        GoogleAdsOverviewService $googleOverview,
        TikTokAdsOverviewService $tiktokOverview,
    ): Response {
        $store = $currentStore->require();
        $payload = $status->forStore($store, $channel);

        return Inertia::render($channel === 'tiktok' ? 'PaidAdvertising/TikTok' : 'PaidAdvertising/Channel', [
            'store' => ['id' => $store->getKey(), 'name' => $store->name],
            'channelStatus' => $payload,
            'googleOverview' => $channel === 'google'
                ? $googleOverview->forStore($store, $request->only(['account', 'date_from', 'date_to']))
                : null,
            'tiktokOverview' => $channel === 'tiktok'
                ? $tiktokOverview->forStore($store, $request->only(['account', 'date_from', 'date_to']))
                : null,
            'canSync' => $request->user()?->hasPermission('sync.run', $store->organization, $store) ?? false,
        ]);
    }

    public function status(string $channel, CurrentStore $currentStore, AdvertisingChannelStatusService $status): JsonResponse
    {
        return response()->json(['data' => $status->forStore($currentStore->require(), $channel)])
            ->withHeaders(['Cache-Control' => 'no-store, private', 'Pragma' => 'no-cache']);
    }

    public function data(
        Request $request,
        string $channel,
        CurrentStore $currentStore,
        GoogleAdsOverviewService $googleOverview,
        GoogleAdsPerformanceTableService $googlePerformance,
        GoogleAdsWeeklyReportService $googleWeeklyReport,
        TikTokAdsOverviewService $tiktokOverview,
    ): JsonResponse {
        abort_unless(in_array($channel, ['google', 'tiktok'], true), 404);
        $filters = $request->validate([
            'account' => ['sometimes', 'string', 'max:128', 'regex:/^[0-9]+$/'],
            'date_from' => ['sometimes', 'required_with:date_to', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'required_with:date_from', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'view' => ['sometimes', 'string', 'in:search-terms,keywords,weekly'],
            'week' => ['sometimes', 'date_format:Y-m-d'],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'sort' => ['sometimes', 'string', 'max:40'],
            'direction' => ['sometimes', 'string', 'in:asc,desc'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:10', 'max:100'],
        ]);

        if ($channel === 'google' && ($filters['view'] ?? null) === 'weekly') {
            return response()->json(['data' => $googleWeeklyReport->forStore($currentStore->require(), $filters)])
                ->withHeaders(['Cache-Control' => 'no-store, private', 'Pragma' => 'no-cache']);
        }

        if ($channel === 'google' && isset($filters['view'])) {
            return response()->json(['data' => $googlePerformance->forStore($currentStore->require(), $filters)])
                ->withHeaders(['Cache-Control' => 'no-store, private', 'Pragma' => 'no-cache']);
        }

        $overview = $channel === 'tiktok' ? $tiktokOverview : $googleOverview;

        return response()->json(['data' => $overview->forStore($currentStore->require(), $filters)])
            ->withHeaders(['Cache-Control' => 'no-store, private', 'Pragma' => 'no-cache']);
    }

    public function sync(
        string $channel,
        CurrentStore $currentStore,
        AdvertisingChannelManualSyncService $manualSync,
        AdvertisingChannelStatusService $status,
    ): JsonResponse {
        abort_unless(in_array($channel, ['google', 'tiktok'], true), 404);
        $store = $currentStore->require();
        $label = $channel === 'tiktok' ? 'TikTok Ads' : 'Google Ads';
        $provider = $channel === 'tiktok' ? 'tiktok_ads' : 'google_ads';
        $result = $manualSync->queue($store, $channel);
        if (! $result['configured']) {
            return response()->json([
                'message' => "当前店铺尚未配置 {$label} 凭证。",
                'settings_url' => route('store-settings.credentials', ['provider' => $provider]),
            ], 422);
        }

        return response()->json([
            'message' => $result['already_running'] ? "{$label} 数据正在同步中。" : "{$label} 增量同步任务已提交。",
            'sync' => $result,
            'status' => $status->forStore($store, $channel),
        ], 202);
    }
}
