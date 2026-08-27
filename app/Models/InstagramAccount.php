<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable([
    'uuid', 'organization_id', 'store_id', 'provider', 'status', 'ig_user_id', 'username',
    'account_type', 'profile_picture_url', 'access_token_encrypted', 'token_expires_at',
    'fb_user_id', 'fb_user_token_encrypted', 'fb_token_expires_at', 'page_id', 'page_name',
    'last_refreshed_at', 'last_synced_at', 'last_published_at', 'connected_by',
])]
#[Hidden(['access_token_encrypted', 'fb_user_token_encrypted'])]
class InstagramAccount extends Model
{
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(InstagramMedia::class, 'account_id');
    }

    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by');
    }

    /** 账号是否已经可以正常拉数据。 */
    public function isUsable(): bool
    {
        return $this->status === 'connected'
            && filled($this->access_token_encrypted)
            && filled($this->ig_user_id);
    }

    public function usesFacebookLogin(): bool
    {
        return $this->provider === 'facebook_login';
    }

    public function providerLabel(): string
    {
        return $this->usesFacebookLogin() ? 'Facebook 主页授权' : 'Instagram 账号授权';
    }

    public function profileUrl(): ?string
    {
        return filled($this->username) ? 'https://www.instagram.com/'.$this->username.'/' : null;
    }

    protected static function booted(): void
    {
        static::creating(function (InstagramAccount $account): void {
            $account->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'access_token_encrypted' => 'encrypted',
            'fb_user_token_encrypted' => 'encrypted',
            'token_expires_at' => 'datetime',
            'fb_token_expires_at' => 'datetime',
            'last_refreshed_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'last_published_at' => 'datetime',
        ];
    }
}
