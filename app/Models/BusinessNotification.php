<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

#[Fillable([
    'uuid', 'organization_id', 'user_id', 'actor_id', 'type', 'title', 'message', 'action_url',
    'subject_type', 'subject_id', 'dedupe_key', 'read_at',
])]
class BusinessNotification extends Model
{
    protected static function booted(): void
    {
        static::creating(function (BusinessNotification $notification): void {
            $notification->uuid ??= (string) Str::uuid();
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }
}
