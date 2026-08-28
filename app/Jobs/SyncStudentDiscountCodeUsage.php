<?php

namespace App\Jobs;

use App\Models\StudentDiscountCode;
use App\Services\StudentDiscount\StudentDiscountCodeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncStudentDiscountCodeUsage implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 30;

    public int $uniqueFor = 300;

    /** @var list<int> */
    public array $backoff = [10, 30];

    public function __construct(
        public readonly int $organizationId,
        public readonly int $storeId,
        public readonly int $codeId,
    ) {
        $this->onQueue('shopify-sync');
    }

    public function handle(StudentDiscountCodeService $codes): void
    {
        $code = StudentDiscountCode::query()
            ->where('organization_id', $this->organizationId)
            ->where('store_id', $this->storeId)
            ->whereKey($this->codeId)
            ->first();
        if (! $code) {
            return;
        }

        $codes->syncUsage($code);
    }

    public function uniqueId(): string
    {
        return "{$this->organizationId}:{$this->storeId}:{$this->codeId}";
    }
}
