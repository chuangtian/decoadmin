<?php

namespace App\Services;

use App\Models\CampaignActivity;
use App\Models\Organization;
use App\Models\Store;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

class CampaignThemePlanningService
{
    public function __construct(
        private CampaignActivityClassificationService $classification,
    ) {}

    /** @return array<string, mixed> */
    public function planning(Organization $organization, Store $store): array
    {
        if ((int) $store->organization_id !== (int) $organization->getKey()) {
            throw new InvalidArgumentException('The store does not belong to the requested organization.');
        }

        $today = CarbonImmutable::now($store->timezone ?: 'UTC')->toDateString();
        $activities = CampaignActivity::query()
            ->forOrganization($organization)
            ->forStore($store)
            ->select([
                'id',
                'campaign_id',
                'campaign_name',
                'main_title',
                'core_offer',
                'starts_on',
                'ends_on',
                'sales_amount',
                'ad_spend',
                'roi',
                'order_count',
                'campaign_summary',
            ])
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(function (CampaignActivity $activity) use ($today): array {
                $startsOn = $activity->starts_on?->toDateString();
                $endsOn = $activity->ends_on?->toDateString();
                $roi = $activity->roi !== null ? round((float) $activity->roi, 2) : null;

                return [
                    'id' => (int) $activity->id,
                    'campaign_id' => $activity->campaign_id,
                    'name' => $activity->campaign_name ?: $activity->campaign_id ?: '未命名活动',
                    'main_title' => $activity->main_title,
                    'core_offer' => $activity->core_offer,
                    'starts_on' => $startsOn,
                    'ends_on' => $endsOn,
                    'status' => $this->classification->status($startsOn, $endsOn, $today),
                    'judgment' => $this->classification->judgment($roi),
                    'gmv' => $activity->sales_amount !== null ? round((float) $activity->sales_amount, 2) : null,
                    'ad_spend' => $activity->ad_spend !== null ? round((float) $activity->ad_spend, 2) : null,
                    'roi' => $roi,
                    'orders' => $activity->order_count !== null ? (int) $activity->order_count : null,
                    'summary' => $activity->campaign_summary,
                ];
            })
            ->values();

        return [
            'schema' => 'campaign-theme-planning-v1',
            'today' => $today,
            'counts' => [
                'total' => $activities->count(),
                'upcoming' => $activities->where('status', 'upcoming')->count(),
                'in_progress' => $activities->where('status', 'in_progress')->count(),
                'completed' => $activities->where('status', 'completed')->count(),
            ],
            'activities' => $activities->all(),
        ];
    }
}
