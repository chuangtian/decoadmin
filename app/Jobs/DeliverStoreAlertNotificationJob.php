<?php

namespace App\Jobs;

use App\Models\StoreAlert;
use App\Services\StoreAlertNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DeliverStoreAlertNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public int $alertId) {}

    public function handle(StoreAlertNotificationService $notifications): void
    {
        $alert = StoreAlert::query()->find($this->alertId);
        if ($alert) {
            $notifications->deliver($alert);
        }
    }
}
