<?php

namespace App\Services\Advertising;

use App\Models\AdvertisingChannelAccount;
use App\Models\GoogleAdsKeywordDailyMetric;
use App\Models\GoogleAdsSearchTermDailyMetric;
use App\Models\Store;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class GoogleAdsPerformanceTableService
{
    public function __construct(private GoogleAdsDateRangeService $dateRange) {}

    /** @param array<string, mixed> $filters */
    public function forStore(Store $store, array $filters): array
    {
        [$from, $to] = $this->dateRange->resolve($store, $filters);
        $filters = [...$filters, 'date_from' => $from->toDateString(), 'date_to' => $to->toDateString()];
        $view = ($filters['view'] ?? '') === 'keywords' ? 'keywords' : 'search-terms';
        $accountId = (string) ($filters['account'] ?? '');
        $account = AdvertisingChannelAccount::query()
            ->forOrganization((int) $store->organization_id)
            ->forStore((int) $store->getKey())
            ->where('provider', 'google')
            ->when($accountId !== '', fn ($query) => $query->where('external_account_id', $accountId))
            ->orderByDesc('last_seen_at')
            ->first();

        if (! $account) {
            return $this->empty($view, $filters);
        }

        $grouped = $view === 'keywords'
            ? $this->keywordQuery($store, $account->external_account_id, $filters)
            : $this->searchTermQuery($store, $account->external_account_id, $filters);
        $sortable = $view === 'keywords'
            ? ['keyword', 'campaign_name', 'status', 'spend', 'revenue', 'roas', 'impressions', 'clicks', 'ctr', 'cpc', 'conversions']
            : ['search_term', 'matched_keyword', 'status', 'spend', 'revenue', 'roas', 'impressions', 'clicks', 'ctr', 'cpc', 'conversions'];
        $defaultSort = $view === 'keywords' ? 'spend' : 'roas';
        $sort = in_array($filters['sort'] ?? '', $sortable, true) ? (string) $filters['sort'] : $defaultSort;
        $direction = ($filters['direction'] ?? '') === 'asc' ? 'asc' : 'desc';
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(100, max(10, (int) ($filters['per_page'] ?? 25)));

        $query = DB::query()->fromSub($grouped, 'performance_rows');
        $query->orderBy($sort, $direction)->orderBy($view === 'keywords' ? 'keyword' : 'search_term');
        /** @var LengthAwarePaginator $paginator */
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return [
            'schema' => 'google-ads-performance-table-v1',
            'view' => $view,
            'filters' => [
                'account' => $account->external_account_id,
                'date_from' => $filters['date_from'],
                'date_to' => $filters['date_to'],
                'search' => trim((string) ($filters['search'] ?? '')),
                'sort' => $sort,
                'direction' => $direction,
            ],
            'rows' => collect($paginator->items())->map(fn ($row): array => $this->row((array) $row))->values()->all(),
            'pagination' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ];
    }

    /** @param array<string, mixed> $filters */
    private function searchTermQuery(Store $store, string $externalAccountId, array $filters): Builder
    {
        $query = GoogleAdsSearchTermDailyMetric::query()
            ->forOrganization((int) $store->organization_id)
            ->forStore((int) $store->getKey())
            ->where('external_account_id', $externalAccountId)
            ->whereBetween('metric_date', [$filters['date_from'], $filters['date_to']]);
        // Google applies the period conversion-value filter to each resource
        // (campaign/ad group/keyword), before Macfox merges the same term across
        // resources. Keep every day of qualifying resources, but do not add
        // spend from another campaign that never converted in this period.
        $convertingDimensions = (clone $query)->select('dimension_key')
            ->groupBy('dimension_key')
            ->havingRaw('SUM(revenue) > 0');
        $query->whereIn('dimension_key', $convertingDimensions);
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function ($nested) use ($search): void {
                $nested->where('search_term', 'like', "%{$search}%")
                    ->orWhere('matched_keyword', 'like', "%{$search}%");
            });
        }

        return $query->selectRaw("normalized_search_term AS row_key, MAX(search_term) AS search_term, COALESCE(MAX(CASE WHEN UPPER(source_type) = 'STANDARD' THEN NULLIF(matched_keyword, '') END), MAX(COALESCE(matched_keyword, ''))) AS matched_keyword, COALESCE(MAX(CASE WHEN UPPER(source_type) = 'STANDARD' THEN NULLIF(match_type, '') END), MAX(COALESCE(match_type, ''))) AS match_type, COALESCE(MAX(CASE WHEN UPPER(source_type) = 'STANDARD' THEN NULLIF(status, '') END), MAX(COALESCE(status, ''))) AS status, SUM(spend) AS spend, SUM(revenue) AS revenue, CASE WHEN SUM(spend) > 0 THEN SUM(revenue) * 1.0 / SUM(spend) ELSE 0 END AS roas, SUM(impressions) AS impressions, SUM(clicks) AS clicks, CASE WHEN SUM(impressions) > 0 THEN SUM(clicks) * 100.0 / SUM(impressions) ELSE 0 END AS ctr, CASE WHEN SUM(clicks) > 0 THEN SUM(spend) * 1.0 / SUM(clicks) ELSE 0 END AS cpc, SUM(conversions) AS conversions")
            ->groupBy('normalized_search_term')
            ->havingRaw('SUM(revenue) > 0')
            ->toBase();
    }

    /** @param array<string, mixed> $filters */
    private function keywordQuery(Store $store, string $externalAccountId, array $filters): Builder
    {
        $query = GoogleAdsKeywordDailyMetric::query()
            ->forOrganization((int) $store->organization_id)
            ->forStore((int) $store->getKey())
            ->where('external_account_id', $externalAccountId)
            ->whereBetween('metric_date', [$filters['date_from'], $filters['date_to']]);
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function ($nested) use ($search): void {
                $nested->where('keyword', 'like', "%{$search}%")
                    ->orWhere('ad_group_name', 'like', "%{$search}%")
                    ->orWhere('campaign_name', 'like', "%{$search}%");
            });
        }

        return $query->selectRaw("dimension_key AS row_key, MAX(keyword) AS keyword, MAX(COALESCE(match_type, '')) AS match_type, MAX(COALESCE(ad_group_name, '')) AS ad_group_name, MAX(COALESCE(campaign_name, '')) AS campaign_name, MAX(COALESCE(status, '')) AS status, SUM(spend) AS spend, SUM(revenue) AS revenue, CASE WHEN SUM(spend) > 0 THEN SUM(revenue) * 1.0 / SUM(spend) ELSE 0 END AS roas, SUM(impressions) AS impressions, SUM(clicks) AS clicks, CASE WHEN SUM(impressions) > 0 THEN SUM(clicks) * 100.0 / SUM(impressions) ELSE 0 END AS ctr, CASE WHEN SUM(clicks) > 0 THEN SUM(spend) * 1.0 / SUM(clicks) ELSE 0 END AS cpc, SUM(conversions) AS conversions")
            ->groupBy('dimension_key')
            ->havingRaw('SUM(spend) > 0')
            ->toBase();
    }

    /** @param array<string, mixed> $row */
    private function row(array $row): array
    {
        foreach (['spend', 'revenue', 'roas', 'ctr', 'cpc', 'conversions'] as $key) {
            $row[$key] = round((float) ($row[$key] ?? 0), 4);
        }
        foreach (['impressions', 'clicks'] as $key) {
            $row[$key] = (int) ($row[$key] ?? 0);
        }
        $row['status_label'] = $this->status((string) ($row['status'] ?? ''));
        $row['match_type_label'] = $this->matchType((string) ($row['match_type'] ?? ''));

        return $row;
    }

    private function status(string $status): string
    {
        return match (strtoupper($status)) {
            'ENABLED' => '启用',
            'ADDED' => '已添加',
            'PAUSED' => '已暂停',
            'EXCLUDED' => '已排除',
            'REMOVED' => '已移除',
            'NONE' => '未处理',
            'PERFORMANCE_MAX' => '效果最大化',
            default => $status !== '' ? $status : '—',
        };
    }

    private function matchType(string $matchType): string
    {
        return match (strtoupper($matchType)) {
            'EXACT' => '精确',
            'PHRASE' => '词组',
            'BROAD' => '广泛',
            'PERFORMANCE_MAX' => '效果最大化',
            default => $matchType !== '' ? $matchType : '—',
        };
    }

    /** @param array<string, mixed> $filters */
    private function empty(string $view, array $filters): array
    {
        return [
            'schema' => 'google-ads-performance-table-v1',
            'view' => $view,
            'filters' => [
                'account' => null,
                'date_from' => (string) ($filters['date_from'] ?? ''),
                'date_to' => (string) ($filters['date_to'] ?? ''),
                'search' => trim((string) ($filters['search'] ?? '')),
                'sort' => (string) ($filters['sort'] ?? 'roas'),
                'direction' => (string) ($filters['direction'] ?? 'desc'),
            ],
            'rows' => [],
            'pagination' => ['page' => 1, 'per_page' => 25, 'total' => 0, 'last_page' => 1],
        ];
    }
}
