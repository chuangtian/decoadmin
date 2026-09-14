<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['uuid', 'design_request_id', 'user_id', 'action', 'content'])]
class DesignRequestProgressLog extends Model
{
    protected static function booted(): void
    {
        static::creating(function (DesignRequestProgressLog $log): void {
            $log->uuid ??= (string) Str::uuid();
        });
    }

    public function designRequest(): BelongsTo
    {
        return $this->belongsTo(DesignRequest::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
