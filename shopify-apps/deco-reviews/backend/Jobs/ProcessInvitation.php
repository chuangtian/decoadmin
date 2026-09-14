<?php

namespace DecoReviews\Jobs;

use DecoReviews\Services\InvitationProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessInvitation implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public int $organizationId, public int $storeId, public string $uuid)
    {
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        app(InvitationProcessor::class)->process($this->organizationId, $this->storeId, $this->uuid);
    }
}
