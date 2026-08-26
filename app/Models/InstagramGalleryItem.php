<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['gallery_id', 'media_id', 'position'])]
class InstagramGalleryItem extends Model
{
    public function gallery(): BelongsTo
    {
        return $this->belongsTo(InstagramGallery::class, 'gallery_id');
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(InstagramMedia::class, 'media_id');
    }

    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }
}
