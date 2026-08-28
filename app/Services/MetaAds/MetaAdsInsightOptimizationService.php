<?php

namespace App\Services\MetaAds;

use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class MetaAdsInsightOptimizationService
{
    private const REDUNDANT_JSON_COLUMNS = [
        'outbound_clicks',
        'actions',
        'action_values',
        'cost_per_action_type',
        'purchase_roas_breakdown',
        'website_purchase_roas',
    ];

    /** @return array<string, int> */
    public function estimate(?int $storeId = null): array
    {
        $facts = $this->facts($storeId);
        $total = (clone $facts)->count();
        $hourly = (clone $facts)->where('granularity', 'hour')->count();
        $redundantJson = $this->redundantJson($storeId)->count();

        return [
            'fact_rows' => $total,
            'hourly_rows' => $hourly,
            'redundant_json_rows' => $redundantJson,
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
        $jsonSanitized = $this->updateInChunks(
            $this->redundantJson($storeId),
            $chunkSize,
            [
                'outbound_clicks' => null,
                'actions' => null,
                'action_values' => null,
                'cost_per_action_type' => null,
                'purchase_roas_breakdown' => null,
                'website_purchase_roas' => null,
                'raw_payload' => '{}',
                'updated_at' => now(),
            ],
            fn (int $processed, int $total) => $progress?->__invoke('payloads', $processed, $total),
        );

        return [
            ...$before,
            'hourly_deleted' => $hourlyDeleted,
            'json_sanitized' => $jsonSanitized,
            'remaining_rows' => $this->facts($storeId)->count(),
        ];
    }

    private function facts(?int $storeId): Builder
    {
        return DB::table('meta_ad_insights')
            ->when($storeId !== null, fn (Builder $query) => $query->where('store_id', $storeId));
    }

    private function redundantJson(?int $storeId): Builder
    {
        return $this->facts($storeId)->where('granularity', '!=', 'hour')
            ->where(function (Builder $query): void {
                $query->where('raw_payload', '!=', '{}');
                foreach (self::REDUNDANT_JSON_COLUMNS as $column) {
                    $query->orWhereNotNull($column);
                }
            });
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

    /** @param array<string, mixed> $values @param Closure(int, int): void $progress */
    private function updateInChunks(Builder $query, int $chunkSize, array $values, Closure $progress): int
    {
        $total = (clone $query)->count();
        $bounds = (clone $query)->selectRaw('MIN(id) min_id, MAX(id) max_id')->first();
        if ($bounds?->min_id === null || $bounds?->max_id === null) {
            return 0;
        }

        $updated = 0;
        for ($start = (int) $bounds->min_id; $start <= (int) $bounds->max_id; $start += $chunkSize) {
            $updated += (clone $query)->whereBetween('id', [$start, $start + $chunkSize - 1])->update($values);
            $progress($updated, $total);
        }

        return $updated;
    }
}
