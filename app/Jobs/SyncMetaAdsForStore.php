<?php

namespace App\Jobs;

use App\Exceptions\MetaAdsApiException;
use App\Models\Store;
use App\Models\StoreBusinessCredential;
use App\Models\SyncJob;
use App\Services\MetaAds\MetaAdsSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SyncMetaAdsForStore implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout;

    public int $uniqueFor;

    public bool $failOnTimeout = true;

    public int $tries = 3;

    public function __construct(
        public readonly int $organizationId,
        public readonly int $storeId,
        public readonly string $mode = 'incremental',
        public readonly ?string $credentialVersion = null,
        public readonly bool $reconcileIncremental = true,
    ) {
        $this->onQueue('meta-ads');
        $this->timeout = 300;
        $this->uniqueFor = 600;
    }

    public function handle(MetaAdsSyncService $sync): void
    {
        $store = Store::query()
            ->where('organization_id', $this->organizationId)
            ->whereKey($this->storeId)
            ->where('status', 'active')
            ->first();

        if (! $store) {
            return;
        }

        $credential = StoreBusinessCredential::query()
            ->where('organization_id', $this->organizationId)
            ->where('store_id', $this->storeId)
            ->where('provider', 'meta_ads')
            ->where('credential_key', 'access_token')
            ->first();
        if (! $credential) {
            return;
        }

        $currentVersion = $credential->updated_at?->utc()->format('Y-m-d H:i:s') ?? '';
        if ($this->credentialVersion !== null && ! hash_equals($this->credentialVersion, $currentVersion)) {
            return;
        }

        // The initial six-month backfill already contains the rolling window.
        // Avoid competing Meta API requests and duplicate upserts while it is
        // still running; the next hourly insight-only run will reconcile the
        // most recent three days after the backfill completes, while the next
        // daily structure run will refresh the hierarchy.
        if (in_array($this->mode, ['incremental', 'structure'], true) && SyncJob::query()
            ->where('organization_id', $this->organizationId)
            ->where('store_id', $this->storeId)
            ->where('type', MetaAdsSyncService::SYNC_TYPE)
            ->whereIn('mode', ['priority', 'backfill'])
            ->where('status', 'running')
            ->exists()) {
            return;
        }

        try {
            $run = $sync->orchestrate(
                $store,
                $this->mode,
                $currentVersion,
                $this->reconcileIncremental,
            );
        } catch (MetaAdsApiException $exception) {
            if ($exception->errorCode === 'meta_ads_sync_cancelled') {
                return;
            }

            throw $exception;
        }

        foreach ($run['shard_ids'] as $shardId) {
            SyncMetaAdsShard::dispatch($shardId);
        }
        FinalizeMetaAdsSync::dispatch($run['sync_job_id'])->delay(now()->addSeconds(10));
    }

    public function uniqueId(): string
    {
        return $this->organizationId.':'.$this->storeId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [300, 900];
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("meta-ads-sync:{$this->organizationId}:{$this->storeId}"))
                ->releaseAfter(30)
                ->expireAfter($this->timeout + 60)
                ->shared(),
        ];
    }

    public function failed(?Throwable $exception): void
    {
        app(MetaAdsSyncService::class)->markUnexpectedFailure($this->organizationId, $this->storeId);
    }
}
