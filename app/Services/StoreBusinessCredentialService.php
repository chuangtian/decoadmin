<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Store;
use App\Models\StoreBusinessCredential;
use App\Models\User;
use Illuminate\Support\Collection;

class StoreBusinessCredentialService
{
    /**
     * This catalog is the allow-list for credentials accepted by the application.
     * Secret values are never included in the initial Inertia payload.
     *
     * @var array<string, array{
     *     title: string,
     *     description: string,
     *     setup_hint: string,
     *     oauth_label?: string,
     *     fields: array<string, array{label: string, env_key: string, secret: bool, placeholder: string}>
     * }>
     */
    private const CATALOG = [
        'meta_ads' => [
            'title' => 'Facebook / Meta Ads',
            'description' => 'Meta Business Suite 系统用户 Access Token，至少需要 ads_read 权限。',
            'setup_hint' => 'Meta Business Suite → 商务设置 → 系统用户 → 生成 Access Token',
            'fields' => [
                'access_token' => ['label' => 'Access Token', 'env_key' => 'FB_ACCESS_TOKEN', 'secret' => true, 'placeholder' => '粘贴 Meta Business Suite 系统用户 Access Token'],
            ],
        ],
        'tiktok_ads' => [
            'title' => 'TikTok Ads',
            'description' => 'TikTok for Business 开发者应用和广告主账号接入。',
            'setup_hint' => 'TikTok for Business → 开发者中心 → 创建应用',
            'fields' => [
                'access_token' => ['label' => 'Access Token', 'env_key' => 'TK_ACCESS_TOKEN', 'secret' => true, 'placeholder' => '输入 TikTok Ads Access Token'],
                'app_id' => ['label' => 'App ID', 'env_key' => 'TK_APP_ID', 'secret' => false, 'placeholder' => '输入 TikTok for Business App ID'],
                'app_secret' => ['label' => 'App Secret', 'env_key' => 'TK_APP_SECRET', 'secret' => true, 'placeholder' => '输入 TikTok for Business App Secret'],
                'advertiser_ids' => ['label' => '广告主 ID', 'env_key' => 'TK_ADVERTISER_IDS', 'secret' => false, 'placeholder' => '输入广告主 ID；多个 ID 请用英文逗号分隔'],
            ],
        ],
        'google_ads' => [
            'title' => 'Google Ads',
            'description' => 'Google Ads OAuth、开发者 Token 和广告账户配置。',
            'setup_hint' => 'Google Cloud Console OAuth 凭据 + Google Ads API 中心',
            'oauth_label' => 'Google Ads OAuth 授权',
            'fields' => [
                'client_id' => ['label' => 'Client ID', 'env_key' => 'GOOGLE_ADS_CLIENT_ID', 'secret' => false, 'placeholder' => '输入 Google Ads OAuth Client ID'],
                'client_secret' => ['label' => 'Client Secret', 'env_key' => 'GOOGLE_ADS_CLIENT_SECRET', 'secret' => true, 'placeholder' => '输入 Google Ads OAuth Client Secret'],
                'refresh_token' => ['label' => 'Refresh Token', 'env_key' => 'GOOGLE_ADS_REFRESH_TOKEN', 'secret' => true, 'placeholder' => '输入 Google Ads OAuth Refresh Token'],
                'developer_token' => ['label' => 'Developer Token', 'env_key' => 'GOOGLE_ADS_DEVELOPER_TOKEN', 'secret' => true, 'placeholder' => '输入 Google Ads Developer Token'],
                'customer_id' => ['label' => 'Customer ID', 'env_key' => 'GOOGLE_ADS_CUSTOMER_ID', 'secret' => false, 'placeholder' => '输入 Google Ads Customer ID'],
                'login_customer_id' => ['label' => 'Login Customer ID', 'env_key' => 'GOOGLE_ADS_LOGIN_CUSTOMER_ID', 'secret' => false, 'placeholder' => '可选：输入经理账户 Login Customer ID'],
            ],
        ],
        'bing_ads' => [
            'title' => 'Bing / Microsoft Ads',
            'description' => 'Microsoft Advertising OAuth、开发者 Token 和账户配置。',
            'setup_hint' => 'Azure Portal 应用注册 + Microsoft Advertising 开发人员令牌',
            'oauth_label' => 'Bing Ads OAuth 授权',
            'fields' => [
                'developer_token' => ['label' => 'Developer Token', 'env_key' => 'BING_ADS_DEVELOPER_TOKEN', 'secret' => true, 'placeholder' => '输入 Microsoft Advertising Developer Token'],
                'client_id' => ['label' => 'Client ID', 'env_key' => 'BING_ADS_CLIENT_ID', 'secret' => false, 'placeholder' => '输入 Azure 应用 Client ID'],
                'client_secret' => ['label' => 'Client Secret', 'env_key' => 'BING_ADS_CLIENT_SECRET', 'secret' => true, 'placeholder' => '输入 Azure 应用 Client Secret'],
                'account_id' => ['label' => 'Account ID', 'env_key' => 'BING_ADS_ACCOUNT_ID', 'secret' => false, 'placeholder' => '输入 Microsoft Advertising Account ID'],
                'refresh_token' => ['label' => 'Refresh Token', 'env_key' => 'BING_ADS_REFRESH_TOKEN', 'secret' => true, 'placeholder' => '输入 Microsoft Advertising Refresh Token'],
                'customer_id' => ['label' => 'Customer ID', 'env_key' => 'BING_ADS_CUSTOMER_ID', 'secret' => false, 'placeholder' => '可选：输入 Microsoft Advertising Customer ID'],
            ],
        ],
        'criteo' => [
            'title' => 'Criteo',
            'description' => 'Criteo API 用户 client_id / client_secret 和广告主 ID。',
            'setup_hint' => 'Criteo Management Center → 设置 → API 用户管理',
            'fields' => [
                'api_key' => ['label' => 'API Key / Client ID', 'env_key' => 'CRITEO_API_KEY', 'secret' => false, 'placeholder' => '输入 Criteo API Key / Client ID'],
                'client_secret' => ['label' => 'Client Secret', 'env_key' => 'CRITEO_CLIENT_SECRET', 'secret' => true, 'placeholder' => '输入 Criteo Client Secret'],
                'advertiser_id' => ['label' => 'Advertiser ID', 'env_key' => 'CRITEO_ADVERTISER_ID', 'secret' => false, 'placeholder' => '输入 Criteo Advertiser ID'],
            ],
        ],
        'youtube_analytics' => [
            'title' => 'YouTube Analytics',
            'description' => 'YouTube OAuth 2.0，用于获取频道分析数据（观看量、互动、流量来源等）。',
            'setup_hint' => 'Google Cloud Console → OAuth 2.0 → 启用 YouTube Data API v3 + YouTube Analytics API',
            'oauth_label' => 'YouTube OAuth 授权',
            'fields' => [
                'client_id' => ['label' => 'Client ID', 'env_key' => 'YOUTUBE_CLIENT_ID', 'secret' => false, 'placeholder' => '输入 YouTube OAuth Client ID'],
                'client_secret' => ['label' => 'Client Secret', 'env_key' => 'YOUTUBE_CLIENT_SECRET', 'secret' => true, 'placeholder' => '输入 YouTube OAuth Client Secret'],
            ],
        ],
        'google_search_console_ga4' => [
            'title' => 'Google Search Console / GA4',
            'description' => 'GSC OAuth + GA4 服务账号，用于获取搜索性能和分析数据。',
            'setup_hint' => 'Google Cloud Console → OAuth / Service Account',
            'oauth_label' => 'Google Search Console OAuth 授权',
            'fields' => [
                'gsc_client_id' => ['label' => 'GSC Client ID', 'env_key' => 'GSC_CLIENT_ID', 'secret' => false, 'placeholder' => '输入 Google Search Console OAuth Client ID'],
                'gsc_client_secret' => ['label' => 'GSC Client Secret', 'env_key' => 'GSC_CLIENT_SECRET', 'secret' => true, 'placeholder' => '输入 Google Search Console OAuth Client Secret'],
                'gsc_refresh_token' => ['label' => 'GSC Refresh Token', 'env_key' => 'GSC_REFRESH_TOKEN', 'secret' => true, 'placeholder' => '输入 Google Search Console Refresh Token'],
                'gsc_site_url' => ['label' => '站点 URL', 'env_key' => 'GSC_SITE_URL', 'secret' => false, 'placeholder' => '例如：https://example.com'],
                'ga4_property_id' => ['label' => 'GA4 Property ID', 'env_key' => 'GA4_PROPERTY_ID', 'secret' => false, 'placeholder' => '输入 GA4 Property ID'],
                'ga4_service_account_json' => ['label' => 'GA4 服务账号 JSON', 'env_key' => 'GA4_SERVICE_ACCOUNT_JSON', 'secret' => true, 'placeholder' => '粘贴 GA4 服务账号 JSON'],
            ],
        ],
    ];

    /** @return array<int, array<string, mixed>> */
    public function catalogForFrontend(Store $store): array
    {
        $credentials = StoreBusinessCredential::query()
            ->where('organization_id', $store->organization_id)
            ->where('store_id', $store->id)
            ->whereIn('provider', array_keys(self::CATALOG))
            ->get()
            ->keyBy(fn (StoreBusinessCredential $credential): string => $credential->provider.'.'.$credential->credential_key);

        return collect(self::CATALOG)
            ->map(fn (array $provider, string $providerKey): array => $this->providerForFrontend($providerKey, $provider, $credentials))
            ->values()
            ->all();
    }

    public function reveal(Store $store, string $provider, string $credentialKey): string
    {
        $this->fieldDefinition($provider, $credentialKey);

        return $this->credential($store, $provider, $credentialKey)?->credential_value ?? '';
    }

    public function update(Store $store, string $provider, string $credentialKey, ?string $value, User $actor): ?StoreBusinessCredential
    {
        $this->fieldDefinition($provider, $credentialKey);
        $value = trim((string) $value);
        $credential = $this->credential($store, $provider, $credentialKey);

        if ($value === '') {
            return $credential;
        }

        $wasConfigured = $credential !== null;
        $credential ??= new StoreBusinessCredential;
        $credential->fill([
            'organization_id' => $store->organization_id,
            'store_id' => $store->id,
            'provider' => $provider,
            'credential_key' => $credentialKey,
            'credential_value' => $value,
            'updated_by' => $actor->id,
        ])->save();

        $this->audit($store, $actor, 'store_business_credential_updated', $provider, $credentialKey, $credential, $wasConfigured, true);

        return $credential;
    }

    public function clear(Store $store, string $provider, string $credentialKey, User $actor): void
    {
        $this->fieldDefinition($provider, $credentialKey);
        $credential = $this->credential($store, $provider, $credentialKey);

        if (! $credential) {
            return;
        }

        $credentialId = $credential->id;
        $credential->delete();
        $this->audit($store, $actor, 'store_business_credential_cleared', $provider, $credentialKey, null, true, false, $credentialId);
    }

    /** @return array{label: string, env_key: string, secret: bool, placeholder: string} */
    public function fieldDefinition(string $provider, string $credentialKey): array
    {
        abort_unless(isset(self::CATALOG[$provider]['fields'][$credentialKey]), 404);

        return self::CATALOG[$provider]['fields'][$credentialKey];
    }

    /** @param Collection<string, StoreBusinessCredential> $credentials */
    private function providerForFrontend(string $providerKey, array $provider, Collection $credentials): array
    {
        $fields = collect($provider['fields'])->map(function (array $field, string $fieldKey) use ($credentials, $providerKey): array {
            $credential = $credentials->get($providerKey.'.'.$fieldKey);

            return [
                'key' => $fieldKey,
                'label' => $field['label'],
                'env_key' => $field['env_key'],
                'secret' => $field['secret'],
                'placeholder' => $field['placeholder'],
                'configured' => $credential !== null,
                'masked_value' => $credential ? $this->mask($credential->credential_value) : '',
                'current_value' => $credential && ! $field['secret'] ? $credential->credential_value : '',
            ];
        })->values()->all();

        return [
            'key' => $providerKey,
            'title' => $provider['title'],
            'description' => $provider['description'],
            'setup_hint' => $provider['setup_hint'],
            'oauth_label' => $provider['oauth_label'] ?? null,
            'configured' => collect($fields)->contains(fn (array $field): bool => $field['configured']),
            'fields' => $fields,
        ];
    }

    private function credential(Store $store, string $provider, string $credentialKey): ?StoreBusinessCredential
    {
        return StoreBusinessCredential::query()
            ->where('organization_id', $store->organization_id)
            ->where('store_id', $store->id)
            ->where('provider', $provider)
            ->where('credential_key', $credentialKey)
            ->first();
    }

    private function mask(string $value): string
    {
        $length = mb_strlen($value);

        if ($length <= 8) {
            return str_repeat('•', max(4, $length));
        }

        return mb_substr($value, 0, 3).'***'.mb_substr($value, -3);
    }

    private function audit(
        Store $store,
        User $actor,
        string $action,
        string $provider,
        string $credentialKey,
        ?StoreBusinessCredential $credential,
        bool $wasConfigured,
        bool $configured,
        ?int $subjectId = null,
    ): void {
        AuditLog::query()->create([
            'organization_id' => $store->organization_id,
            'store_id' => $store->id,
            'user_id' => $actor->id,
            'action' => $action,
            'subject_type' => StoreBusinessCredential::class,
            'subject_id' => $credential?->id ?? $subjectId,
            'old_values' => ['configured' => $wasConfigured],
            'new_values' => ['configured' => $configured],
            'metadata' => [
                'scope' => 'store',
                'provider' => $provider,
                'credential_key' => $credentialKey,
            ],
        ]);
    }
}
