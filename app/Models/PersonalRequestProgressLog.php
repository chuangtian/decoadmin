<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['uuid', 'personal_request_id', 'user_id', 'action', 'content'])]
class PersonalRequestProgressLog extends Model
{
    protected static function booted(): void
    {
        static::creating(function (PersonalRequestProgressLog $log): void {
            $log->uuid ??= (string) Str::uuid();
        });
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(PersonalRequest::class, 'personal_request_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
