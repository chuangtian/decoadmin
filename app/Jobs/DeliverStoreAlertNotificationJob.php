<?php

namespace App\Jobs;

use App\Models\StoreAlert;
use App\Services\StoreAlertNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DeliverStoreAlertNotificationJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 60;

    public function __construct(public int $alertId) {}

    public function handle(StoreAlertNotificationService $notifications): void
    {
        $alert = StoreAlert::query()->find($this->alertId);
        if ($alert) {
            $notifications->deliver($alert);
        }
    }

    public function uniqueId(): string
    {
        return (string) $this->alertId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 300];
    }
}
