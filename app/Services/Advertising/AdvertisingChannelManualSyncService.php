<?php

namespace App\Services\Advertising;

use App\Jobs\SyncAdvertisingChannelForStore;
use App\Models\Store;
use App\Models\SyncJob;

class AdvertisingChannelManualSyncService
{
    public function __construct(private AdvertisingChannelSyncService $sync) {}

    /** @return array{queued: bool, already_running: bool, configured: bool, mode: string|null} */
    public function queue(Store $store, string $channel): array
    {
        if (! $this->sync->configured($store, $channel)) {
            return ['queued' => false, 'already_running' => false, 'configured' => false, 'mode' => null];
        }

        $active = SyncJob::query()
            ->where('organization_id', $store->organization_id)
            ->where('store_id', $store->getKey())
            ->where('type', $this->sync->syncType($channel))
            ->whereIn('status', ['queued', 'running'])
            ->latest('id')
            ->first();
        if ($active) {
            return ['queued' => false, 'already_running' => true, 'configured' => true, 'mode' => $active->mode];
        }

        SyncAdvertisingChannelForStore::dispatch(
            (int) $store->organization_id,
            (int) $store->getKey(),
            $channel,
            'incremental',
            $this->sync->credentialVersion($store, $channel),
        );

        return ['queued' => true, 'already_running' => false, 'configured' => true, 'mode' => 'incremental'];
    }
}
