<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable([
    'uuid', 'design_request_id', 'uploaded_by', 'disk', 'path', 'original_name',
    'mime_type', 'size', 'sort_order',
])]
class DesignRequestAttachment extends Model
{
    protected static function booted(): void
    {
        static::creating(function (DesignRequestAttachment $attachment): void {
            $attachment->uuid ??= (string) Str::uuid();
        });
    }

    public function designRequest(): BelongsTo
    {
        return $this->belongsTo(DesignRequest::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'sort_order' => 'integer',
        ];
    }
}
