<?php

namespace App\Services\Advertising;

use App\Models\AdvertisingChannelAccount;
use App\Models\AdvertisingChannelDailyMetric;
use App\Models\Store;
use App\Models\TikTokAdsAdDailyMetric;
use App\Models\TikTokAdsCampaignDailyMetric;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class TikTokAdsOverviewService
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
            ->where('provider', 'tiktok')
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
            ->where('provider', 'tiktok');
        if ($account !== null) {
            $baseQuery->where('external_account_id', $account);
        } else {
            $baseQuery->whereRaw('1 = 0');
        }

        $current = $this->summary(clone $baseQuery, $from->toDateString(), $to->toDateString());
        $previous = $this->summary(clone $baseQuery, $previousFrom->toDateString(), $previousTo->toDateString());
        $keys = [
            'spend', 'revenue', 'roas', 'cpa', 'add_to_cart', 'checkout',
            'add_to_cart_cost', 'checkout_cost', 'impressions', 'clicks',
            'conversions', 'ctr', 'cpc',
        ];

        $campaignDetails = $this->campaignDetails($store, $account, $from->toDateString(), $to->toDateString());

        return [
            'schema' => 'tiktok-ads-overview-v1',
            'accounts' => $accounts->map(fn (AdvertisingChannelAccount $item): array => [
                'id' => (string) $item->external_account_id,
                'name' => $item->name ?: 'TikTok advertiser '.$item->external_account_id,
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
            'trend' => $this->dailyTrend(clone $baseQuery, $from->toDateString(), $to->toDateString()),
            'previous_trend' => $this->dailyTrend(clone $baseQuery, $previousFrom->toDateString(), $previousTo->toDateString()),
            'campaigns' => $this->campaignSpendShare($campaignDetails),
            'campaign_details' => $campaignDetails,
            'creatives' => $this->creativeDetails($store, $account, $from->toDateString(), $to->toDateString()),
            'funnel' => [
                ['key' => 'impressions', 'label' => '曝光', 'value' => $current['impressions']],
                ['key' => 'clicks', 'label' => '点击', 'value' => $current['clicks']],
                ['key' => 'conversions', 'label' => '购买', 'value' => $current['conversions']],
            ],
            'deltas' => collect($keys)->mapWithKeys(
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
        $totals = $query->whereBetween('metric_date', [$from, $to])
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
            'spend' => round($spend, 2), 'revenue' => round($revenue, 2),
            'roas' => $this->ratio($revenue, $spend), 'cpa' => $this->ratio($spend, $conversions),
            'add_to_cart' => round($addToCart, 2), 'checkout' => round($checkout, 2),
            'add_to_cart_cost' => $this->ratio($spend, $addToCart),
            'checkout_cost' => $this->ratio($spend, $checkout),
            'impressions' => round($impressions, 2), 'clicks' => round($clicks, 2),
            'conversions' => round($conversions, 2),
            'ctr' => $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : 0.0,
            'cpc' => $this->ratio($spend, $clicks),
        ];
    }

    /** @return list<array<string, float|string>> */
    private function dailyTrend(Builder $query, string $from, string $to): array
    {
        return $query->whereBetween('metric_date', [$from, $to])
            ->selectRaw('metric_date AS date, COALESCE(SUM(spend), 0) AS spend, COALESCE(SUM(attributed_sales), 0) AS revenue')
            ->selectRaw('COALESCE(SUM(impressions), 0) AS impressions, COALESCE(SUM(clicks), 0) AS clicks, COALESCE(SUM(conversions), 0) AS conversions')
            ->groupBy('metric_date')->orderBy('metric_date')->get()
            ->map(function ($row): array {
                $spend = max(0, (float) $row->spend);
                $revenue = max(0, (float) $row->revenue);

                return [
                    'date' => CarbonImmutable::parse((string) $row->date)->toDateString(),
                    'spend' => round($spend, 2), 'revenue' => round($revenue, 2),
                    'roi' => $this->ratio($revenue, $spend),
                    'impressions' => round(max(0, (float) $row->impressions), 2),
                    'clicks' => round(max(0, (float) $row->clicks), 2),
                    'conversions' => round(max(0, (float) $row->conversions), 2),
                ];
            })->values()->all();
    }

    /** @return list<array<string, float|string>> */
    private function campaignDetails(Store $store, ?string $account, string $from, string $to): array
    {
        if ($account === null) {
            return [];
        }
        $rows = TikTokAdsCampaignDailyMetric::query()
            ->forOrganization((int) $store->organization_id)
            ->forStore((int) $store->getKey())
            ->where('external_account_id', $account)
            ->whereBetween('metric_date', [$from, $to])
            ->selectRaw('campaign_id, MAX(campaign_name) AS campaign_name, MAX(campaign_status) AS campaign_status, MAX(objective_type) AS objective_type')
            ->selectRaw('COALESCE(SUM(spend), 0) AS spend, COALESCE(SUM(attributed_sales), 0) AS revenue')
            ->selectRaw('COALESCE(SUM(impressions), 0) AS impressions, COALESCE(SUM(clicks), 0) AS clicks, COALESCE(SUM(conversions), 0) AS conversions')
            ->groupBy('campaign_id')->orderByDesc('spend')->get();
        $total = max(0, (float) $rows->sum(fn ($row): float => (float) $row->spend));

        return $rows->map(function ($row) use ($total): array {
            $spend = max(0, (float) $row->spend);
            $revenue = max(0, (float) $row->revenue);
            $impressions = max(0, (float) $row->impressions);
            $clicks = max(0, (float) $row->clicks);

            return [
                'id' => (string) $row->campaign_id,
                'name' => $row->campaign_name ?: '广告系列 '.$row->campaign_id,
                'status' => (string) ($row->campaign_status ?: 'UNKNOWN'),
                'objective_type' => (string) ($row->objective_type ?: ''),
                'spend' => round($spend, 2),
                'revenue' => round($revenue, 2),
                'roas' => $this->ratio($revenue, $spend),
                'impressions' => round($impressions, 2),
                'clicks' => round($clicks, 2),
                'ctr' => $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : 0.0,
                'conversions' => round(max(0, (float) $row->conversions), 2),
                'cpc' => $this->ratio($spend, $clicks),
                'share' => $total > 0 ? round(($spend / $total) * 100, 2) : 0.0,
            ];
        })->values()->all();
    }

    /** @return list<array<string, float|int|string|list<string>|null>> */
    private function creativeDetails(Store $store, ?string $account, string $from, string $to): array
    {
        if ($account === null) {
            return [];
        }

        $query = TikTokAdsAdDailyMetric::query()
            ->forOrganization((int) $store->organization_id)
            ->forStore((int) $store->getKey())
            ->where('external_account_id', $account)
            ->whereBetween('metric_date', [$from, $to]);
        $rows = (clone $query)
            ->selectRaw('ad_id, COALESCE(SUM(spend), 0) AS spend, COALESCE(SUM(attributed_sales), 0) AS revenue')
            ->selectRaw('COALESCE(SUM(impressions), 0) AS impressions, COALESCE(SUM(clicks), 0) AS clicks, COALESCE(SUM(conversions), 0) AS conversions')
            ->selectRaw('COALESCE(SUM(video_play_actions), 0) AS video_plays, COALESCE(SUM(video_watched_2s), 0) AS video_watched_2s')
            ->selectRaw('COALESCE(SUM(average_video_play * video_play_actions), 0) AS weighted_play_time')
            ->groupBy('ad_id')
            ->havingRaw('COALESCE(SUM(spend), 0) > 0')
            ->get();
        $metadata = (clone $query)
            ->whereIn('ad_id', $rows->pluck('ad_id'))
            ->orderByDesc('metric_date')
            ->orderByDesc('id')
            ->get(['ad_id', 'ad_name', 'ad_text', 'ad_texts', 'ad_format', 'video_id', 'campaign_id', 'campaign_name'])
            ->unique('ad_id')
            ->keyBy('ad_id');

        return $rows->map(function ($row) use ($metadata): array {
            $meta = $metadata->get((string) $row->ad_id);
            $spend = max(0, (float) $row->spend);
            $revenue = max(0, (float) $row->revenue);
            $impressions = max(0, (int) $row->impressions);
            $clicks = max(0, (int) $row->clicks);
            $videoPlays = max(0, (int) $row->video_plays);
            $watched2s = max(0, (int) $row->video_watched_2s);
            $variants = collect((array) ($meta?->ad_texts ?? []))
                ->map(fn (mixed $text): string => trim((string) $text))
                ->filter()->unique()->values();
            $body = trim((string) ($meta?->ad_text ?? ''));
            if ($body !== '' && ! $variants->contains($body)) {
                $variants->prepend($body);
            }

            return [
                'id' => (string) $row->ad_id,
                'name' => $meta?->ad_name ?: '广告 '.$row->ad_id,
                'body' => $body !== '' ? $body : ($variants->first() ?: ''),
                'text_variants' => $variants->all(),
                'format' => strtoupper((string) ($meta?->ad_format ?: 'VIDEO')),
                'video_id' => $meta?->video_id,
                'campaign_id' => $meta?->campaign_id,
                'campaign_name' => $meta?->campaign_name,
                'spend' => round($spend, 2),
                'revenue' => round($revenue, 2),
                'roas' => $this->ratio($revenue, $spend),
                'impressions' => $impressions,
                'clicks' => $clicks,
                'ctr' => $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : 0.0,
                'purchases' => round(max(0, (float) $row->conversions), 2),
                'video_plays' => $videoPlays,
                'video_2s_rate' => $videoPlays > 0 ? round(($watched2s / $videoPlays) * 100, 2) : 0.0,
                'average_play_time' => $videoPlays > 0 ? round(max(0, (float) $row->weighted_play_time) / $videoPlays, 2) : 0.0,
            ];
        })->sortByDesc('roas')->take(12)->values()->all();
    }

    /** @param list<array<string, float|string>> $details @return list<array{id: string, name: string, spend: float, share: float}> */
    private function campaignSpendShare(array $details): array
    {
        $top = collect($details)->take(6);
        $items = $top->map(fn (array $row): array => [
            'id' => (string) $row['id'],
            'name' => (string) $row['name'],
            'spend' => (float) $row['spend'],
            'share' => (float) $row['share'],
        ])->values();
        $total = (float) collect($details)->sum(fn (array $row): float => (float) $row['spend']);
        $other = max(0, $total - (float) $top->sum(fn (array $row): float => (float) $row['spend']));
        if ($other > 0) {
            $items->push(['id' => 'other', 'name' => '其他广告系列', 'spend' => round($other, 2), 'share' => round(($other / $total) * 100, 2)]);
        }

        return $items->all();
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
