<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AdvertisingChannelSyncStatusChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  list<string>  $views
     */
    public function __construct(
        public readonly int $organizationId,
        public readonly int $storeId,
        public readonly string $channel,
        public readonly string $state,
        public readonly string $source = 'google_ads',
        public readonly ?string $mode = null,
        public readonly array $views = [],
        public readonly ?float $progressPercent = null,
        public readonly ?string $message = null,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("advertising-sync.{$this->organizationId}.{$this->storeId}");
    }

    public function broadcastAs(): string
    {
        return 'advertising-channel.sync-status';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'channel' => $this->channel,
            'state' => $this->state,
            'source' => $this->source,
            'mode' => $this->mode,
            'views' => $this->views,
            'progress_percent' => $this->progressPercent,
            'message' => $this->message,
            'emitted_at' => now()->toIso8601String(),
        ];
    }
}
