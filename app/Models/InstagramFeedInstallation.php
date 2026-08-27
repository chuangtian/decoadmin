<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable([
    'uuid', 'organization_id', 'store_id', 'environment', 'app_installation_id',
    'access_token_encrypted', 'granted_scopes', 'installed_at', 'last_verified_at',
    'last_published_at',
])]
#[Hidden(['access_token_encrypted'])]
class InstagramFeedInstallation extends Model
{
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /** 是否可以对 Shopify Admin API 发起请求。 */
    public function isUsable(): bool
    {
        return filled($this->access_token_encrypted)
            && filled($this->app_installation_id)
            && $this->environment === (string) config('instagram_feed.environment');
    }

    protected static function booted(): void
    {
        static::creating(function (InstagramFeedInstallation $installation): void {
            $installation->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'access_token_encrypted' => 'encrypted',
            'granted_scopes' => 'array',
            'installed_at' => 'datetime',
            'last_verified_at' => 'datetime',
            'last_published_at' => 'datetime',
        ];
    }
}
