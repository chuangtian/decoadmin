<?php

namespace App\Jobs;

use App\Services\MetaAds\MetaAdsSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class FinalizeMetaAdsSync implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $tries = 1;

    public function __construct(public readonly int $syncJobId)
    {
        $this->onQueue('meta-ads');
    }

    public function handle(MetaAdsSyncService $sync): void
    {
        if (! $sync->finalizeShardedSync($this->syncJobId)) {
            self::dispatch($this->syncJobId)->delay(now()->addSeconds(30));
        }
    }
}
