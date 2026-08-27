<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

#[Fillable([
    'uuid', 'organization_id', 'store_id', 'name', 'email', 'normalized_email', 'privacy_consented_at', 'source', 'status',
    'review_method', 'evidence_disk', 'evidence_path', 'evidence_mime', 'evidence_size',
    'evidence_deleted_at', 'recognition_result', 'confidence', 'model_name', 'recognized_at',
    'recognition_failure_code',
    'reviewed_at', 'reviewed_by', 'rejection_reason', 'idempotency_key', 'request_fingerprint',
    'claim_token_hash', 'claim_token_encrypted', 'submission_count', 'superseded_by_claim_id', 'superseded_at',
    'email_sent_at', 'email_failed_at',
])]
#[Hidden(['evidence_disk', 'evidence_path', 'recognition_result', 'claim_token_hash', 'claim_token_encrypted', 'request_fingerprint'])]
class StudentDiscountClaim extends Model
{
    use SoftDeletes;

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function discountCode(): HasOne
    {
        return $this->hasOne(StudentDiscountCode::class, 'claim_id');
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_claim_id');
    }

    public function supersededClaims(): HasMany
    {
        return $this->hasMany(self::class, 'superseded_by_claim_id');
    }

    protected static function booted(): void
    {
        static::creating(function (StudentDiscountClaim $claim): void {
            $claim->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'evidence_path' => 'encrypted',
            'recognition_result' => 'encrypted:array',
            'claim_token_encrypted' => 'encrypted',
            'confidence' => 'decimal:2',
            'recognized_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'privacy_consented_at' => 'datetime',
            'superseded_at' => 'datetime',
            'evidence_deleted_at' => 'datetime',
            'submission_count' => 'integer',
            'email_sent_at' => 'datetime',
            'email_failed_at' => 'datetime',
        ];
    }
}
