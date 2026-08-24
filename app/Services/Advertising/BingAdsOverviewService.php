<?php

namespace App\Services\Advertising;

use App\Models\AdvertisingChannelAccount;
use App\Models\AdvertisingChannelDailyMetric;
use App\Models\BingAdsCampaignDailyMetric;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class BingAdsOverviewService
{
    /** @param array{account?: string, date_from?: string, date_to?: string} $filters */
    public function forStore(Store $store, array $filters = []): array
    {
        $timezone = $store->timezone ?: 'UTC';
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $to = $this->date($filters['date_to'] ?? null, $today, $timezone);
        $from = $this->date($filters['date_from'] ?? null, $to->subDays(6), $timezone);
        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }
        if ($from->diffInDays($to) > 365) {
            $from = $to->subDays(365);
        }

        $accounts = AdvertisingChannelAccount::query()
            ->forOrganization((int) $store->organization_id)
            ->forStore((int) $store->getKey())
            ->where('provider', 'bing')
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
            ->where('provider', 'bing');
        if ($account !== null) {
            $baseQuery->where('external_account_id', $account);
        } else {
            $baseQuery->whereRaw('1 = 0');
        }

        $current = $this->summary(clone $baseQuery, $from->toDateString(), $to->toDateString());
        $previous = $this->summary(clone $baseQuery, $previousFrom->toDateString(), $previousTo->toDateString());

        return [
            'schema' => 'bing-ads-overview-v1',
            'accounts' => $accounts->map(fn (AdvertisingChannelAccount $item): array => [
                'id' => (string) $item->external_account_id,
                'name' => $item->name ?: 'Bing Ads '.$item->external_account_id,
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
            'trend' => $this->dailyTrend(clone $baseQuery, $from, $to),
            'previous_trend' => $this->dailyTrend(clone $baseQuery, $previousFrom, $previousTo),
            'campaigns' => $this->campaigns($store, $account, $from, $to),
            'deltas' => collect(array_keys($current))->mapWithKeys(
                fn (string $key): array => [$key => $this->delta((float) $current[$key], (float) $previous[$key])],
            )->all(),
        ];
    }

    private function date(mixed $value, CarbonImmutable $fallback, string $timezone): CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $fallback;
        }

        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $value, $timezone)->startOfDay();
        } catch (\Throwable) {
            return $fallback;
        }
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
            'conversions' => round($conversions, 2),
            'impressions' => round($impressions, 2),
            'clicks' => round($clicks, 2),
            'ctr' => $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : 0.0,
            'cpc' => $this->ratio($spend, $clicks),
            'cpa' => $this->ratio($spend, $conversions),
            'add_to_cart' => round($addToCart, 2),
            'checkout' => round($checkout, 2),
            'add_to_cart_cost' => $this->ratio($spend, $addToCart),
            'checkout_cost' => $this->ratio($spend, $checkout),
        ];
    }

    private function ratio(float $numerator, float $denominator): float
    {
        return $denominator > 0 ? round($numerator / $denominator, 2) : 0.0;
    }

    /** @return list<array<string, float|string>> */
    private function dailyTrend(Builder $query, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = $query
            ->whereBetween('metric_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('metric_date AS date, COALESCE(SUM(spend), 0) AS spend, COALESCE(SUM(attributed_sales), 0) AS revenue')
            ->selectRaw('COALESCE(SUM(impressions), 0) AS impressions, COALESCE(SUM(clicks), 0) AS clicks')
            ->selectRaw('COALESCE(SUM(conversions), 0) AS conversions, COALESCE(SUM(add_to_cart), 0) AS add_to_cart')
            ->selectRaw('COALESCE(SUM(initiate_checkout), 0) AS checkout')
            ->groupBy('metric_date')
            ->orderBy('metric_date')
            ->get()
            ->keyBy(fn ($row): string => CarbonImmutable::parse((string) $row->date)->toDateString());

        $points = [];
        for ($date = $from; $date->lte($to); $date = $date->addDay()) {
            $key = $date->toDateString();
            $row = $rows->get($key);
            $spend = max(0, (float) ($row?->spend ?? 0));
            $revenue = max(0, (float) ($row?->revenue ?? 0));
            $impressions = max(0, (float) ($row?->impressions ?? 0));
            $clicks = max(0, (float) ($row?->clicks ?? 0));
            $conversions = max(0, (float) ($row?->conversions ?? 0));
            $points[] = [
                'date' => $key,
                'spend' => round($spend, 2),
                'revenue' => round($revenue, 2),
                'roas' => $this->ratio($revenue, $spend),
                'impressions' => round($impressions, 2),
                'clicks' => round($clicks, 2),
                'ctr' => $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : 0.0,
                'cpc' => $this->ratio($spend, $clicks),
                'conversions' => round($conversions, 2),
                'add_to_cart' => round(max(0, (float) ($row?->add_to_cart ?? 0)), 2),
                'checkout' => round(max(0, (float) ($row?->checkout ?? 0)), 2),
                'cpa' => $this->ratio($spend, $conversions),
            ];
        }

        return $points;
    }

    /** @return list<array<string, float|string|null>> */
    private function campaigns(Store $store, ?string $account, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($account === null) {
            return [];
        }

        $rows = BingAdsCampaignDailyMetric::query()
            ->forOrganization((int) $store->organization_id)
            ->forStore((int) $store->getKey())
            ->where('external_account_id', $account)
            ->whereBetween('metric_date', [$from->toDateString(), $to->toDateString()])
            ->whereIn('campaign_status', ['Active', 'Enabled'])
            ->selectRaw('campaign_id, MAX(campaign_name) AS campaign_name, MAX(campaign_status) AS campaign_status, MAX(campaign_type) AS campaign_type')
            ->selectRaw('COALESCE(SUM(spend), 0) AS spend, COALESCE(SUM(attributed_sales), 0) AS revenue')
            ->selectRaw('COALESCE(SUM(impressions), 0) AS impressions, COALESCE(SUM(clicks), 0) AS clicks, COALESCE(SUM(conversions), 0) AS conversions')
            ->groupBy('campaign_id')
            ->orderByDesc('spend')
            ->get();
        $chartSpend = max(0, (float) $rows->take(6)->sum(fn ($row): float => (float) $row->spend));

        return $rows->map(function ($row, int $index) use ($chartSpend): array {
            $spend = max(0, (float) $row->spend);
            $revenue = max(0, (float) $row->revenue);
            $impressions = max(0, (float) $row->impressions);
            $clicks = max(0, (float) $row->clicks);

            return [
                'id' => (string) $row->campaign_id,
                'name' => $row->campaign_name ?: '广告系列 '.$row->campaign_id,
                'status' => $row->campaign_status,
                'type' => $row->campaign_type,
                'spend' => round($spend, 2),
                'revenue' => round($revenue, 2),
                'roas' => $this->ratio($revenue, $spend),
                'impressions' => round($impressions, 2),
                'clicks' => round($clicks, 2),
                'ctr' => $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : 0.0,
                'cpc' => $this->ratio($spend, $clicks),
                'conversions' => round(max(0, (float) $row->conversions), 2),
                'share' => $index < 6 && $chartSpend > 0 ? round(($spend / $chartSpend) * 100, 2) : 0.0,
            ];
        })->values()->all();
    }

    private function delta(float $current, float $previous): ?float
    {
        return $previous > 0 ? round((($current - $previous) / $previous) * 100, 1) : null;
    }
}
