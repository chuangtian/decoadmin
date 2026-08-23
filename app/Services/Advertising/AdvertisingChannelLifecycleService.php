<?php

namespace App\Services\Advertising;

use App\Jobs\SyncAdvertisingChannelForStore;
use App\Models\AdvertisingChannelAccount;
use App\Models\Store;
use App\Models\StoreSyncState;
use App\Models\SyncJob;
use App\Models\User;
use App\Services\StoreBusinessCredentialService;
use Illuminate\Support\Facades\DB;

class AdvertisingChannelLifecycleService
{
    public function __construct(
        private AdvertisingChannelSyncService $sync,
        private StoreBusinessCredentialService $credentials,
    ) {}

    public function restartIfConfigured(Store $store, string $provider): void
    {
        $channel = $this->sync->channelForProvider($provider);
        if ($channel === null || ! $this->sync->configured($store, $channel)) {
            return;
        }

        DB::transaction(fn () => $this->deleteSyncData($store, $channel));
        SyncAdvertisingChannelForStore::dispatch(
            (int) $store->organization_id,
            (int) $store->getKey(),
            $channel,
            'priority',
            $this->sync->credentialVersion($store, $channel),
        )->afterCommit();
    }

    public function supportsProvider(string $provider): bool
    {
        return $this->sync->channelForProvider($provider) !== null;
    }

    public function clearCredential(Store $store, string $provider, string $credentialKey, User $actor): void
    {
        $channel = $this->sync->channelForProvider($provider);
        DB::transaction(function () use ($store, $provider, $credentialKey, $actor, $channel): void {
            $this->credentials->clear($store, $provider, $credentialKey, $actor);
            if ($channel !== null) {
                $this->deleteSyncData($store, $channel);
            }
        });
    }

    private function deleteSyncData(Store $store, string $channel): void
    {
        $scope = ['organization_id' => $store->organization_id, 'store_id' => $store->getKey()];
        AdvertisingChannelAccount::query()
            ->where($scope)
            ->where('provider', $channel)
            ->delete();
        StoreSyncState::query()
            ->where($scope)
            ->where('sync_type', $this->sync->syncType($channel))
            ->delete();
        SyncJob::withTrashed()
            ->where($scope)
            ->where('type', $this->sync->syncType($channel))
            ->forceDelete();
    }
}
