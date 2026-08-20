<?php

namespace App\Services\Shopify\Analytics;

use App\Models\AnalyticsSnapshot;

class AnalyticsSnapshotMaintenanceService
{
    /** @return array{deleted: int, retention_days: int, cutoff: string} */
    public function prune(): array
    {
        $retentionDays = max(1, (int) config('shopify.analytics_snapshot_retention_days', 365));
        $batchSize = max(100, (int) config('shopify.analytics_snapshot_prune_batch_size', 1000));
        $cutoff = now()->subDays($retentionDays);
        $deleted = 0;

        do {
            $ids = AnalyticsSnapshot::query()
                ->where('expires_at', '<', $cutoff)
                ->orderBy('id')
                ->limit($batchSize)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $deleted += AnalyticsSnapshot::query()->whereKey($ids)->delete();
        } while ($ids->count() === $batchSize);

        return [
            'deleted' => $deleted,
            'retention_days' => $retentionDays,
            'cutoff' => $cutoff->toIso8601String(),
        ];
    }
}
