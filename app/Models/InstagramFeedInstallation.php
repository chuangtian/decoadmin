<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable([
    'uuid', 'organization_id', 'store_id', 'environment', 'status', 'app_installation_id',
    'access_token_encrypted', 'granted_scopes', 'installed_by', 'installed_at', 'uninstalled_at',
    'last_verified_at', 'last_api_check', 'last_published_at', 'last_error', 'last_error_at',
])]
#[Hidden(['access_token_encrypted'])]
class InstagramFeedInstallation extends Model
{
    public const STATUS_CONNECTED = 'connected';

    public const STATUS_WARNING = 'warning';

    public const STATUS_INVALID = 'invalid';

    public const STATUS_DISCONNECTED = 'disconnected';

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function installedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'installed_by');
    }

    /** 是否可以对 Shopify Admin API 发起请求。 */
    public function isUsable(): bool
    {
        return filled($this->access_token_encrypted)
            && filled($this->app_installation_id)
            && $this->uninstalled_at === null
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
            'uninstalled_at' => 'datetime',
            'last_verified_at' => 'datetime',
            'last_api_check' => 'datetime',
            'last_published_at' => 'datetime',
            'last_error_at' => 'datetime',
        ];
    }
}
