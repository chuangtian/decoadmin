<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['organization_id', 'store_id', 'ingest_key', 'web_pixel_id', 'status', 'activated_at', 'last_event_at', 'purge_after'])]
class PersonalizationEventSource extends Model
{
    public function getRouteKeyName(): string
    {
        return 'ingest_key';
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(PersonalizationEvent::class, 'event_source_id');
    }

    protected static function booted(): void
    {
        static::creating(function (PersonalizationEventSource $source): void {
            $source->ingest_key ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'activated_at' => 'datetime',
            'last_event_at' => 'datetime',
            'purge_after' => 'datetime',
        ];
    }
}
