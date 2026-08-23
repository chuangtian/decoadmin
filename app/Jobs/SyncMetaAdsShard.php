<?php

namespace App\Jobs;

use App\Models\MetaAdSyncShard;
use App\Services\MetaAds\MetaAdsSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SyncMetaAdsShard implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout;

    public int $uniqueFor;

    public bool $failOnTimeout = true;

    public int $tries = 100;

    public function __construct(public readonly int $shardId)
    {
        $this->onQueue('meta-ads');
        $this->timeout = min(1800, max(120, (int) config('services.meta_ads.shard_timeout_seconds', 900)));
        $this->uniqueFor = $this->timeout + 3600;
    }

    public function handle(MetaAdsSyncService $sync): void
    {
        $shard = MetaAdSyncShard::query()->find($this->shardId);
        if (! $shard || $shard->status === 'completed') {
            return;
        }

        $result = $sync->runShard($shard);
        foreach ((array) ($result['replacement_shard_ids'] ?? []) as $replacementShardId) {
            self::dispatch((int) $replacementShardId);
        }
        if ((int) ($result['async_pending'] ?? 0) === 1) {
            PollMetaAdsAsyncReport::dispatch($this->shardId)->delay(
                now()->addSeconds(max(5, (int) ($result['retry_after_seconds'] ?? 15))),
            );
        }
    }

    public function uniqueId(): string
    {
        return (string) $this->shardId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function failed(?Throwable $exception): void
    {
        app(MetaAdsSyncService::class)->markShardFailed($this->shardId, $exception);
    }
}
