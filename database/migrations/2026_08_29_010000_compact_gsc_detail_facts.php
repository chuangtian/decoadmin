<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->backfillDimensions();

        foreach ([
            ['seo_gsc_page_daily_metrics', 'page_id'],
            ['seo_gsc_query_daily_metrics', 'query_id'],
        ] as [$table, $idColumn]) {
            if (DB::table($table)->whereNull($idColumn)->exists()) {
                throw new RuntimeException("{$table} 仍有未关联的 GSC 维度，已停止压缩迁移。");
            }
        }

        $this->compactFactTable(
            'seo_gsc_page_daily_metrics',
            'page_id',
            ['page_hash', 'page'],
            'seo_gsc_page_store_date_segment_hash_unique',
            'seo_gsc_page_store_date_segment_dimension_unique',
            'seo_gsc_page_segment_date_index',
            'seo_gsc_page_store_dimension_index',
        );
        $this->compactFactTable(
            'seo_gsc_query_daily_metrics',
            'query_id',
            ['query_hash', 'query'],
            'seo_gsc_query_store_date_segment_hash_unique',
            'seo_gsc_query_store_date_segment_dimension_unique',
            'seo_gsc_query_segment_date_index',
            'seo_gsc_query_store_dimension_index',
        );

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE seo_gsc_pages MODIFY page_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL');
            DB::statement('ALTER TABLE seo_gsc_queries MODIFY query_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL');
            DB::statement('ALTER TABLE seo_gsc_page_daily_metrics FORCE');
            DB::statement('ALTER TABLE seo_gsc_query_daily_metrics FORCE');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE seo_gsc_pages MODIFY page_hash CHAR(64) NOT NULL');
            DB::statement('ALTER TABLE seo_gsc_queries MODIFY query_hash CHAR(64) NOT NULL');
        }

        $this->restoreFactColumns('pages');
        $this->restoreFactColumns('queries');
    }

    private function backfillDimensions(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement(<<<'SQL'
                INSERT IGNORE INTO seo_gsc_pages
                    (organization_id, store_id, page_hash, page, is_blog, created_at, updated_at)
                SELECT organization_id, store_id, page_hash, MAX(page),
                       MAX(CASE WHEN LOWER(page) LIKE '%/blogs/%' THEN 1 ELSE 0 END),
                       CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                FROM seo_gsc_page_daily_metrics
                WHERE page_id IS NULL
                GROUP BY organization_id, store_id, page_hash
                SQL);
            DB::statement(<<<'SQL'
                UPDATE seo_gsc_page_daily_metrics metric
                INNER JOIN seo_gsc_pages dimension
                    ON dimension.store_id = metric.store_id
                   AND dimension.page_hash = metric.page_hash
                SET metric.page_id = dimension.id
                WHERE metric.page_id IS NULL
                SQL);
            DB::statement(<<<'SQL'
                INSERT IGNORE INTO seo_gsc_queries
                    (organization_id, store_id, query_hash, query, created_at, updated_at)
                SELECT organization_id, store_id, query_hash, MAX(query), CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                FROM seo_gsc_query_daily_metrics
                WHERE query_id IS NULL
                GROUP BY organization_id, store_id, query_hash
                SQL);
            DB::statement(<<<'SQL'
                UPDATE seo_gsc_query_daily_metrics metric
                INNER JOIN seo_gsc_queries dimension
                    ON dimension.store_id = metric.store_id
                   AND dimension.query_hash = metric.query_hash
                SET metric.query_id = dimension.id
                WHERE metric.query_id IS NULL
                SQL);

            return;
        }

        $this->backfillPortable('pages');
        $this->backfillPortable('queries');
    }

    private function backfillPortable(string $type): void
    {
        $isPage = $type === 'pages';
        $factTable = $isPage ? 'seo_gsc_page_daily_metrics' : 'seo_gsc_query_daily_metrics';
        $dimensionTable = $isPage ? 'seo_gsc_pages' : 'seo_gsc_queries';
        $idColumn = $isPage ? 'page_id' : 'query_id';
        $hashColumn = $isPage ? 'page_hash' : 'query_hash';
        $labelColumn = $isPage ? 'page' : 'query';

        DB::table($factTable)->whereNull($idColumn)->orderBy('id')->chunkById(1000, function ($rows) use (
            $isPage, $factTable, $dimensionTable, $idColumn, $hashColumn, $labelColumn,
        ): void {
            foreach ($rows as $row) {
                $values = [
                    'organization_id' => $row->organization_id,
                    'store_id' => $row->store_id,
                    $hashColumn => $row->{$hashColumn},
                    $labelColumn => $row->{$labelColumn},
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                if ($isPage) {
                    $values['is_blog'] = str_contains(strtolower((string) $row->{$labelColumn}), '/blogs/');
                }
                DB::table($dimensionTable)->updateOrInsert(
                    ['store_id' => $row->store_id, $hashColumn => $row->{$hashColumn}],
                    $values,
                );
                $dimensionId = DB::table($dimensionTable)
                    ->where('store_id', $row->store_id)
                    ->where($hashColumn, $row->{$hashColumn})
                    ->value('id');
                DB::table($factTable)->where('id', $row->id)->update([$idColumn => $dimensionId]);
            }
        });
    }

    /** @param list<string> $legacyColumns */
    private function compactFactTable(
        string $table,
        string $idColumn,
        array $legacyColumns,
        string $legacyUnique,
        string $dimensionUnique,
        string $segmentDateIndex,
        string $storeDimensionIndex,
    ): void {
        Schema::table($table, function (Blueprint $blueprint) use ($idColumn, $dimensionUnique): void {
            $blueprint->unique(['store_id', 'metric_date', 'segment', $idColumn], $dimensionUnique);
        });

        Schema::table($table, function (Blueprint $blueprint) use (
            $legacyUnique, $segmentDateIndex, $storeDimensionIndex,
        ): void {
            if (Schema::hasIndex($blueprint->getTable(), $legacyUnique)) {
                $blueprint->dropUnique($legacyUnique);
            }
            if (Schema::hasIndex($blueprint->getTable(), $segmentDateIndex)) {
                $blueprint->dropIndex($segmentDateIndex);
            }
            if (Schema::hasIndex($blueprint->getTable(), $storeDimensionIndex)) {
                $blueprint->dropIndex($storeDimensionIndex);
            }
        });

        Schema::table($table, function (Blueprint $blueprint) use ($legacyColumns): void {
            $blueprint->dropColumn($legacyColumns);
        });
    }

    private function restoreFactColumns(string $type): void
    {
        $isPage = $type === 'pages';
        $table = $isPage ? 'seo_gsc_page_daily_metrics' : 'seo_gsc_query_daily_metrics';
        $dimensionTable = $isPage ? 'seo_gsc_pages' : 'seo_gsc_queries';
        $idColumn = $isPage ? 'page_id' : 'query_id';
        $hashColumn = $isPage ? 'page_hash' : 'query_hash';
        $labelColumn = $isPage ? 'page' : 'query';
        $dimensionUnique = $isPage
            ? 'seo_gsc_page_store_date_segment_dimension_unique'
            : 'seo_gsc_query_store_date_segment_dimension_unique';
        $legacyUnique = $isPage
            ? 'seo_gsc_page_store_date_segment_hash_unique'
            : 'seo_gsc_query_store_date_segment_hash_unique';
        $segmentDateIndex = $isPage ? 'seo_gsc_page_segment_date_index' : 'seo_gsc_query_segment_date_index';
        $storeDimensionIndex = $isPage ? 'seo_gsc_page_store_dimension_index' : 'seo_gsc_query_store_dimension_index';

        Schema::table($table, function (Blueprint $blueprint) use ($hashColumn, $labelColumn): void {
            $blueprint->char($hashColumn, 64)->nullable();
            $blueprint->text($labelColumn)->nullable();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("UPDATE {$table} metric INNER JOIN {$dimensionTable} dimension ON dimension.id = metric.{$idColumn} SET metric.{$hashColumn} = dimension.{$hashColumn}, metric.{$labelColumn} = dimension.{$labelColumn}");
        } else {
            DB::table($table)->orderBy('id')->chunkById(1000, function ($rows) use (
                $table, $dimensionTable, $idColumn, $hashColumn, $labelColumn,
            ): void {
                foreach ($rows as $row) {
                    $dimension = DB::table($dimensionTable)->where('id', $row->{$idColumn})->first();
                    DB::table($table)->where('id', $row->id)->update([
                        $hashColumn => $dimension?->{$hashColumn},
                        $labelColumn => $dimension?->{$labelColumn},
                    ]);
                }
            });
        }

        Schema::table($table, function (Blueprint $blueprint) use (
            $dimensionUnique, $legacyUnique, $hashColumn, $segmentDateIndex, $storeDimensionIndex, $idColumn,
        ): void {
            $blueprint->dropUnique($dimensionUnique);
            $blueprint->unique(['store_id', 'metric_date', 'segment', $hashColumn], $legacyUnique);
            $blueprint->index(['store_id', 'segment', 'metric_date'], $segmentDateIndex);
            $blueprint->index(['store_id', $idColumn], $storeDimensionIndex);
        });
    }
};
