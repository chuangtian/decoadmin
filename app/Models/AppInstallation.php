<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['app_id', 'store_id', 'shopify_connection_id', 'installed_by', 'external_installation_id', 'status', 'granted_scopes', 'access_token_encrypted', 'refresh_token_encrypted', 'token_type', 'access_token_expires_at', 'refresh_token_expires_at', 'settings', 'installed_at', 'uninstalled_at'])]
#[Hidden(['access_token_encrypted', 'refresh_token_encrypted'])]
class AppInstallation extends Model
{
    use SoftDeletes;

    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function shopifyConnection(): BelongsTo
    {
        return $this->belongsTo(ShopifyConnection::class);
    }

    public function installedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'installed_by');
    }

    protected function casts(): array
    {
        return [
            'granted_scopes' => 'array',
            'access_token_encrypted' => 'encrypted',
            'refresh_token_encrypted' => 'encrypted',
            'access_token_expires_at' => 'datetime',
            'refresh_token_expires_at' => 'datetime',
            'settings' => 'array',
            'installed_at' => 'datetime',
            'uninstalled_at' => 'datetime',
        ];
    }
}
