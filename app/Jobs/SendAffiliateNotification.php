<?php

namespace App\Jobs;

use App\Domain\ReferralAffiliate\Services\AffiliateNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendAffiliateNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 60;

    public function __construct(public int $intentId)
    {
        $this->onQueue('affiliate-notifications');
    }

    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(AffiliateNotificationService $service): void
    {
        $service->send($this->intentId);
    }
}
