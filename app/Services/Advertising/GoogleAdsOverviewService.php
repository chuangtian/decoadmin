<?php

namespace App\Services\Advertising;

use App\Models\AdvertisingChannelAccount;
use App\Models\AdvertisingChannelDailyMetric;
use App\Models\GoogleAdsCampaignDailyMetric;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class GoogleAdsOverviewService
{
    public function __construct(private GoogleAdsDateRangeService $dateRange) {}

    /** @param array{account?: string, date_from?: string, date_to?: string} $filters */
    public function forStore(Store $store, array $filters = []): array
    {
        [$from, $to, $today] = $this->dateRange->resolve($store, $filters);

        $accounts = AdvertisingChannelAccount::query()
            ->forOrganization((int) $store->organization_id)
            ->forStore((int) $store->getKey())
            ->where('provider', 'google')
            ->orderBy('name')
            ->orderBy('external_account_id')
            ->get();
        $requestedAccount = trim((string) ($filters['account'] ?? ''));
        $selected = $accounts->firstWhere('external_account_id', $requestedAccount) ?? $accounts->first();
        $account = $selected?->external_account_id;

        $days = $from->diffInDays($to) + 1;
        $previousTo = $from->subDay();
        $previousFrom = $previousTo->subDays($days - 1);
        $baseQuery = AdvertisingChannelDailyMetric::query()
            ->forOrganization((int) $store->organization_id)
            ->forStore((int) $store->getKey())
            ->where('provider', 'google');
        if ($account !== null) {
            $baseQuery->where('external_account_id', $account);
        } else {
            $baseQuery->whereRaw('1 = 0');
        }

        $current = $this->summary(clone $baseQuery, $from->toDateString(), $to->toDateString());
        $previous = $this->summary(clone $baseQuery, $previousFrom->toDateString(), $previousTo->toDateString());
        $trend = $this->dailyTrend(clone $baseQuery, $from->toDateString(), $to->toDateString());
        $campaigns = $this->campaigns(
            $store,
            $account,
            $from->toDateString(),
            $to->toDateString(),
        );
        $campaignSpend = $this->campaignSpend($campaigns);
        $keys = [
            'spend', 'revenue', 'roas', 'cpa', 'add_to_cart', 'checkout',
            'add_to_cart_cost', 'checkout_cost', 'impressions', 'clicks',
            'conversions', 'ctr', 'cpc',
        ];

        return [
            'schema' => 'google-ads-overview-v2',
            'store_today' => $today->toDateString(),
            'timezone' => $today->timezoneName,
            'accounts' => $accounts->map(fn (AdvertisingChannelAccount $item): array => [
                'id' => (string) $item->external_account_id,
                'name' => $item->name ?: 'Google Ads '.$item->external_account_id,
                'currency' => $item->currency ?: 'USD',
                'timezone' => $item->timezone,
            ])->values()->all(),
            'filters' => [
                'account' => $account,
                'date_from' => $from->toDateString(),
                'date_to' => $to->toDateString(),
            ],
            'comparison_period' => [
                'date_from' => $previousFrom->toDateString(),
                'date_to' => $previousTo->toDateString(),
            ],
            'currency' => $selected?->currency ?: 'USD',
            'current' => $current,
            'previous' => $previous,
            'trend' => $trend,
            'campaign_spend' => $campaignSpend,
            'campaigns' => $campaigns,
            'funnel' => [
                ['key' => 'impressions', 'label' => '曝光', 'value' => $current['impressions']],
                ['key' => 'clicks', 'label' => '点击', 'value' => $current['clicks']],
                ['key' => 'conversions', 'label' => '转化', 'value' => $current['conversions']],
            ],
            'deltas' => collect($keys)->mapWithKeys(
                fn (string $key): array => [$key => $this->delta((float) $current[$key], (float) $previous[$key])],
            )->all(),
        ];
    }

    /** @return array<string, float> */
    private function summary(Builder $query, string $from, string $to): array
    {
        $totals = $query
            ->whereBetween('metric_date', [$from, $to])
            ->selectRaw('COALESCE(SUM(spend), 0) AS spend')
            ->selectRaw('COALESCE(SUM(attributed_sales), 0) AS revenue')
            ->selectRaw('COALESCE(SUM(impressions), 0) AS impressions')
            ->selectRaw('COALESCE(SUM(clicks), 0) AS clicks')
            ->selectRaw('COALESCE(SUM(conversions), 0) AS conversions')
            ->selectRaw('COALESCE(SUM(add_to_cart), 0) AS add_to_cart')
            ->selectRaw('COALESCE(SUM(initiate_checkout), 0) AS checkout')
            ->first();

        $spend = max(0, (float) ($totals?->spend ?? 0));
        $revenue = max(0, (float) ($totals?->revenue ?? 0));
        $impressions = max(0, (float) ($totals?->impressions ?? 0));
        $clicks = max(0, (float) ($totals?->clicks ?? 0));
        $conversions = max(0, (float) ($totals?->conversions ?? 0));
        $addToCart = max(0, (float) ($totals?->add_to_cart ?? 0));
        $checkout = max(0, (float) ($totals?->checkout ?? 0));

        return [
            'spend' => round($spend, 2),
            'revenue' => round($revenue, 2),
            'roas' => $this->ratio($revenue, $spend),
            'cpa' => $this->ratio($spend, $conversions),
            'add_to_cart' => round($addToCart, 2),
            'checkout' => round($checkout, 2),
            'add_to_cart_cost' => $this->ratio($spend, $addToCart),
            'checkout_cost' => $this->ratio($spend, $checkout),
            'impressions' => round($impressions, 2),
            'clicks' => round($clicks, 2),
            'conversions' => round($conversions, 2),
            'ctr' => $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : 0.0,
            'cpc' => $this->ratio($spend, $clicks),
        ];
    }

    /** @return array<int, array{date: string, spend: float, revenue: float, conversion_value: float, roas: float, roi: float, impressions: float, clicks: float, ctr: float, cpc: float, conversions: float}> */
    private function dailyTrend(Builder $query, string $from, string $to): array
    {
        return $query
            ->whereBetween('metric_date', [$from, $to])
            ->selectRaw('metric_date AS date')
            ->selectRaw('COALESCE(SUM(spend), 0) AS spend')
            ->selectRaw('COALESCE(SUM(attributed_sales), 0) AS revenue')
            ->selectRaw('COALESCE(SUM(conversion_value_by_conversion_date), 0) AS conversion_value')
            ->selectRaw('COALESCE(SUM(impressions), 0) AS impressions')
            ->selectRaw('COALESCE(SUM(clicks), 0) AS clicks')
            ->selectRaw('COALESCE(SUM(conversions), 0) AS conversions')
            ->groupBy('metric_date')
            ->orderBy('metric_date')
            ->get()
            ->map(function ($row): array {
                $spend = max(0, (float) $row->spend);
                $revenue = max(0, (float) $row->revenue);
                $conversionValue = max(0, (float) $row->conversion_value);
                $impressions = max(0, (float) $row->impressions);
                $clicks = max(0, (float) $row->clicks);

                return [
                    'date' => CarbonImmutable::parse((string) $row->date)->toDateString(),
                    'spend' => round($spend, 2),
                    'revenue' => round($revenue, 2),
                    'conversion_value' => round($conversionValue, 2),
                    'roas' => $this->ratio($revenue, $spend),
                    'roi' => $this->ratio($conversionValue, $spend),
                    'impressions' => round($impressions, 2),
                    'clicks' => round($clicks, 2),
                    'ctr' => $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : 0.0,
                    'cpc' => $this->ratio($spend, $clicks),
                    'conversions' => round(max(0, (float) $row->conversions), 2),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{
     *     id: string, name: string, status: string, channel_type: string, spend: float,
     *     revenue: float, roas: float, impressions: float, clicks: float, ctr: float,
     *     cpc: float, conversions: float
     * }>
     */
    private function campaigns(Store $store, ?string $account, string $from, string $to): array
    {
        if ($account === null) {
            return [];
        }

        return GoogleAdsCampaignDailyMetric::query()
            ->forOrganization((int) $store->organization_id)
            ->forStore((int) $store->getKey())
            ->where('external_account_id', $account)
            ->whereBetween('metric_date', [$from, $to])
            ->selectRaw('campaign_id')
            ->selectRaw("COALESCE(MAX(campaign_name), '') AS campaign_name")
            ->selectRaw("COALESCE(MAX(campaign_status), '') AS campaign_status")
            ->selectRaw("COALESCE(MAX(advertising_channel_type), '') AS advertising_channel_type")
            ->selectRaw('COALESCE(SUM(spend), 0) AS spend')
            ->selectRaw('COALESCE(SUM(conversions_value), 0) AS revenue')
            ->selectRaw('COALESCE(SUM(impressions), 0) AS impressions')
            ->selectRaw('COALESCE(SUM(clicks), 0) AS clicks')
            ->selectRaw('COALESCE(SUM(conversions), 0) AS conversions')
            ->groupBy('campaign_id')
            ->orderByDesc('spend')
            ->limit(500)
            ->get()
            ->map(function ($campaign): array {
                $spend = max(0, (float) $campaign->spend);
                $revenue = max(0, (float) $campaign->revenue);
                $impressions = max(0, (float) $campaign->impressions);
                $clicks = max(0, (float) $campaign->clicks);

                return [
                    'id' => (string) $campaign->campaign_id,
                    'name' => filled($campaign->campaign_name)
                        ? (string) $campaign->campaign_name
                        : '广告系列 '.$campaign->campaign_id,
                    'status' => $this->campaignStatus((string) $campaign->campaign_status),
                    'channel_type' => $this->campaignType((string) $campaign->advertising_channel_type),
                    'spend' => round($spend, 2),
                    'revenue' => round($revenue, 2),
                    'roas' => $this->ratio($revenue, $spend),
                    'impressions' => round($impressions, 2),
                    'clicks' => round($clicks, 2),
                    'ctr' => $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : 0.0,
                    'cpc' => $this->ratio($spend, $clicks),
                    'conversions' => round(max(0, (float) $campaign->conversions), 2),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array{id: string, name: string, spend: float}>  $campaigns
     * @return array<int, array{id: string, name: string, spend: float, percentage: float}>
     */
    private function campaignSpend(array $campaigns): array
    {
        $displayed = collect($campaigns)->take(6);
        $displayedTotal = max(0, (float) $displayed->sum('spend'));

        return $displayed->map(function (array $campaign) use ($displayedTotal): array {
            $spend = max(0, (float) $campaign['spend']);

            return [
                'id' => $campaign['id'],
                'name' => $campaign['name'],
                'spend' => round($spend, 2),
                'percentage' => $displayedTotal > 0 ? round(($spend / $displayedTotal) * 100, 2) : 0.0,
            ];
        })->values()->all();
    }

    private function campaignType(string $type): string
    {
        return match (strtoupper($type)) {
            'SEARCH' => '搜索',
            'PERFORMANCE_MAX' => 'PMax',
            'DISPLAY' => '展示',
            'SHOPPING' => '购物',
            'VIDEO' => '视频',
            'DEMAND_GEN' => '需求开发',
            'HOTEL' => '酒店',
            'LOCAL' => '本地',
            'APP' => '应用',
            'SMART' => '智能',
            'MULTI_CHANNEL' => '多渠道',
            default => filled($type) ? $type : '其他',
        };
    }

    private function campaignStatus(string $status): string
    {
        return match (strtoupper($status)) {
            'ENABLED' => '运行中',
            'PAUSED' => '已暂停',
            'REMOVED' => '已移除',
            default => filled($status) ? $status : '未知',
        };
    }

    private function ratio(float $numerator, float $denominator): float
    {
        return $denominator > 0 ? round($numerator / $denominator, 2) : 0.0;
    }

    private function delta(float $current, float $previous): ?float
    {
        if ($previous == 0.0) {
            return $current == 0.0 ? 0.0 : null;
        }

        return round((($current - $previous) / abs($previous)) * 100, 1);
    }
}
