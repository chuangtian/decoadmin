<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

#[Fillable([
    'uuid', 'organization_id', 'kind', 'reference_no', 'submitter_id', 'assignee_id', 'reviewer_id',
    'title', 'description', 'category', 'priority', 'desired_date', 'amount', 'currency', 'expense_date',
    'software_url', 'software_account', 'software_password', 'renewal_mode', 'billing_cycle',
    'payment_status', 'paid_by', 'paid_on', 'next_renewal_on', 'payment_reference',
    'status', 'review_note', 'reviewed_at', 'completed_at',
])]
class PersonalRequest extends Model
{
    use SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (PersonalRequest $request): void {
            $request->uuid ??= (string) Str::uuid();
            $prefix = match ($request->kind) {
                'expense' => 'ER',
                'expense_request' => 'CR',
                default => 'TR',
            };
            $request->reference_no ??= $prefix.'-'.now()->format('ymd').'-'.Str::upper(Str::random(5));
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitter_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(PersonalRequestAttachment::class)->orderBy('sort_order')->orderBy('id');
    }

    public function progressLogs(): HasMany
    {
        return $this->hasMany(PersonalRequestProgressLog::class)->orderBy('created_at')->orderBy('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PersonalRequestPayment::class)->orderBy('paid_on')->orderBy('id');
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected function casts(): array
    {
        return [
            'desired_date' => 'date', 'expense_date' => 'date', 'paid_on' => 'date', 'next_renewal_on' => 'date', 'amount' => 'decimal:2',
            'software_account' => 'encrypted', 'software_password' => 'encrypted',
            'reviewed_at' => 'datetime', 'completed_at' => 'datetime',
        ];
    }
}
