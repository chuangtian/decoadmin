<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['store_id', 'shopify_shop_id', 'shop_domain', 'access_token_encrypted', 'refresh_token_encrypted', 'token_type', 'access_token_expires_at', 'scopes', 'api_version', 'last_api_check', 'status', 'installed_at', 'uninstalled_at', 'last_verified_at', 'last_error', 'last_error_at', 'metadata'])]
#[Hidden(['access_token_encrypted', 'refresh_token_encrypted'])]
class ShopifyConnection extends Model
{
    use SoftDeletes;

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function appInstallations(): HasMany
    {
        return $this->hasMany(AppInstallation::class);
    }

    public function webhookEvents(): HasMany
    {
        return $this->hasMany(WebhookEvent::class);
    }

    protected function casts(): array
    {
        return [
            'shopify_shop_id' => 'integer',
            'access_token_encrypted' => 'encrypted',
            'refresh_token_encrypted' => 'encrypted',
            'access_token_expires_at' => 'datetime',
            'scopes' => 'array',
            'last_api_check' => 'datetime',
            'installed_at' => 'datetime',
            'uninstalled_at' => 'datetime',
            'last_verified_at' => 'datetime',
            'last_error_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
