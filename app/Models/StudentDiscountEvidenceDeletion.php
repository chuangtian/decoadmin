<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable([
    'uuid', 'organization_id', 'store_id', 'claim_id', 'disk', 'path', 'status', 'attempts',
    'last_error_code', 'last_attempted_at', 'completed_at',
])]
#[Hidden(['disk', 'path'])]
class StudentDiscountEvidenceDeletion extends Model
{
    public function claim(): BelongsTo
    {
        return $this->belongsTo(StudentDiscountClaim::class, 'claim_id');
    }

    protected static function booted(): void
    {
        static::creating(function (StudentDiscountEvidenceDeletion $deletion): void {
            $deletion->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'path' => 'encrypted',
            'attempts' => 'integer',
            'last_attempted_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
