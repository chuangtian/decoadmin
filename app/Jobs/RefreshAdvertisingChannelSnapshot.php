<?php

namespace App\Jobs;

use App\Services\Advertising\AdvertisingChannelSnapshotService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class RefreshAdvertisingChannelSnapshot implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 70;

    public int $uniqueFor = 180;

    public int $tries = 2;

    public function __construct(
        public readonly int $snapshotId,
        public readonly string $channelKey,
        public readonly string $generation,
    ) {
        $this->onQueue('shopify-analytics');
    }

    public function handle(AdvertisingChannelSnapshotService $snapshots): void
    {
        $snapshots->refreshChannel($this->snapshotId, $this->channelKey, $this->generation);
    }

    public function uniqueId(): string
    {
        return "{$this->snapshotId}:{$this->channelKey}:{$this->generation}";
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("advertising-channel-snapshot:{$this->snapshotId}:{$this->channelKey}"))
                ->releaseAfter(15)
                ->expireAfter($this->timeout + 30)
                ->shared(),
        ];
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120];
    }
}
