<?php

namespace App\Services\SeoAnalytics;

use Closure;
use Illuminate\Support\Facades\DB;

class GscDimensionBackfillService
{
    public function __construct(private SeoAnalyticsCacheVersionService $cacheVersion) {}

    /**
     * GSC facts are normalized at write time and the compact schema no longer
     * contains legacy labels to backfill. Keep this operation as an idempotent
     * verifier for existing deployment scripts and operator workflows.
     *
     * @param  Closure(string, int, int): void|null  $progress
     * @return array<string, array<string, int>>
     */
    public function backfill(?int $storeId = null, int $chunkSize = 50000, ?Closure $progress = null): array
    {
        $status = $this->status($storeId);
        foreach ($status as $type => $row) {
            $progress?->__invoke($type, $row['linked_rows'], $row['fact_rows']);
        }

        $storeIds = $storeId !== null
            ? collect([$storeId])
            : DB::table('seo_gsc_page_daily_metrics')->distinct()->pluck('store_id')
                ->merge(DB::table('seo_gsc_query_daily_metrics')->distinct()->pluck('store_id'))->unique();
        $storeIds->each(fn ($id): int => $this->cacheVersion->bump((int) $id));

        return $status;
    }

    /** @return array<string, array<string, int>> */
    public function status(?int $storeId = null): array
    {
        return collect(['pages', 'queries'])->mapWithKeys(function (string $type) use ($storeId): array {
            $definition = $this->definition($type);
            $facts = DB::table($definition['fact_table'])
                ->when($storeId !== null, fn ($query) => $query->where('store_id', $storeId));
            $factRows = (clone $facts)->count();
            $linkedRows = (clone $facts)->whereNotNull($definition['id_column'])->count();
            $dimensionRows = DB::table($definition['dimension_table'])
                ->when($storeId !== null, fn ($query) => $query->where('store_id', $storeId))->count();

            return [$type => [
                'fact_rows' => $factRows,
                'linked_rows' => $linkedRows,
                'missing_rows' => $factRows - $linkedRows,
                'dimension_rows' => $dimensionRows,
            ]];
        })->all();
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
