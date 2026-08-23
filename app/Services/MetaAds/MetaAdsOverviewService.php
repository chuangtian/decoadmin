<?php

namespace App\Services\MetaAds;

use App\Models\MetaAdAccount;
use App\Models\MetaAdInsight;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class MetaAdsOverviewService
{
    /**
     * @param  array{account?: mixed, date_from?: mixed, date_to?: mixed, compare?: mixed}  $filters
     * @return array<string, mixed>
     */
    public function forStore(Store $store, array $filters = []): array
    {
        $timezone = $store->timezone ?: (string) config('app.timezone', 'UTC');
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $dateFrom = $this->date($filters['date_from'] ?? null, $today->startOfMonth(), $timezone, 'date_from');
        $dateTo = $this->date($filters['date_to'] ?? null, $today, $timezone, 'date_to');

        if ($dateFrom->gt($dateTo)) {
            throw ValidationException::withMessages(['date_to' => '结束日期不能早于开始日期。']);
        }
        if ($dateFrom->diffInDays($dateTo) > 366) {
            throw ValidationException::withMessages(['date_to' => '单次查询范围不能超过 367 天。']);
        }

        $accounts = MetaAdAccount::query()
            ->forOrganization((int) $store->organization_id)
            ->forStore((int) $store->getKey())
            ->orderByRaw('name IS NULL')
            ->orderBy('name')
            ->orderBy('meta_account_id')
            ->get(['meta_account_id', 'name', 'currency', 'account_status'])
            ->map(fn (MetaAdAccount $account): array => [
                'id' => $account->meta_account_id,
                'name' => $account->name ?: $account->meta_account_id,
                'label' => ($account->name ?: '未命名账户').' ('.$account->meta_account_id.')',
                'currency' => $account->currency,
                'active' => $account->account_status === null || $account->account_status === 1,
            ])
            ->values();

        $account = is_string($filters['account'] ?? null) && $filters['account'] !== ''
            ? (string) $filters['account']
            : 'all';
        if ($account !== 'all' && ! $accounts->contains('id', $account)) {
            throw ValidationException::withMessages(['account' => '所选 Meta 广告账户不属于当前店铺。']);
        }

        $compare = ($filters['compare'] ?? 'previous') === 'none' ? 'none' : 'previous';
        $current = $this->summary($store, $dateFrom, $dateTo, $account);
        $comparisonPeriod = null;
        $comparisonDaily = [];
        $previous = null;
        $deltas = null;

        if ($compare === 'previous') {
            $days = $dateFrom->diffInDays($dateTo) + 1;
            $previousTo = $dateFrom->subDay();
            $previousFrom = $previousTo->subDays($days - 1);
            $previous = $this->summary($store, $previousFrom, $previousTo, $account);
            $comparisonDaily = $this->daily($store, $previousFrom, $previousTo, $account);
            $deltas = collect($current)
                ->mapWithKeys(fn (float|int|null $value, string $key): array => [
                    $key => $this->delta($value, $previous[$key] ?? null),
                ])
                ->all();
            $comparisonPeriod = [
                'date_from' => $previousFrom->toDateString(),
                'date_to' => $previousTo->toDateString(),
            ];
        }

        $currencies = $accounts->pluck('currency')->filter()->unique()->values();
        $campaigns = $this->campaignPerformance($store, $dateFrom, $dateTo, $account);
        $campaignLimit = 100;

        return [
            'schema' => 'meta-ads-overview-v1',
            'accounts' => $accounts->all(),
            'filters' => [
                'account' => $account,
                'date_from' => $dateFrom->toDateString(),
                'date_to' => $dateTo->toDateString(),
                'compare' => $compare,
                'timezone' => $timezone,
            ],
            'comparison_period' => $comparisonPeriod,
            'currency' => $currencies->count() === 1 ? $currencies->first() : null,
            'summary' => $current,
            'comparison' => $previous,
            'deltas' => $deltas,
            'daily' => $this->daily($store, $dateFrom, $dateTo, $account),
            'comparison_daily' => $comparisonDaily,
            'campaign_spend' => $this->campaignSpend($campaigns),
            'campaigns' => [
                'total' => $campaigns->count(),
                'limit' => $campaignLimit,
                'truncated' => $campaigns->count() > $campaignLimit,
                'items' => $campaigns->take($campaignLimit)->values()->all(),
            ],
        ];
    }

    private function date(mixed $value, CarbonImmutable $fallback, string $timezone, string $field): CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return $fallback;
        }

        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $value, $timezone)->startOfDay();
        } catch (\Throwable) {
            throw ValidationException::withMessages([$field => '日期格式必须为 YYYY-MM-DD。']);
        }
    }

    /** @return array<string, float|int|null> */
    private function summary(Store $store, CarbonImmutable $from, CarbonImmutable $to, string $account): array
    {
        $row = $this->query($store, $from, $to, $account)
            ->selectRaw('COALESCE(SUM(spend), 0) AS spend')
            ->selectRaw('COALESCE(SUM(purchase_value), 0) AS purchase_value')
            ->selectRaw('COALESCE(SUM(purchases), 0) AS purchases')
            ->selectRaw('COALESCE(SUM(impressions), 0) AS impressions')
            ->selectRaw('COALESCE(SUM(reach), 0) AS reach')
            ->selectRaw('COALESCE(SUM(clicks), 0) AS clicks')
            ->selectRaw('COALESCE(SUM(inline_link_clicks), 0) AS inline_link_clicks')
            ->selectRaw('COALESCE(SUM(add_to_cart), 0) AS add_to_cart')
            ->selectRaw('COALESCE(SUM(initiate_checkout), 0) AS initiate_checkout')
            ->first();

        $spend = (float) ($row?->spend ?? 0);
        $purchaseValue = (float) ($row?->purchase_value ?? 0);
        $purchases = (float) ($row?->purchases ?? 0);
        $impressions = (int) ($row?->impressions ?? 0);
        $reach = (int) ($row?->reach ?? 0);
        $clicks = (int) ($row?->clicks ?? 0);
        $linkClicks = (int) ($row?->inline_link_clicks ?? 0);
        $addToCart = (float) ($row?->add_to_cart ?? 0);
        $initiateCheckout = (float) ($row?->initiate_checkout ?? 0);

        return [
            'spend' => round($spend, 2),
            'purchase_value' => round($purchaseValue, 2),
            'purchases' => round($purchases, 2),
            'impressions' => $impressions,
            'reach' => $reach,
            'clicks' => $clicks,
            'inline_link_clicks' => $linkClicks,
            'add_to_cart' => round($addToCart, 2),
            'initiate_checkout' => round($initiateCheckout, 2),
            'roas' => $this->ratio($purchaseValue, $spend),
            'ctr' => $this->percentage($clicks, $impressions),
            'cpc' => $this->ratio($spend, $clicks),
            'cpm' => $impressions > 0 ? round(($spend / $impressions) * 1000, 2) : null,
            'cost_per_purchase' => $this->ratio($spend, $purchases),
            'cost_per_add_to_cart' => $this->ratio($spend, $addToCart),
            'cost_per_checkout' => $this->ratio($spend, $initiateCheckout),
        ];
    }

    /** @return list<array<string, float|int|string|null>> */
    private function daily(Store $store, CarbonImmutable $from, CarbonImmutable $to, string $account): array
    {
        return $this->query($store, $from, $to, $account)
            ->selectRaw('date_start AS metric_date')
            ->selectRaw('COALESCE(SUM(spend), 0) AS spend')
            ->selectRaw('COALESCE(SUM(purchase_value), 0) AS purchase_value')
            ->selectRaw('COALESCE(SUM(purchases), 0) AS purchases')
            ->selectRaw('COALESCE(SUM(impressions), 0) AS impressions')
            ->selectRaw('COALESCE(SUM(clicks), 0) AS clicks')
            ->groupBy('date_start')
            ->orderBy('date_start')
            ->get()
            ->map(function (MetaAdInsight $row): array {
                $spend = (float) $row->spend;
                $purchaseValue = (float) $row->purchase_value;
                $impressions = (int) $row->impressions;
                $clicks = (int) $row->clicks;

                return [
                    'date' => CarbonImmutable::parse($row->metric_date)->toDateString(),
                    'spend' => round($spend, 2),
                    'purchase_value' => round($purchaseValue, 2),
                    'purchases' => round((float) $row->purchases, 2),
                    'impressions' => $impressions,
                    'clicks' => $clicks,
                    'roas' => $this->ratio($purchaseValue, $spend),
                    'ctr' => $this->percentage($clicks, $impressions),
                    'cpc' => $this->ratio($spend, $clicks),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, array{
     *     id: string, name: string, spend: float, purchase_value: float, roas: float|null,
     *     impressions: int, reach: int, clicks: int, ctr: float|null, cpc: float|null,
     *     frequency: float|null, purchases: float
     * }>
     */
    private function campaignPerformance(Store $store, CarbonImmutable $from, CarbonImmutable $to, string $account): Collection
    {
        $dailyQuery = MetaAdInsight::query()
            ->forOrganization((int) $store->organization_id)
            ->forStore((int) $store->getKey())
            ->where('level', 'campaign')
            ->where('granularity', 'day')
            ->whereDate('date_start', '>=', $from->toDateString())
            ->whereDate('date_start', '<=', $to->toDateString())
            ->when(
                $account !== 'all',
                fn (Builder $query): Builder => $query->whereIn(
                    'account_external_id',
                    $this->accountExternalIds($account),
                ),
            );
        $periodQuery = MetaAdInsight::query()
            ->forOrganization((int) $store->organization_id)
            ->forStore((int) $store->getKey())
            ->where('level', 'campaign')
            ->where('granularity', 'period')
            ->whereDate('date_start', $from->toDateString())
            ->whereDate('date_stop', $to->toDateString())
            ->when(
                $account !== 'all',
                fn (Builder $query): Builder => $query->whereIn(
                    'account_external_id',
                    $this->accountExternalIds($account),
                ),
            );

        // A user may have refreshed one account and then switched the filter to
        // "all". Do not mistake that partial snapshot for an exact all-account
        // aggregate: every account represented by the daily data must be
        // covered by the period snapshot before it is preferred.
        $dailyAccountIds = (clone $dailyQuery)
            ->whereNotNull('meta_ad_account_id')
            ->distinct()
            ->pluck('meta_ad_account_id');
        $periodAccountIds = (clone $periodQuery)
            ->whereNotNull('meta_ad_account_id')
            ->distinct()
            ->pluck('meta_ad_account_id');
        $usesExactPeriod = $periodAccountIds->isNotEmpty()
            && ($dailyAccountIds->isEmpty() || $dailyAccountIds->diff($periodAccountIds)->isEmpty());
        $query = $usesExactPeriod
            ? $periodQuery
            : $dailyQuery;

        return $query
            ->selectRaw('entity_id AS campaign_id')
            ->selectRaw('MAX(campaign_name) AS campaign_name')
            ->selectRaw('COALESCE(SUM(spend), 0) AS spend')
            ->selectRaw('COALESCE(SUM(purchase_value), 0) AS purchase_value')
            ->selectRaw('COALESCE(SUM(purchases), 0) AS purchases')
            ->selectRaw('COALESCE(SUM(impressions), 0) AS impressions')
            ->selectRaw('COALESCE(SUM(reach), 0) AS reach')
            ->selectRaw('COALESCE(SUM(clicks), 0) AS clicks')
            ->selectRaw($usesExactPeriod ? 'MAX(frequency) AS frequency' : 'NULL AS frequency')
            // A historical importer stored the same account both as `123` and
            // `act_123`. Meta campaign IDs are stable, so grouping by the real
            // campaign ID prevents one campaign from being split into two rows.
            ->groupBy('entity_id')
            ->orderByDesc('spend')
            ->get()
            ->map(function (MetaAdInsight $row): array {
                $spend = (float) $row->spend;
                $purchaseValue = (float) $row->purchase_value;
                $impressions = (int) $row->impressions;
                $reach = (int) $row->reach;
                $clicks = (int) $row->clicks;

                return [
                    'id' => (string) $row->campaign_id,
                    'name' => $row->campaign_name ?: $row->campaign_id,
                    'spend' => round($spend, 2),
                    'purchase_value' => round($purchaseValue, 2),
                    'roas' => $this->ratio($purchaseValue, $spend),
                    'impressions' => $impressions,
                    'reach' => $reach,
                    'clicks' => $clicks,
                    'ctr' => $this->percentage($clicks, $impressions),
                    'cpc' => $this->ratio($spend, $clicks),
                    // Reach is not additive across days. Only a Meta aggregate
                    // for the exact selected period can provide true frequency.
                    'frequency' => $row->frequency === null
                        ? null
                        : round((float) $row->frequency, 2),
                    'purchases' => round((float) $row->purchases, 2),
                ];
            })
            ->filter(fn (array $row): bool => $row['spend'] > 0)
            ->values();
    }

    /**
     * @param  Collection<int, array<string, float|int|string|null>>  $campaigns
     * @return array{total_spend: float, items: list<array{id: string, name: string, spend: float, share: float|null}>}
     */
    private function campaignSpend(Collection $campaigns): array
    {
        $rows = $campaigns
            ->map(fn (array $campaign): array => [
                'id' => $campaign['id'],
                'name' => $campaign['name'],
                'spend' => $campaign['spend'],
            ])
            ->values();

        $totalSpend = round((float) $rows->sum('spend'), 2);

        if ($rows->count() > 6) {
            $leading = $rows->take(5)->values();
            $otherSpend = round((float) $rows->slice(5)->sum('spend'), 2);
            $rows = $leading->push([
                'id' => 'other',
                'name' => '其他广告系列',
                'spend' => $otherSpend,
            ]);
        }

        return [
            'total_spend' => $totalSpend,
            'items' => $rows
                ->map(fn (array $row): array => [
                    ...$row,
                    'share' => $this->percentage($row['spend'], $totalSpend),
                ])
                ->values()
                ->all(),
        ];
    }

    private function query(Store $store, CarbonImmutable $from, CarbonImmutable $to, string $account): Builder
    {
        return MetaAdInsight::query()
            ->forOrganization((int) $store->organization_id)
            ->forStore((int) $store->getKey())
            ->where('level', 'account')
            ->where('granularity', 'day')
            ->whereDate('date_start', '>=', $from->toDateString())
            ->whereDate('date_start', '<=', $to->toDateString())
            ->when(
                $account !== 'all',
                fn (Builder $query): Builder => $query->whereIn(
                    'account_external_id',
                    $this->accountExternalIds($account),
                ),
            );
    }

    /** @return list<string> */
    private function accountExternalIds(string $account): array
    {
        $ids = [$account];

        if (str_starts_with($account, 'act_') && strlen($account) > 4) {
            $ids[] = substr($account, 4);
        } elseif (ctype_digit($account)) {
            $ids[] = 'act_'.$account;
        }

        return array_values(array_unique($ids));
    }

    private function ratio(float|int $numerator, float|int $denominator): ?float
    {
        return $denominator > 0 ? round($numerator / $denominator, 2) : null;
    }

    private function percentage(float|int $numerator, float|int $denominator): ?float
    {
        return $denominator > 0 ? round(($numerator / $denominator) * 100, 2) : null;
    }

    private function delta(float|int|null $current, float|int|null $previous): ?float
    {
        if ($current === null || $previous === null) {
            return null;
        }
        if ((float) $previous === 0.0) {
            return (float) $current === 0.0 ? 0.0 : null;
        }

        return round((((float) $current - (float) $previous) / abs((float) $previous)) * 100, 2);
    }
}
