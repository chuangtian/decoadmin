<?php

namespace App\Jobs;

use App\Mail\StudentDiscountDecisionMail;
use App\Models\StudentDiscountClaim;
use App\Models\StudentDiscountCode;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

class SendStudentDiscountDecisionMail implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 3600;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(
        public readonly int $organizationId,
        public readonly int $storeId,
        public readonly int $claimId,
        public readonly ?int $codeId,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        $claim = StudentDiscountClaim::query()
            ->where('organization_id', $this->organizationId)
            ->where('store_id', $this->storeId)
            ->whereKey($this->claimId)
            ->first();
        if (! $claim || $claim->email_sent_at !== null) {
            return;
        }

        $code = $this->codeFor($claim);
        try {
            Mail::to($claim->email)->send(new StudentDiscountDecisionMail($claim, $code));
        } catch (Throwable) {
            throw new RuntimeException('Student discount decision email delivery failed.');
        }

        $sentAt = now();
        StudentDiscountClaim::query()
            ->where('organization_id', $this->organizationId)
            ->where('store_id', $this->storeId)
            ->whereKey($this->claimId)
            ->whereNull('email_sent_at')
            ->update(['email_sent_at' => $sentAt, 'email_failed_at' => null]);

        if ($this->codeId !== null) {
            StudentDiscountCode::query()
                ->where('organization_id', $this->organizationId)
                ->where('store_id', $this->storeId)
                ->where('claim_id', $this->claimId)
                ->whereKey($this->codeId)
                ->whereNull('email_sent_at')
                ->update(['email_sent_at' => $sentAt, 'email_failed_at' => null]);
        }
    }

    public function uniqueId(): string
    {
        return "{$this->organizationId}:{$this->storeId}:{$this->claimId}";
    }

    public function failed(?Throwable $exception): void
    {
        $failedAt = now();
        StudentDiscountClaim::query()
            ->where('organization_id', $this->organizationId)
            ->where('store_id', $this->storeId)
            ->whereKey($this->claimId)
            ->whereNull('email_sent_at')
            ->update(['email_failed_at' => $failedAt]);

        if ($this->codeId !== null) {
            StudentDiscountCode::query()
                ->where('organization_id', $this->organizationId)
                ->where('store_id', $this->storeId)
                ->where('claim_id', $this->claimId)
                ->whereKey($this->codeId)
                ->whereNull('email_sent_at')
                ->update(['email_failed_at' => $failedAt]);
        }
    }

    private function codeFor(StudentDiscountClaim $claim): ?StudentDiscountCode
    {
        if ($this->codeId === null) {
            return null;
        }

        return StudentDiscountCode::query()
            ->where('organization_id', $this->organizationId)
            ->where('store_id', $this->storeId)
            ->where('claim_id', $claim->id)
            ->whereKey($this->codeId)
            ->firstOr(function (): never {
                throw new RuntimeException('Student discount decision email record is unavailable.');
            });
    }
}
