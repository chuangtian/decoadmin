<?php

namespace App\Services\SeoAnalytics;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class GscDimensionRegistry
{
    /** @param list<array<string, mixed>> $records @return list<array<string, mixed>> */
    public function attachPageIds(array $records): array
    {
        return $this->attachIds($records, 'pages');
    }

    /** @param list<array<string, mixed>> $records @return list<array<string, mixed>> */
    public function attachQueryIds(array $records): array
    {
        return $this->attachIds($records, 'queries');
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @return list<array<string, mixed>>
     */
    private function attachIds(array $records, string $type): array
    {
        if ($records === []) {
            return [];
        }

        $isPage = $type === 'pages';
        $table = $isPage ? 'seo_gsc_pages' : 'seo_gsc_queries';
        $hashColumn = $isPage ? 'page_hash' : 'query_hash';
        $labelColumn = $isPage ? 'page' : 'query';
        $idColumn = $isPage ? 'page_id' : 'query_id';
        $storeId = (int) $records[0]['store_id'];
        $organizationId = (int) $records[0]['organization_id'];
        $now = now();

        $dimensions = collect($records)->unique($hashColumn)->map(function (array $record) use ($organizationId, $storeId, $hashColumn, $labelColumn, $isPage, $now): array {
            $dimension = [
                'organization_id' => $organizationId,
                'store_id' => $storeId,
                $hashColumn => (string) $record[$hashColumn],
                $labelColumn => (string) $record[$labelColumn],
                'created_at' => $now,
                'updated_at' => $now,
            ];
            if ($isPage) {
                $dimension['is_blog'] = str_contains(strtolower((string) $record[$labelColumn]), '/blogs/');
            }

            return $dimension;
        })->values()->all();

        $updates = [$labelColumn, 'updated_at'];
        if ($isPage) {
            $updates[] = 'is_blog';
        }
        DB::table($table)->upsert($dimensions, ['store_id', $hashColumn], $updates);

        $ids = DB::table($table)->where('organization_id', $organizationId)->where('store_id', $storeId)
            ->whereIn($hashColumn, array_column($dimensions, $hashColumn))->pluck('id', $hashColumn);

        return collect($records)->map(function (array $record) use ($ids, $hashColumn, $idColumn): array {
            $id = $ids->get((string) $record[$hashColumn]);
            if ($id === null) {
                throw new RuntimeException("无法解析 GSC 维度 ID：{$hashColumn}");
            }

            return [...$record, $idColumn => (int) $id];
        })->all();
    }
}
