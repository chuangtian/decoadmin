<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['uuid', 'organization_id', 'store_id', 'name', 'handle', 'position', 'created_by'])]
class InstagramGallery extends Model
{
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InstagramGalleryItem::class, 'gallery_id')->orderBy('position');
    }

    public function media(): BelongsToMany
    {
        return $this->belongsToMany(InstagramMedia::class, 'instagram_gallery_items', 'gallery_id', 'media_id')
            ->withPivot(['position'])
            ->orderBy('instagram_gallery_items.position');
    }

    protected static function booted(): void
    {
        static::creating(function (InstagramGallery $gallery): void {
            $gallery->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }
}
