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

class PollMetaAdsAsyncReport implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;

    public int $uniqueFor = 3600;

    public int $tries = 100;

    public function __construct(public readonly int $shardId)
    {
        $this->onQueue('meta-ads-poll');
    }

    public function handle(MetaAdsSyncService $sync): void
    {
        $shard = MetaAdSyncShard::query()->find($this->shardId);
        if (! $shard || $shard->status === 'completed') {
            return;
        }

        $result = $sync->runShard($shard);
        foreach ((array) ($result['replacement_shard_ids'] ?? []) as $replacementShardId) {
            SyncMetaAdsShard::dispatch((int) $replacementShardId);
        }
        if ((int) ($result['async_pending'] ?? 0) === 1) {
            $this->release(max(5, (int) ($result['retry_after_seconds'] ?? 15)));
        }
    }

    public function uniqueId(): string
    {
        return (string) $this->shardId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [15, 60, 300];
    }

    public function failed(?Throwable $exception): void
    {
        app(MetaAdsSyncService::class)->markShardFailed($this->shardId, $exception);
    }
}
