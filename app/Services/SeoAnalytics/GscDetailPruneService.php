<?php

namespace App\Services\SeoAnalytics;

use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class GscDetailPruneService
{
    public function __construct(private SeoAnalyticsCacheVersionService $cacheVersion) {}

    /** @return array<string, array<string, int>> */
    public function estimate(?int $storeId = null, int $minimumImpressions = GscDetailRetentionPolicy::MIN_IMPRESSIONS): array
    {
        $minimumImpressions = max(1, $minimumImpressions);

        return collect(['pages', 'queries'])->mapWithKeys(function (string $type) use ($storeId, $minimumImpressions): array {
            $definition = $this->definition($type);
            $facts = $this->facts($definition['fact_table'], $storeId);
            $factRows = (clone $facts)->count();
            $matchedRows = $this->lowValue($definition['fact_table'], $storeId, $minimumImpressions)->count();

            return [$type => [
                'fact_rows' => $factRows,
                'matched_rows' => $matchedRows,
                'retained_rows' => $factRows - $matchedRows,
            ]];
        })->all();
    }

    /**
     * @param  Closure(string, int, int): void|null  $progress
     * @return array<string, array<string, int>>
     */
    public function prune(
        ?int $storeId = null,
        int $minimumImpressions = GscDetailRetentionPolicy::MIN_IMPRESSIONS,
        int $chunkSize = 50000,
        ?Closure $progress = null,
    ): array {
        $minimumImpressions = max(1, $minimumImpressions);
        $chunkSize = min(250000, max(1000, $chunkSize));
        $storeIds = $this->storeIds($storeId);

        $result = collect(['pages', 'queries'])->mapWithKeys(function (string $type) use (
            $storeId, $minimumImpressions, $chunkSize, $progress,
        ): array {
            $definition = $this->definition($type);
            $facts = $this->facts($definition['fact_table'], $storeId);
            $factRows = (clone $facts)->count();
            $candidates = $this->lowValue($definition['fact_table'], $storeId, $minimumImpressions);
            $matchedRows = (clone $candidates)->count();
            $bounds = (clone $candidates)->selectRaw('MIN(id) min_id, MAX(id) max_id')->first();
            $deletedRows = 0;

            if ($bounds?->min_id !== null && $bounds?->max_id !== null) {
                $minimum = (int) $bounds->min_id;
                $maximum = (int) $bounds->max_id;
                for ($start = $minimum; $start <= $maximum; $start += $chunkSize) {
                    $end = min($maximum, $start + $chunkSize - 1);
                    $deletedRows += (clone $candidates)->whereBetween('id', [$start, $end])->delete();
                    $progress?->__invoke($type, $deletedRows, $matchedRows);
                }
            }

            $orphanDimensions = DB::table($definition['dimension_table'])
                ->when($storeId !== null, fn (Builder $query) => $query->where('store_id', $storeId))
                ->whereNotExists(function (Builder $query) use ($definition): void {
                    $query->selectRaw('1')->from($definition['fact_table'])
                        ->whereColumn(
                            $definition['fact_table'].'.'.$definition['id_column'],
                            $definition['dimension_table'].'.id',
                        );
                })->delete();

            return [$type => [
                'fact_rows' => $factRows,
                'matched_rows' => $matchedRows,
                'deleted_rows' => $deletedRows,
                'retained_rows' => $factRows - $deletedRows,
                'orphan_dimensions_deleted' => $orphanDimensions,
            ]];
        })->all();

        $redundantWebRows = DB::table('seo_gsc_search_type_daily_metrics')->where('search_type', 'web')
            ->when($storeId !== null, fn (Builder $query) => $query->where('store_id', $storeId))->delete();
        $emptyBreakdownRows = DB::table('seo_gsc_breakdown_daily_metrics')->where('clicks', 0)->where('impressions', 0)
            ->when($storeId !== null, fn (Builder $query) => $query->where('store_id', $storeId))->delete();
        $result['redundant'] = [
            'web_search_type_rows_deleted' => $redundantWebRows,
            'empty_breakdown_rows_deleted' => $emptyBreakdownRows,
        ];

        $storeIds->each(fn ($id): int => $this->cacheVersion->bump((int) $id));

        return $result;
    }

    private function facts(string $table, ?int $storeId): Builder
    {
        return DB::table($table)->when($storeId !== null, fn (Builder $query) => $query->where('store_id', $storeId));
    }

    private function lowValue(string $table, ?int $storeId, int $minimumImpressions): Builder
    {
        return $this->facts($table, $storeId)->where('clicks', 0)->where('impressions', '<', $minimumImpressions);
    }

    /** @return Collection<int, int> */
    private function storeIds(?int $storeId): Collection
    {
        if ($storeId !== null) {
            return collect([$storeId]);
        }

        return DB::table('seo_gsc_page_daily_metrics')->distinct()->pluck('store_id')
            ->merge(DB::table('seo_gsc_query_daily_metrics')->distinct()->pluck('store_id'))
            ->merge(DB::table('seo_gsc_search_type_daily_metrics')->distinct()->pluck('store_id'))
            ->merge(DB::table('seo_gsc_breakdown_daily_metrics')->distinct()->pluck('store_id'))
            ->unique()->values();
    }

    /** @return array{fact_table: string, dimension_table: string, id_column: string} */
    private function definition(string $type): array
    {
        return $type === 'pages'
            ? [
                'fact_table' => 'seo_gsc_page_daily_metrics',
                'dimension_table' => 'seo_gsc_pages',
                'id_column' => 'page_id',
            ]
            : [
                'fact_table' => 'seo_gsc_query_daily_metrics',
                'dimension_table' => 'seo_gsc_queries',
                'id_column' => 'query_id',
            ];
    }
}
