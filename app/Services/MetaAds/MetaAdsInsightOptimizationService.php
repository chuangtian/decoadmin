<?php

namespace App\Services\MetaAds;

use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class MetaAdsInsightOptimizationService
{
    /** @return array<string, int> */
    public function estimate(?int $storeId = null): array
    {
        $facts = $this->facts($storeId);
        $total = (clone $facts)->count();
        $hourly = (clone $facts)->where('granularity', 'hour')->count();

        return [
            'fact_rows' => $total,
            'hourly_rows' => $hourly,
            'redundant_json_rows' => 0,
            'retained_rows' => $total - $hourly,
        ];
    }

    /**
     * @param  Closure(string, int, int): void|null  $progress
     * @return array<string, int>
     */
    public function optimize(?int $storeId = null, int $chunkSize = 10000, ?Closure $progress = null): array
    {
        $chunkSize = min(100000, max(1000, $chunkSize));
        $before = $this->estimate($storeId);
        $hourlyDeleted = $this->deleteInChunks(
            $this->facts($storeId)->where('granularity', 'hour'),
            $chunkSize,
            fn (int $processed, int $total) => $progress?->__invoke('hourly', $processed, $total),
        );

        return [
            ...$before,
            'hourly_deleted' => $hourlyDeleted,
            'json_sanitized' => 0,
            'remaining_rows' => $this->facts($storeId)->count(),
        ];
    }

    private function facts(?int $storeId): Builder
    {
        return DB::table('meta_ad_insights')
            ->when($storeId !== null, fn (Builder $query) => $query->where('store_id', $storeId));
    }

    /** @param Closure(int, int): void $progress */
    private function deleteInChunks(Builder $query, int $chunkSize, Closure $progress): int
    {
        $total = (clone $query)->count();
        $bounds = (clone $query)->selectRaw('MIN(id) min_id, MAX(id) max_id')->first();
        if ($bounds?->min_id === null || $bounds?->max_id === null) {
            return 0;
        }

        $deleted = 0;
        for ($start = (int) $bounds->min_id; $start <= (int) $bounds->max_id; $start += $chunkSize) {
            $deleted += (clone $query)->whereBetween('id', [$start, $start + $chunkSize - 1])->delete();
            $progress($deleted, $total);
        }

        return $deleted;
    }
}
