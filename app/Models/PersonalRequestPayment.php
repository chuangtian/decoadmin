<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['uuid', 'organization_id', 'personal_request_id', 'paid_by', 'type', 'amount', 'currency', 'paid_on', 'reference'])]
class PersonalRequestPayment extends Model
{
    protected static function booted(): void
    {
        static::creating(function (PersonalRequestPayment $payment): void {
            $payment->uuid ??= (string) Str::uuid();
        });
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(PersonalRequest::class, 'personal_request_id');
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'paid_on' => 'date'];
    }
}
