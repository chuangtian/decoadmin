<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['uuid', 'personal_request_id', 'uploaded_by', 'disk', 'path', 'original_name', 'mime_type', 'size', 'sort_order'])]
class PersonalRequestAttachment extends Model
{
    protected static function booted(): void
    {
        static::creating(function (PersonalRequestAttachment $attachment): void {
            $attachment->uuid ??= (string) Str::uuid();
        });
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(PersonalRequest::class, 'personal_request_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
