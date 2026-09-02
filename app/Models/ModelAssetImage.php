<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable([
    'uuid', 'organization_id', 'store_id', 'folder_id', 'uploaded_by', 'disk', 'path', 'thumbnail_path',
    'original_name', 'mime_type', 'byte_size', 'width', 'height',
])]
class ModelAssetImage extends Model
{
    public function folder(): BelongsTo
    {
        return $this->belongsTo(ModelAssetFolder::class, 'folder_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    protected static function booted(): void
    {
        static::creating(function (ModelAssetImage $image): void {
            $image->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'byte_size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }
}
