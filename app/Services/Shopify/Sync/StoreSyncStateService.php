<?php

namespace App\Services\Shopify\Sync;

use App\Models\Store;
use App\Models\StoreSyncState;
use App\Models\SyncJob;
use Carbon\CarbonImmutable;

class StoreSyncStateService
{
    /** @return array{mode: string, since_at: CarbonImmutable|null, until_at: CarbonImmutable} */
    public function window(Store $store, string $type, string $requestedMode): array
    {
        $state = $this->state($store, $type);
        $untilAt = CarbonImmutable::now('UTC')->startOfSecond();
        $mode = $requestedMode;

        if ($mode === 'incremental' && ! $state->last_full_sync_at) {
            $mode = 'full';
        }

        $sinceAt = null;

        if ($mode === 'incremental') {
            $watermark = $state->watermark_at ?? $state->last_success_at;
            $overlapMinutes = max(1, (int) config('shopify.scheduled_sync.overlap_minutes', 5));
            $sinceAt = $watermark
                ? CarbonImmutable::instance($watermark)->utc()->subMinutes($overlapMinutes)
                : null;
        }

        return [
            'mode' => $mode,
            'since_at' => $sinceAt,
            'until_at' => $untilAt,
        ];
    }

    public function state(Store $store, string $type): StoreSyncState
    {
        return StoreSyncState::query()->firstOrCreate([
            'store_id' => $store->getKey(),
            'sync_type' => $type,
        ], [
            'organization_id' => $store->organization_id,
            'status' => 'idle',
            'next_sync_at' => now(),
        ]);
    }

    public function isDue(StoreSyncState $state, string $mode): bool
    {
        if (in_array($state->status, ['queued', 'running'], true)) {
            return false;
        }

        if ($mode !== 'incremental') {
            return true;
        }

        return ! $state->next_sync_at || $state->next_sync_at->lte(now());
    }

    public function markQueued(SyncJob $job): void
    {
        $state = $this->state($job->store, $job->type);
        $state->forceFill([
            'status' => 'queued',
            'last_job_id' => $job->getKey(),
        ])->save();
    }

    public function markRunning(SyncJob $job): void
    {
        $state = $this->state($job->store, $job->type);
        $state->forceFill([
            'status' => 'running',
            'last_job_id' => $job->getKey(),
            'last_error_code' => null,
            'last_error' => null,
        ])->save();
    }

    public function markCompleted(SyncJob $job): void
    {
        $state = $this->state($job->store, $job->type);
        $finishedAt = $job->finished_at ?? now();
        $attributes = [
            'status' => 'idle',
            'last_job_id' => $job->getKey(),
            'last_success_at' => $finishedAt,
            'watermark_at' => $job->until_at ?? $finishedAt,
            'consecutive_failures' => 0,
            'next_sync_at' => $finishedAt->copy()->addMinutes($this->intervalMinutes($job->type)),
            'last_error_code' => null,
            'last_error' => null,
        ];

        $attributes[match ($job->mode) {
            'incremental' => 'last_incremental_sync_at',
            'reconcile' => 'last_reconciled_at',
            default => 'last_full_sync_at',
        }] = $finishedAt;

        $state->forceFill($attributes)->save();
    }

    public function markFailed(SyncJob $job): void
    {
        $state = $this->state($job->store, $job->type);
        $state->forceFill([
            'status' => 'failed',
            'last_job_id' => $job->getKey(),
            'last_failed_at' => $job->failed_at ?? now(),
            'consecutive_failures' => $state->consecutive_failures + 1,
            'next_sync_at' => now()->addMinutes($this->retryDelayMinutes($state->consecutive_failures + 1)),
            'last_error_code' => $job->error_code,
            'last_error' => $job->last_error,
        ])->save();
    }

    private function intervalMinutes(string $type): int
    {
        return max(5, (int) config(
            $type === 'inventory'
                ? 'shopify.scheduled_sync.inventory_interval_minutes'
                : 'shopify.scheduled_sync.incremental_interval_minutes',
            $type === 'inventory' ? 30 : 15,
        ));
    }

    private function retryDelayMinutes(int $failures): int
    {
        return min(60, 5 * (2 ** max(0, min($failures - 1, 3))));
    }
}
