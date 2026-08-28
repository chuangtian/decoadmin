<?php

namespace App\Jobs;

use App\Services\StudentDiscount\StudentDiscountEvidenceCleanupService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class DeleteSupersededStudentDiscountEvidence implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 30;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 86400;

    /** @var list<int> */
    public array $backoff = [30, 120, 600, 1800];

    public function __construct(public readonly int $deletionId) {}

    public function handle(StudentDiscountEvidenceCleanupService $cleanup): void
    {
        $cleanup->cleanup($this->deletionId);
    }

    public function uniqueId(): string
    {
        return (string) $this->deletionId;
    }

    public function failed(?Throwable $exception): void
    {
        app(StudentDiscountEvidenceCleanupService::class)->markPermanentlyFailed($this->deletionId);
    }
}
