<?php

namespace App\Services\MetaAds;

use App\Jobs\FinalizeMetaAdsSync;
use App\Jobs\PollMetaAdsAsyncReport;
use App\Jobs\SyncMetaAdsForStore;
use App\Jobs\SyncMetaAdsShard;
use App\Models\MetaAdAccount;
use App\Models\MetaAdSyncShard;
use App\Models\Store;
use App\Models\StoreBusinessCredential;
use App\Models\StoreSyncState;
use App\Models\SyncJob;
use App\Models\User;
use App\Services\StoreBusinessCredentialService;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class MetaAdsLifecycleService
{
    public function __construct(
        private StoreBusinessCredentialService $credentials,
        private CacheRepository $cache,
    ) {}

    /**
     * A submitted token is treated as a fresh Meta connection. Old records
     * cannot be mixed with a different Business account, so the previous run
     * is cancelled before a new two-phase import is queued.
     */
    public function restartFullSync(Store $store, StoreBusinessCredential $credential): void
    {
        $this->removeQueuedJobs($store);

        DB::transaction(function () use ($store): void {
            $this->deleteSyncData($store);

            StoreSyncState::query()->create([
                'organization_id' => $store->organization_id,
                'store_id' => $store->getKey(),
                'sync_type' => MetaAdsSyncService::SYNC_TYPE,
                'status' => 'queued',
                'next_sync_at' => now(),
            ]);
        });

        $this->releaseStoreLocks($store);

        SyncMetaAdsForStore::dispatch(
            (int) $store->organization_id,
            (int) $store->getKey(),
            'priority',
            $this->credentialVersion($credential),
        )->afterCommit();
    }

    public function clear(Store $store, User $actor): void
    {
        $this->removeQueuedJobs($store);

        DB::transaction(function () use ($store, $actor): void {
            // Delete the credential first so a worker already making an API
            // request cannot write another page after this transaction commits.
            $this->credentials->clear($store, 'meta_ads', 'access_token', $actor);
            $this->deleteSyncData($store);
        });

        $this->releaseStoreLocks($store);
    }

    /**
     * Resume an interrupted full import without deleting completed records or
     * checkpoints. Pending queue payloads are replaced so an upgraded shard
     * strategy can take effect immediately.
     */
    public function resumeFullSync(Store $store, StoreBusinessCredential $credential): void
    {
        $this->removeQueuedJobs($store);
        $this->releaseStoreLocks($store);

        SyncMetaAdsForStore::dispatch(
            (int) $store->organization_id,
            (int) $store->getKey(),
            'priority',
            $this->credentialVersion($credential),
        );
    }

    private function deleteSyncData(Store $store): void
    {
        $scope = [
            'organization_id' => $store->organization_id,
            'store_id' => $store->getKey(),
        ];

        StoreSyncState::query()
            ->where($scope)
            ->where('sync_type', MetaAdsSyncService::SYNC_TYPE)
            ->delete();

        // Account deletion cascades through campaigns, ad sets, ads,
        // creatives, insights, and any account-bound shard checkpoints.
        MetaAdAccount::query()
            ->forOrganization((int) $store->organization_id)
            ->forStore((int) $store->getKey())
            ->delete();

        MetaAdSyncShard::query()->where($scope)->delete();

        SyncJob::withTrashed()
            ->where($scope)
            ->where('type', MetaAdsSyncService::SYNC_TYPE)
            ->forceDelete();
    }

    private function releaseStoreLocks(Store $store): void
    {
        $job = new SyncMetaAdsForStore(
            (int) $store->organization_id,
            (int) $store->getKey(),
        );
        (new UniqueLock($this->cache))->release($job);
        $this->cache->lock(
            "laravel-queue-overlap:meta-ads-sync:{$store->organization_id}:{$store->getKey()}",
        )->forceRelease();
    }

    /**
     * Remove pending, delayed, and reserved jobs for only this store. A
     * reserved worker may already be executing; database cancellation guards
     * in MetaAdsSyncService stop that worker before its next write.
     */
    private function removeQueuedJobs(Store $store): int
    {
        $manager = app('queue');
        if (! $manager instanceof QueueManager) {
            return 0;
        }

        try {
            $queue = $manager->connection('redis');
            if (! $queue instanceof RedisQueue) {
                return 0;
            }

            $redis = $queue->getConnection();
            $removed = 0;
            foreach ([
                'queues:meta-ads',
                'queues:meta-ads:delayed',
                'queues:meta-ads:reserved',
                'queues:meta-ads-poll',
                'queues:meta-ads-poll:delayed',
                'queues:meta-ads-poll:reserved',
            ] as $key) {
                $payloads = in_array($key, ['queues:meta-ads', 'queues:meta-ads-poll'], true)
                    ? $redis->lrange($key, 0, -1)
                    : $redis->zrange($key, 0, -1);

                foreach ($payloads as $payload) {
                    $job = $this->jobFromPayload((string) $payload);
                    if (! $job || ! $this->belongsToStore($job, $store)) {
                        continue;
                    }

                    $removed += in_array($key, ['queues:meta-ads', 'queues:meta-ads-poll'], true)
                        ? (int) $redis->lrem($key, 0, $payload)
                        : (int) $redis->zrem($key, $payload);

                    if ($job instanceof SyncMetaAdsForStore
                        || $job instanceof SyncMetaAdsShard
                        || $job instanceof PollMetaAdsAsyncReport) {
                        (new UniqueLock($this->cache))->release($job);
                    }
                }
            }

            return $removed;
        } catch (Throwable $exception) {
            Log::warning('Unable to remove scoped Meta Ads queue payloads.', [
                'organization_id' => $store->organization_id,
                'store_id' => $store->getKey(),
                'exception' => $exception::class,
            ]);

            return 0;
        }
    }

    private function jobFromPayload(string $payload): ?object
    {
        $decoded = json_decode($payload, true);
        $command = is_array($decoded) ? data_get($decoded, 'data.command') : null;
        if (! is_string($command) || $command === '') {
            return null;
        }

        $job = @unserialize($command);

        return is_object($job) ? $job : null;
    }

    private function belongsToStore(object $job, Store $store): bool
    {
        if ($job instanceof SyncMetaAdsForStore) {
            return $job->organizationId === (int) $store->organization_id
                && $job->storeId === (int) $store->getKey();
        }

        if ($job instanceof SyncMetaAdsShard) {
            return MetaAdSyncShard::query()
                ->whereKey($job->shardId)
                ->where('organization_id', $store->organization_id)
                ->where('store_id', $store->getKey())
                ->exists();
        }

        if ($job instanceof PollMetaAdsAsyncReport) {
            return MetaAdSyncShard::query()
                ->whereKey($job->shardId)
                ->where('organization_id', $store->organization_id)
                ->where('store_id', $store->getKey())
                ->exists();
        }

        if ($job instanceof FinalizeMetaAdsSync) {
            return SyncJob::query()
                ->whereKey($job->syncJobId)
                ->where('organization_id', $store->organization_id)
                ->where('store_id', $store->getKey())
                ->where('type', MetaAdsSyncService::SYNC_TYPE)
                ->exists();
        }

        return false;
    }

    private function credentialVersion(StoreBusinessCredential $credential): string
    {
        return $credential->updated_at?->utc()->format('Y-m-d H:i:s') ?? '';
    }
}
