<?php

namespace App\Services\SeoAnalytics;

use Closure;
use Illuminate\Support\Facades\DB;

class GscDimensionBackfillService
{
    public function __construct(
        private GscDimensionRegistry $registry,
        private SeoAnalyticsCacheVersionService $cacheVersion,
    ) {}

    /**
     * @param  Closure(string, int, int): void|null  $progress
     * @return array<string, array<string, int>>
     */
    public function backfill(?int $storeId = null, int $chunkSize = 50000, ?Closure $progress = null): array
    {
        $chunkSize = min(250000, max(1000, $chunkSize));

        if (DB::getDriverName() === 'mysql') {
            $this->populateMysqlDimensions('pages', $storeId);
            $this->populateMysqlDimensions('queries', $storeId);
            $this->linkMysqlFacts('pages', $storeId, $chunkSize, $progress);
            $this->linkMysqlFacts('queries', $storeId, $chunkSize, $progress);
        } else {
            $this->backfillPortable('pages', $storeId, $chunkSize, $progress);
            $this->backfillPortable('queries', $storeId, $chunkSize, $progress);
        }

        $storeIds = $storeId !== null
            ? collect([$storeId])
            : DB::table('seo_gsc_page_daily_metrics')->distinct()->pluck('store_id')
                ->merge(DB::table('seo_gsc_query_daily_metrics')->distinct()->pluck('store_id'))->unique();
        $storeIds->each(fn ($id): int => $this->cacheVersion->bump((int) $id));

        return $this->status($storeId);
    }

    /** @return array<string, array<string, int>> */
    public function status(?int $storeId = null): array
    {
        return collect(['pages', 'queries'])->mapWithKeys(function (string $type) use ($storeId): array {
            $definition = $this->definition($type);
            $facts = DB::table($definition['fact_table'])->when($storeId !== null, fn ($query) => $query->where('store_id', $storeId));
            $factRows = (clone $facts)->count();
            $linkedRows = (clone $facts)->whereNotNull($definition['id_column'])->count();
            $dimensionRows = DB::table($definition['dimension_table'])->when($storeId !== null, fn ($query) => $query->where('store_id', $storeId))->count();

            return [$type => [
                'fact_rows' => $factRows,
                'linked_rows' => $linkedRows,
                'missing_rows' => $factRows - $linkedRows,
                'dimension_rows' => $dimensionRows,
            ]];
        })->all();
    }

    private function populateMysqlDimensions(string $type, ?int $storeId): void
    {
        $definition = $this->definition($type);
        $where = $storeId === null ? '' : ' WHERE store_id = ?';
        $bindings = $storeId === null ? [] : [$storeId];
        $blogColumn = $type === 'pages'
            ? ", MAX(CASE WHEN LOWER({$definition['label_column']}) LIKE '%/blogs/%' THEN 1 ELSE 0 END)"
            : '';
        $columns = $type === 'pages'
            ? 'organization_id, store_id, page_hash, page, is_blog, created_at, updated_at'
            : 'organization_id, store_id, query_hash, query, created_at, updated_at';

        DB::insert(
            "INSERT IGNORE INTO {$definition['dimension_table']} ({$columns})
             SELECT organization_id, store_id, {$definition['hash_column']}, MAX({$definition['label_column']}){$blogColumn}, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
             FROM {$definition['fact_table']}{$where}
             GROUP BY organization_id, store_id, {$definition['hash_column']}",
            $bindings,
        );
    }

    /** @param Closure(string, int, int): void|null $progress */
    private function linkMysqlFacts(string $type, ?int $storeId, int $chunkSize, ?Closure $progress): void
    {
        $definition = $this->definition($type);
        $missing = DB::table($definition['fact_table'])->whereNull($definition['id_column'])
            ->when($storeId !== null, fn ($query) => $query->where('store_id', $storeId));
        $total = (clone $missing)->count();
        $bounds = (clone $missing)->selectRaw('MIN(id) min_id, MAX(id) max_id')->first();
        if ($bounds?->min_id === null || $bounds?->max_id === null) {
            return;
        }

        $minimum = (int) $bounds->min_id;
        $maximum = (int) $bounds->max_id;
        $processed = 0;
        for ($start = $minimum; $start <= $maximum; $start += $chunkSize) {
            $end = min($maximum, $start + $chunkSize - 1);
            $storeWhere = $storeId === null ? '' : ' AND metric.store_id = ?';
            $bindings = $storeId === null ? [$start, $end] : [$start, $end, $storeId];
            $updated = DB::update(
                "UPDATE {$definition['fact_table']} metric
                 INNER JOIN {$definition['dimension_table']} dimension
                    ON dimension.store_id = metric.store_id
                   AND dimension.{$definition['hash_column']} = metric.{$definition['hash_column']}
                 SET metric.{$definition['id_column']} = dimension.id
                 WHERE metric.id BETWEEN ? AND ?
                   AND metric.{$definition['id_column']} IS NULL{$storeWhere}",
                $bindings,
            );
            $processed += $updated;
            $progress?->__invoke($type, $processed, $total);
        }
    }

    /** @param Closure(string, int, int): void|null $progress */
    private function backfillPortable(string $type, ?int $storeId, int $chunkSize, ?Closure $progress): void
    {
        $definition = $this->definition($type);
        $total = DB::table($definition['fact_table'])->when($storeId !== null, fn ($query) => $query->where('store_id', $storeId))->count();
        $processed = 0;

        DB::table($definition['fact_table'])->when($storeId !== null, fn ($query) => $query->where('store_id', $storeId))
            ->orderBy('id')->chunkById($chunkSize, function ($rows) use ($type, $definition, &$processed, $total, $progress): void {
                $rows->groupBy('store_id')->each(function ($storeRows) use ($type, $definition): void {
                    $records = $storeRows->map(fn ($row): array => (array) $row)->all();
                    $records = $type === 'pages'
                        ? $this->registry->attachPageIds($records)
                        : $this->registry->attachQueryIds($records);
                    foreach ($records as $record) {
                        DB::table($definition['fact_table'])->where('id', $record['id'])
                            ->whereNull($definition['id_column'])->update([$definition['id_column'] => $record[$definition['id_column']]]);
                    }
                });
                $processed += $rows->count();
                $progress?->__invoke($type, $processed, $total);
            });
    }

    /** @return array{fact_table: string, dimension_table: string, id_column: string, hash_column: string, label_column: string} */
    private function definition(string $type): array
    {
        return $type === 'pages'
            ? [
                'fact_table' => 'seo_gsc_page_daily_metrics', 'dimension_table' => 'seo_gsc_pages',
                'id_column' => 'page_id', 'hash_column' => 'page_hash', 'label_column' => 'page',
            ]
            : [
                'fact_table' => 'seo_gsc_query_daily_metrics', 'dimension_table' => 'seo_gsc_queries',
                'id_column' => 'query_id', 'hash_column' => 'query_hash', 'label_column' => 'query',
            ];
    }
}
