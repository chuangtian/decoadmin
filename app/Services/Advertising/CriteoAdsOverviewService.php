<?php

namespace App\Services\Advertising;

use App\Models\AdvertisingChannelAccount;
use App\Models\AdvertisingChannelDailyMetric;
use App\Models\CriteoCampaignDailyMetric;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class CriteoAdsOverviewService
{
    private const WINDOW_DAYS = 7;

    private const REPORT_TIMEZONE = 'UTC';

    /** @return array<string, mixed> */
    public function forStore(Store $store): array
    {
        $timezone = self::REPORT_TIMEZONE;
        $accounts = AdvertisingChannelAccount::query()
            ->forOrganization((int) $store->organization_id)
            ->forStore((int) $store->getKey())
            ->where('provider', 'criteo')
            ->orderBy('name')
            ->orderBy('external_account_id')
            ->get();
        $baseQuery = AdvertisingChannelDailyMetric::query()
            ->forOrganization((int) $store->organization_id)
            ->forStore((int) $store->getKey())
            ->where('provider', 'criteo');
        $latestMetricDate = (clone $baseQuery)->max('metric_date');
        $to = $latestMetricDate
            ? CarbonImmutable::parse((string) $latestMetricDate, $timezone)->startOfDay()
            : CarbonImmutable::now($timezone)->startOfDay();
        $from = $to->subDays(self::WINDOW_DAYS - 1);
        $previousTo = $from->subDay();
        $previousFrom = $previousTo->subDays(self::WINDOW_DAYS - 1);
        $current = $this->summary(clone $baseQuery, $from->toDateString(), $to->toDateString());
        $previous = $this->summary(clone $baseQuery, $previousFrom->toDateString(), $previousTo->toDateString());
        $metricKeys = ['spend', 'revenue', 'conversions', 'roas', 'impressions', 'clicks', 'ctr', 'cpc', 'cpa'];
        $dataDays = (int) ((clone $baseQuery)
            ->whereDate('metric_date', '>=', $from->toDateString())
            ->whereDate('metric_date', '<=', $to->toDateString())
            ->selectRaw('COUNT(DISTINCT metric_date) AS aggregate')
            ->value('aggregate'));
        $currencies = $accounts->pluck('currency')->filter()->unique()->values();
        $lastSyncedAt = (clone $baseQuery)->max('synced_at');
        $campaigns = $this->campaignPerformance($store, $from->toDateString(), $to->toDateString());

        return [
            'schema' => 'criteo-ads-overview-v3',
            'available' => $latestMetricDate !== null,
            'accounts' => $accounts->map(fn (AdvertisingChannelAccount $account): array => [
                'id' => (string) $account->external_account_id,
                'name' => $account->name ?: 'Criteo advertiser '.$account->external_account_id,
                'currency' => $account->currency ?: 'USD',
                'timezone' => $account->timezone ?: self::REPORT_TIMEZONE,
            ])->values()->all(),
            'period' => [
                'label' => '最新 7 天',
                'date_from' => $from->toDateString(),
                'date_to' => $to->toDateString(),
                'days_expected' => self::WINDOW_DAYS,
                'days_available' => $dataDays,
            ],
            'comparison_period' => [
                'date_from' => $previousFrom->toDateString(),
                'date_to' => $previousTo->toDateString(),
            ],
            'attribution' => [
                'label' => '点击后 7 天 + 展示后 24 小时',
                'click_window_days' => 7,
                'view_window_hours' => 24,
            ],
            'report_timezone' => [
                'id' => self::REPORT_TIMEZONE,
                'label' => 'Criteo 系统时间 (UTC)',
            ],
            'currency' => $currencies->count() === 1 ? (string) $currencies->first() : 'USD',
            'current' => $current,
            'previous' => $previous,
            'deltas' => collect($metricKeys)->mapWithKeys(
                fn (string $key): array => [$key => $this->delta((float) $current[$key], (float) $previous[$key])],
            )->all(),
            'daily' => $this->daily(clone $baseQuery, $from, $to),
            'campaign_spend_share' => $this->campaignSpendShare($campaigns),
            'campaigns' => $campaigns,
            'last_synced_at' => $lastSyncedAt
                ? CarbonImmutable::parse((string) $lastSyncedAt)->toIso8601String()
                : null,
        ];
    }

    /** @return list<array{id: string, name: string, spend: float, revenue: float, roas: float, impressions: int, clicks: int, ctr: float, cpc: float, conversions: float, cpa: float}> */
    private function campaignPerformance(Store $store, string $from, string $to): array
    {
        return CriteoCampaignDailyMetric::query()
            ->forOrganization((int) $store->organization_id)
            ->forStore((int) $store->getKey())
            ->whereDate('metric_date', '>=', $from)
            ->whereDate('metric_date', '<=', $to)
            ->selectRaw("campaign_id, COALESCE(MAX(campaign_name), '') AS campaign_name")
            ->selectRaw('COALESCE(SUM(spend), 0) AS spend, COALESCE(SUM(attributed_sales), 0) AS revenue')
            ->selectRaw('COALESCE(SUM(impressions), 0) AS impressions, COALESCE(SUM(clicks), 0) AS clicks, COALESCE(SUM(conversions), 0) AS conversions')
            ->groupBy('campaign_id')
            ->havingRaw('SUM(spend) > 0 OR SUM(impressions) > 0 OR SUM(clicks) > 0 OR SUM(conversions) > 0')
            ->orderByDesc('spend')
            ->get()
            ->map(function ($row): array {
                $spend = max(0, (float) $row->spend);
                $revenue = max(0, (float) $row->revenue);
                $impressions = max(0, (int) $row->impressions);
                $clicks = max(0, (int) $row->clicks);
                $conversions = max(0, (float) $row->conversions);

                return [
                    'id' => (string) $row->campaign_id,
                    'name' => trim((string) $row->campaign_name) ?: '广告系列 '.$row->campaign_id,
                    'spend' => round($spend, 2),
                    'revenue' => round($revenue, 2),
                    'roas' => $this->ratio($revenue, $spend),
                    'impressions' => $impressions,
                    'clicks' => $clicks,
                    'ctr' => $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : 0.0,
                    'cpc' => $this->ratio($spend, $clicks),
                    'conversions' => round($conversions, 2),
                    'cpa' => $this->ratio($spend, $conversions),
                ];
            })
            ->values()
            ->all();
    }

    /** @param list<array{id: string, name: string, spend: float}> $campaigns
     * @return array{available: bool, total_spend: float, items: list<array{id: string, name: string, spend: float, share: float}>}
     */
    private function campaignSpendShare(array $campaigns): array
    {
        $rows = collect($campaigns);
        $total = max(0, (float) $rows->sum('spend'));
        $top = $rows->take(5)->values();
        $otherSpend = max(0, (float) $rows->slice(5)->sum('spend'));
        $items = $top->map(fn (array $row): array => [
            'id' => $row['id'],
            'name' => $row['name'],
            'spend' => $row['spend'],
            'share' => $total > 0 ? round(($row['spend'] / $total) * 100, 2) : 0.0,
        ]);
        if ($otherSpend > 0) {
            $items->push([
                'id' => 'other',
                'name' => '其他广告系列',
                'spend' => round($otherSpend, 2),
                'share' => $total > 0 ? round(($otherSpend / $total) * 100, 2) : 0.0,
            ]);
        }

        return [
            'available' => $items->isNotEmpty(),
            'total_spend' => round($total, 2),
            'items' => $items->values()->all(),
        ];
    }

    /** @return array<string, float|int> */
    private function summary(Builder $query, string $from, string $to): array
    {
        $totals = $query
            ->whereDate('metric_date', '>=', $from)
            ->whereDate('metric_date', '<=', $to)
            ->selectRaw('COALESCE(SUM(spend), 0) AS spend')
            ->selectRaw('COALESCE(SUM(attributed_sales), 0) AS revenue')
            ->selectRaw('COALESCE(SUM(impressions), 0) AS impressions')
            ->selectRaw('COALESCE(SUM(clicks), 0) AS clicks')
            ->selectRaw('COALESCE(SUM(conversions), 0) AS conversions')
            ->first();
        $spend = max(0, (float) ($totals?->spend ?? 0));
        $revenue = max(0, (float) ($totals?->revenue ?? 0));
        $impressions = max(0, (int) ($totals?->impressions ?? 0));
        $clicks = max(0, (int) ($totals?->clicks ?? 0));
        $conversions = max(0, (float) ($totals?->conversions ?? 0));

        return [
            'spend' => round($spend, 2),
            'revenue' => round($revenue, 2),
            'conversions' => round($conversions, 2),
            'roas' => $this->ratio($revenue, $spend),
            'impressions' => $impressions,
            'clicks' => $clicks,
            'ctr' => $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : 0.0,
            'cpc' => $this->ratio($spend, $clicks),
            'cpa' => $this->ratio($spend, $conversions),
        ];
    }

    /** @return list<array<string, float|int|string>> */
    private function daily(Builder $query, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = $query
            ->whereDate('metric_date', '>=', $from->toDateString())
            ->whereDate('metric_date', '<=', $to->toDateString())
            ->selectRaw('metric_date AS date, COALESCE(SUM(spend), 0) AS spend, COALESCE(SUM(attributed_sales), 0) AS revenue')
            ->selectRaw('COALESCE(SUM(impressions), 0) AS impressions, COALESCE(SUM(clicks), 0) AS clicks, COALESCE(SUM(conversions), 0) AS conversions')
            ->groupBy('metric_date')
            ->orderBy('metric_date')
            ->get()
            ->keyBy(fn ($row): string => CarbonImmutable::parse((string) $row->date)->toDateString());

        $days = [];
        for ($date = $from; $date->lte($to); $date = $date->addDay()) {
            $key = $date->toDateString();
            $row = $rows->get($key);
            $spend = max(0, (float) ($row?->spend ?? 0));
            $revenue = max(0, (float) ($row?->revenue ?? 0));
            $impressions = max(0, (int) ($row?->impressions ?? 0));
            $clicks = max(0, (int) ($row?->clicks ?? 0));
            $conversions = max(0, (float) ($row?->conversions ?? 0));
            $days[] = [
                'date' => $key,
                'spend' => round($spend, 2),
                'revenue' => round($revenue, 2),
                'conversions' => round($conversions, 2),
                'roas' => $this->ratio($revenue, $spend),
                'impressions' => $impressions,
                'clicks' => $clicks,
                'ctr' => $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : 0.0,
                'cpc' => $this->ratio($spend, $clicks),
                'cpa' => $this->ratio($spend, $conversions),
            ];
        }

        return $days;
    }

    private function ratio(float $numerator, float $denominator): float
    {
        return $denominator > 0 ? round($numerator / $denominator, 2) : 0.0;
    }

    private function delta(float $current, float $previous): ?float
    {
        return $previous > 0 ? round((($current - $previous) / $previous) * 100, 1) : null;
    }
}
