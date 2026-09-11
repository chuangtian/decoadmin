<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 单个店铺的 Instagram Feed 应用配置：Meta 应用凭证 + Cloudflare R2 存储凭证。
 *
 * 商家在 Shopify App 内嵌页里维护，所以三个密钥列一律 encrypted cast 且 Hidden，
 * 不会随模型序列化外泄。留空的项由 InstagramFeedStoreCredentials 回退到平台级配置。
 */
#[Fillable([
    'organization_id', 'store_id',
    'instagram_app_id', 'instagram_app_secret',
    'facebook_app_id', 'facebook_app_secret', 'facebook_login_config_id',
    'r2_account_id', 'r2_access_key_id', 'r2_secret_access_key', 'r2_bucket', 'r2_public_base_url',
    'updated_by', 'updated_from',
])]
#[Hidden(['instagram_app_secret', 'facebook_app_secret', 'r2_secret_access_key'])]
class InstagramFeedStoreSetting extends Model
{
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    protected function casts(): array
    {
        return [
            'instagram_app_secret' => 'encrypted',
            'facebook_app_secret' => 'encrypted',
            'r2_secret_access_key' => 'encrypted',
        ];
    }
}
