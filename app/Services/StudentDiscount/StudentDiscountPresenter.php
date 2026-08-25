<?php

namespace App\Services\StudentDiscount;

use App\Models\StudentDiscountClaim;
use App\Models\StudentDiscountCode;

class StudentDiscountPresenter
{
    /** @return array<string, mixed> */
    public function publicClaim(StudentDiscountClaim $claim, ?StudentDiscountCode $code = null): array
    {
        $code ??= $claim->discountCode;

        return [
            'id' => $claim->uuid,
            'status' => $claim->status,
            'review_method' => $claim->review_method,
            'submitted_at' => $claim->created_at->toIso8601String(),
            'reviewed_at' => $claim->reviewed_at?->toIso8601String(),
            'rejection_reason' => $claim->status === 'rejected' ? $claim->rejection_reason : null,
            'discount' => $code ? $this->discount($code) : null,
        ];
    }

    /** @return array<string, mixed> */
    public function discount(StudentDiscountCode $code): array
    {
        $code->refreshStatus();

        return [
            'id' => $code->uuid,
            'code' => $code->code,
            'status' => $code->status,
            'usage_count' => $code->usage_count,
            'usage_limit' => $code->usage_limit,
            'generated_at' => $code->generated_at->toIso8601String(),
            'expires_at' => $code->expires_at->toIso8601String(),
        ];
    }
}
