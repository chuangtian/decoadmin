<?php

namespace App\Services;

use App\Models\CampaignActivity;
use App\Models\Organization;
use App\Models\Store;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

class CampaignThemeOverviewService
{
    public function __construct(
        private CampaignActivityClassificationService $classification,
    ) {}

    /** @return array<string, mixed> */
    public function overview(Organization $organization, Store $store): array
    {
        if ((int) $store->organization_id !== (int) $organization->getKey()) {
            throw new InvalidArgumentException('The store does not belong to the requested organization.');
        }

        $aggregate = CampaignActivity::query()
            ->forOrganization($organization)
            ->forStore($store)
            ->where('sales_amount', '>', 0)
            ->selectRaw('COUNT(*) as completed_campaigns')
            ->selectRaw('COALESCE(SUM(sales_amount), 0) as total_gmv')
            ->selectRaw('COALESCE(SUM(ad_spend), 0) as total_ad_spend')
            ->selectRaw('COALESCE(SUM(order_count), 0) as total_orders')
            ->selectRaw('COALESCE(SUM(conversion_rate), 0) as conversion_rate_sum')
            ->selectRaw('COUNT(conversion_rate) as conversion_rate_count')
            ->firstOrFail();

        $completedCampaigns = (int) $aggregate->completed_campaigns;
        $totalGmv = (float) $aggregate->total_gmv;
        $totalAdSpend = (float) $aggregate->total_ad_spend;
        $conversionRateSum = (float) $aggregate->conversion_rate_sum;
        $conversionRateCount = (int) $aggregate->conversion_rate_count;
        $today = CarbonImmutable::now($store->timezone ?: 'UTC')->toDateString();
        $timeline = CampaignActivity::query()
            ->forOrganization($organization)
            ->forStore($store)
            ->whereNotNull('starts_on')
            ->select(['id', 'campaign_id', 'campaign_name', 'starts_on', 'ends_on', 'sales_amount', 'roi'])
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(function (CampaignActivity $activity) use ($today): array {
                $startsOn = $activity->starts_on?->toDateString();
                $endsOn = $activity->ends_on?->toDateString();

                return [
                    'id' => (int) $activity->id,
                    'name' => $activity->campaign_name ?: $activity->campaign_id ?: '未命名活动',
                    'starts_on' => $startsOn,
                    'ends_on' => $endsOn,
                    'month' => $startsOn ? substr($startsOn, 0, 7) : null,
                    'status' => $this->classification->status($startsOn, $endsOn, $today),
                    'duration_days' => $startsOn && $endsOn
                        ? (int) CarbonImmutable::parse($startsOn)->diffInDays(CarbonImmutable::parse($endsOn)) + 1
                        : null,
                    'gmv' => $activity->sales_amount !== null ? round((float) $activity->sales_amount, 2) : 0.0,
                    'roi' => $activity->roi !== null ? round((float) $activity->roi, 2) : null,
                ];
            })
            ->values()
            ->all();

        $completedActivities = CampaignActivity::query()
            ->forOrganization($organization)
            ->forStore($store)
            ->where('sales_amount', '>', 0)
            ->select([
                'id',
                'campaign_id',
                'campaign_name',
                'starts_on',
                'ends_on',
                'sales_amount',
                'ad_spend',
                'roi',
                'store_visits',
                'conversion_rate',
                'daily_average_sales',
                'daily_average_store_visits',
            ])
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        $trends = $completedActivities
            ->filter(fn (CampaignActivity $activity): bool => $activity->starts_on !== null)
            ->take(50)
            ->map(fn (CampaignActivity $activity): array => [
                'id' => (int) $activity->id,
                'name' => $activity->campaign_name ?: $activity->campaign_id ?: '未命名活动',
                'starts_on' => $activity->starts_on?->toDateString(),
                'roi' => $activity->roi !== null ? round((float) $activity->roi, 2) : null,
                'conversion_rate_percent' => $activity->conversion_rate !== null
                    ? round((float) $activity->conversion_rate * 100, 3)
                    : null,
                'daily_average_sales' => $activity->daily_average_sales !== null
                    ? round((float) $activity->daily_average_sales, 2)
                    : null,
                'daily_average_store_visits' => $activity->daily_average_store_visits !== null
                    ? round((float) $activity->daily_average_store_visits, 2)
                    : null,
            ])
            ->values()
            ->all();

        $performance = $completedActivities
            ->map(function (CampaignActivity $activity): array {
                $roi = $activity->roi !== null ? round((float) $activity->roi, 2) : null;

                return [
                    'id' => (int) $activity->id,
                    'name' => $activity->campaign_name ?: $activity->campaign_id ?: '未命名活动',
                    'starts_on' => $activity->starts_on?->toDateString(),
                    'ends_on' => $activity->ends_on?->toDateString(),
                    'gmv' => round((float) $activity->sales_amount, 2),
                    'ad_spend' => $activity->ad_spend !== null ? round((float) $activity->ad_spend, 2) : null,
                    'roi' => $roi,
                    'conversion_rate_percent' => $activity->conversion_rate !== null
                        ? round((float) $activity->conversion_rate * 100, 3)
                        : null,
                    'store_visits' => $activity->store_visits !== null ? (int) $activity->store_visits : null,
                    'judgment' => $this->classification->judgment($roi),
                ];
            })
            ->values()
            ->all();

        return [
            'schema' => 'campaign-theme-overview-v1',
            'metrics' => [
                'total_gmv' => round($totalGmv, 2),
                'total_ad_spend' => round($totalAdSpend, 2),
                'total_orders' => (int) $aggregate->total_orders,
                'overall_roi' => $totalAdSpend > 0 ? round($totalGmv / $totalAdSpend, 2) : 0.0,
                'average_cvr_percent' => $completedCampaigns > 0
                    ? round(($conversionRateSum / $completedCampaigns) * 100, 3)
                    : 0.0,
            ],
            'coverage' => [
                'completed_campaigns' => $completedCampaigns,
                'missing_conversion_rate' => max(0, $completedCampaigns - $conversionRateCount),
            ],
            'performance_rules' => [
                'break_even_roi' => 5.0,
                'reusable_roi' => 6.0,
            ],
            'timeline' => $timeline,
            'trends' => $trends,
            'performance' => $performance,
        ];
    }
}
