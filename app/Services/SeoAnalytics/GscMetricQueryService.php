<?php

namespace App\Services\SeoAnalytics;

use App\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class GscMetricQueryService
{
    /**
     * @param  list<string>  $segments
     * @param  list<string>  $hashes
     */
    public function aggregate(
        Store $store,
        string $type,
        array $segments,
        CarbonImmutable $from,
        CarbonImmutable $to,
        string $search = '',
        array $hashes = [],
        bool $blogOnly = false,
    ): Builder {
        $definition = $this->definition($type);

        return $this->dimensionsReady($store, $type)
            ? $this->optimizedAggregate($store, $definition, $segments, $from, $to, $search, $hashes, $blogOnly)
            : $this->legacyAggregate($store, $definition, $segments, $from, $to, $search, $hashes, $blogOnly);
    }

    public function dimensionsReady(Store $store, string $type): bool
    {
        $definition = $this->definition($type);

        return ! DB::table($definition['fact_table'])
            ->where('organization_id', $store->organization_id)
            ->where('store_id', $store->getKey())
            ->whereNull($definition['id_column'])
            ->exists();
    }

    /** @param array<string, string> $definition @param list<string> $segments @param list<string> $hashes */
    private function optimizedAggregate(
        Store $store,
        array $definition,
        array $segments,
        CarbonImmutable $from,
        CarbonImmutable $to,
        string $search,
        array $hashes,
        bool $blogOnly,
    ): Builder {
        $query = DB::table($definition['fact_table'].' as metric')
            ->join($definition['dimension_table'].' as dimension', 'dimension.id', '=', 'metric.'.$definition['id_column'])
            ->where('metric.organization_id', $store->organization_id)
            ->where('metric.store_id', $store->getKey())
            ->whereIn('metric.segment', $segments)
            ->whereBetween('metric.metric_date', [
                $from->startOfDay()->toDateTimeString(),
                $to->endOfDay()->toDateTimeString(),
            ]);

        if ($search !== '') {
            $query->where('dimension.'.$definition['label_column'], 'like', '%'.$search.'%');
        }
        if ($hashes !== []) {
            $query->whereIn('dimension.'.$definition['hash_column'], $hashes);
        }
        if ($blogOnly && $definition['dimension_table'] === 'seo_gsc_pages') {
            $query->where('dimension.is_blog', true);
        }

        return $query
            ->selectRaw('dimension.'.$definition['hash_column'].' hash, MAX(dimension.'.$definition['label_column'].') label, SUM(metric.clicks) clicks, SUM(metric.impressions) impressions')
            ->selectRaw('CASE WHEN SUM(metric.impressions) > 0 THEN SUM(metric.clicks) * 100.0 / SUM(metric.impressions) ELSE 0 END ctr')
            ->selectRaw('CASE WHEN SUM(metric.impressions) > 0 THEN SUM(metric.average_position * metric.impressions) / SUM(metric.impressions) ELSE 0 END position')
            ->groupBy('metric.'.$definition['id_column'], 'dimension.'.$definition['hash_column']);
    }

    /** @param array<string, string> $definition @param list<string> $segments @param list<string> $hashes */
    private function legacyAggregate(
        Store $store,
        array $definition,
        array $segments,
        CarbonImmutable $from,
        CarbonImmutable $to,
        string $search,
        array $hashes,
        bool $blogOnly,
    ): Builder {
        $query = DB::table($definition['fact_table'].' as metric')
            ->where('metric.organization_id', $store->organization_id)
            ->where('metric.store_id', $store->getKey())
            ->whereIn('metric.segment', $segments)
            ->whereBetween('metric.metric_date', [
                $from->startOfDay()->toDateTimeString(),
                $to->endOfDay()->toDateTimeString(),
            ]);

        if ($search !== '') {
            $query->where('metric.'.$definition['label_column'], 'like', '%'.$search.'%');
        }
        if ($hashes !== []) {
            $query->whereIn('metric.'.$definition['hash_column'], $hashes);
        }
        if ($blogOnly && $definition['fact_table'] === 'seo_gsc_page_daily_metrics') {
            $query->where('metric.page', 'like', '%/blogs/%');
        }

        return $query
            ->selectRaw('metric.'.$definition['hash_column'].' hash, MAX(metric.'.$definition['label_column'].') label, SUM(metric.clicks) clicks, SUM(metric.impressions) impressions')
            ->selectRaw('CASE WHEN SUM(metric.impressions) > 0 THEN SUM(metric.clicks) * 100.0 / SUM(metric.impressions) ELSE 0 END ctr')
            ->selectRaw('CASE WHEN SUM(metric.impressions) > 0 THEN SUM(metric.average_position * metric.impressions) / SUM(metric.impressions) ELSE 0 END position')
            ->groupBy('metric.'.$definition['hash_column']);
    }

    /** @return array{fact_table: string, dimension_table: string, id_column: string, hash_column: string, label_column: string} */
    private function definition(string $type): array
    {
        return match ($type) {
            'pages' => [
                'fact_table' => 'seo_gsc_page_daily_metrics',
                'dimension_table' => 'seo_gsc_pages',
                'id_column' => 'page_id',
                'hash_column' => 'page_hash',
                'label_column' => 'page',
            ],
            'queries' => [
                'fact_table' => 'seo_gsc_query_daily_metrics',
                'dimension_table' => 'seo_gsc_queries',
                'id_column' => 'query_id',
                'hash_column' => 'query_hash',
                'label_column' => 'query',
            ],
            default => throw new InvalidArgumentException("不支持的 GSC 维度类型：{$type}"),
        };
    }
}
