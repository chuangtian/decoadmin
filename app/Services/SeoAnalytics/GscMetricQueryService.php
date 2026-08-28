<?php

namespace App\Services\SeoAnalytics;

use App\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
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

    /**
     * @param  list<string>  $segments
     * @param  list<int>  $dimensionIds
     */
    public function factAggregate(
        Store $store,
        string $type,
        array $segments,
        CarbonImmutable $from,
        CarbonImmutable $to,
        string $search = '',
        array $dimensionIds = [],
        bool $blogOnly = false,
    ): Builder {
        $definition = $this->definition($type);
        $facts = DB::table($definition['fact_table'].' as metric')
            ->forceIndex($definition['covering_index'])
            ->where('metric.organization_id', $store->organization_id)
            ->where('metric.store_id', $store->getKey())
            ->whereIn('metric.segment', $segments)
            ->whereBetween('metric.metric_date', [
                $from->startOfDay()->toDateTimeString(),
                $to->endOfDay()->toDateTimeString(),
            ]);

        if ($dimensionIds !== []) {
            $facts->whereIn('metric.'.$definition['id_column'], $dimensionIds);
        }
        if ($search !== '' || ($blogOnly && $definition['dimension_table'] === 'seo_gsc_pages')) {
            $dimensions = DB::table($definition['dimension_table'])
                ->select('id')
                ->where('organization_id', $store->organization_id)
                ->where('store_id', $store->getKey());
            if ($search !== '') {
                $dimensions->where($definition['label_column'], 'like', '%'.$search.'%');
            }
            if ($blogOnly && $definition['dimension_table'] === 'seo_gsc_pages') {
                $dimensions->where('is_blog', true);
            }
            $facts->whereIn('metric.'.$definition['id_column'], $dimensions);
        }

        return $facts
            ->selectRaw('metric.'.$definition['id_column'].' dimension_id, SUM(metric.clicks) clicks, SUM(metric.impressions) impressions')
            ->selectRaw('CASE WHEN SUM(metric.impressions) > 0 THEN SUM(metric.clicks) * 100.0 / SUM(metric.impressions) ELSE 0 END ctr')
            ->selectRaw('CASE WHEN SUM(metric.impressions) > 0 THEN SUM(metric.average_position * metric.impressions) / SUM(metric.impressions) ELSE 0 END position')
            ->groupBy('metric.'.$definition['id_column']);
    }

    /** @param Collection<int, object> $rows @return Collection<int, object> */
    public function hydrateDimensions(string $type, Collection $rows): Collection
    {
        if ($rows->isEmpty()) {
            return $rows;
        }

        $definition = $this->definition($type);
        $dimensions = DB::table($definition['dimension_table'])
            ->whereIn('id', $rows->pluck('dimension_id')->map(fn ($id): int => (int) $id)->all())
            ->get(['id', $definition['hash_column'], $definition['label_column']])->keyBy('id');

        return $rows->map(function (object $row) use ($dimensions, $definition): object {
            $dimension = $dimensions->get((int) $row->dimension_id);
            $row->hash = (string) ($dimension->{$definition['hash_column']} ?? '');
            $row->label = (string) ($dimension->{$definition['label_column']} ?? '');

            return $row;
        });
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
        $dimensionIds = [];
        if ($hashes !== []) {
            $dimensionIds = DB::table($definition['dimension_table'])
                ->where('organization_id', $store->organization_id)->where('store_id', $store->getKey())
                ->whereIn($definition['hash_column'], $hashes)->pluck('id')->map(fn ($id): int => (int) $id)->all();
            if ($dimensionIds === []) {
                $dimensionIds = [-1];
            }
        }
        $aggregated = $this->factAggregate($store, $definition['type'], $segments, $from, $to, $search, $dimensionIds, $blogOnly);

        return DB::query()->fromSub($aggregated, 'aggregated')
            ->join($definition['dimension_table'].' as dimension', 'dimension.id', '=', 'aggregated.dimension_id')
            ->selectRaw('dimension.'.$definition['hash_column'].' hash, dimension.'.$definition['label_column'].' label')
            ->addSelect('aggregated.clicks', 'aggregated.impressions', 'aggregated.ctr', 'aggregated.position');
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

    /** @return array{type: string, fact_table: string, dimension_table: string, id_column: string, hash_column: string, label_column: string, covering_index: string} */
    private function definition(string $type): array
    {
        return match ($type) {
            'pages' => [
                'type' => 'pages',
                'fact_table' => 'seo_gsc_page_daily_metrics',
                'dimension_table' => 'seo_gsc_pages',
                'id_column' => 'page_id',
                'hash_column' => 'page_hash',
                'label_column' => 'page',
                'covering_index' => 'seo_gsc_page_detail_covering_index',
            ],
            'queries' => [
                'type' => 'queries',
                'fact_table' => 'seo_gsc_query_daily_metrics',
                'dimension_table' => 'seo_gsc_queries',
                'id_column' => 'query_id',
                'hash_column' => 'query_hash',
                'label_column' => 'query',
                'covering_index' => 'seo_gsc_query_detail_covering_index',
            ],
            default => throw new InvalidArgumentException("不支持的 GSC 维度类型：{$type}"),
        };
    }
}
