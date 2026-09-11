<?php

namespace App\Services\InstagramFeed;

use App\Models\AuditLog;
use App\Models\InstagramFeedStoreSetting;
use App\Models\Store;
use App\Models\User;

/**
 * Instagram Feed 应用配置的店铺级解析。
 *
 * 为什么按店铺存：Meta 应用凭证与 R2 存储凭证由商家自己在 Shopify App 内嵌页维护，
 * 而内嵌页没有 DecoAdmin 用户、没有按人 RBAC。如果仍存平台级，任何一个店铺员工都能
 * 改掉全平台共享的密钥。按店铺隔离后，越权范围就收敛回「只影响自己店铺」。
 *
 * 生效顺序：店铺级有值 → 平台级（system_settings）→ .env。留空即回退，不是清空。
 *
 * 实现方式沿用 SystemSettingsService::applyInstagramFeedConfiguration() 的范式：
 * 把生效值覆盖进 config('instagram_feed.*')。R2Client / InstagramApiClient /
 * FacebookApiClient 都是调用时才读 config，所以它们不需要任何改动。
 *
 * 注意：apply() 是进程内全局覆盖。同一进程里连续处理多个店铺（定时任务）时，
 * 每个店铺都必须重新 apply()，否则会串用上一个店铺的凭证 —— 所以这里始终写入
 * 完整的一组值，缺失项显式回落到 baseline，不做增量覆盖。
 */
class InstagramFeedStoreCredentials
{
    /** 内嵌页与后台共用的字段清单，顺序即表单顺序。 */
    public const META_KEYS = [
        'instagram_app_id',
        'instagram_app_secret',
        'facebook_app_id',
        'facebook_app_secret',
        'facebook_login_config_id',
    ];

    public const R2_KEYS = [
        'account_id',
        'access_key_id',
        'secret_access_key',
        'bucket',
        'public_base_url',
    ];

    /** 只写字段：永不回显，提交空值表示保持原值。 */
    public const WRITE_ONLY_KEYS = [
        'instagram_app_secret',
        'facebook_app_secret',
        'secret_access_key',
    ];

    /** R2 字段在数据库里带 r2_ 前缀，config 里不带。 */
    private const R2_COLUMN_PREFIX = 'r2_';

    /**
     * 平台级基线。首次 apply() 时快照，之后每次 apply() 都以它为回退值。
     *
     * 必须快照：apply() 会把 config 改成某个店铺的值，如果不留基线，
     * 下一个没配凭证的店铺就会继续用上一个店铺的凭证。
     *
     * @var array{instagram: array<string, mixed>, facebook: array<string, mixed>, r2: array<string, mixed>}|null
     */
    private ?array $baseline = null;

    /** 把该店铺的生效凭证覆盖进 config。所有读凭证的动作之前都要先调一次。 */
    public function apply(Store $store): void
    {
        $baseline = $this->baseline();
        $setting = $this->setting($store);

        $meta = $this->resolveMeta($setting, $baseline);
        $r2 = $this->resolveR2($setting, $baseline);

        config([
            'instagram_feed.instagram.app_id' => $meta['instagram_app_id'],
            'instagram_feed.instagram.app_secret' => $meta['instagram_app_secret'],
            'instagram_feed.facebook.app_id' => $meta['facebook_app_id'],
            'instagram_feed.facebook.app_secret' => $meta['facebook_app_secret'],
            'instagram_feed.facebook.login_config_id' => $meta['facebook_login_config_id'] ?: null,
            'instagram_feed.r2.account_id' => $r2['account_id'],
            'instagram_feed.r2.access_key_id' => $r2['access_key_id'],
            'instagram_feed.r2.secret_access_key' => $r2['secret_access_key'],
            'instagram_feed.r2.bucket' => $r2['bucket'],
            'instagram_feed.r2.public_base_url' => $r2['public_base_url'],
        ]);
    }

    /**
     * 内嵌页「应用配置」页签的数据。
     *
     * 密钥一律不回显，只给 *_configured 布尔值；同时告诉前端每一组是店铺自己配的
     * 还是在用平台默认，否则商家看到空表单会以为没配过。
     *
     * @return array<string, mixed>
     */
    public function forFrontend(Store $store): array
    {
        $baseline = $this->baseline();
        $setting = $this->setting($store);

        $meta = $this->resolveMeta($setting, $baseline);
        $r2 = $this->resolveR2($setting, $baseline);

        return [
            'meta' => [
                'instagram_app_id' => $meta['instagram_app_id'],
                'instagram_app_secret' => '',
                'facebook_app_id' => $meta['facebook_app_id'],
                'facebook_app_secret' => '',
                'facebook_login_config_id' => $meta['facebook_login_config_id'],
                'instagram_app_secret_configured' => $meta['instagram_app_secret'] !== '',
                'facebook_app_secret_configured' => $meta['facebook_app_secret'] !== '',
            ],
            'r2' => [
                'account_id' => $r2['account_id'],
                'access_key_id' => $r2['access_key_id'],
                'secret_access_key' => '',
                'bucket' => $r2['bucket'],
                'public_base_url' => $r2['public_base_url'],
                'secret_access_key_configured' => $r2['secret_access_key'] !== '',
            ],
            // 回调地址是环境级的，所有店铺共用同一个，必须原样填进各自的 Meta 应用。
            'callbacks' => [
                'instagram' => (string) config('instagram_feed.instagram.redirect_uri'),
                'facebook' => (string) config('instagram_feed.facebook.redirect_uri'),
            ],
            'source' => [
                'meta' => $this->sectionSource($setting, self::META_KEYS),
                'r2' => $this->sectionSource($setting, self::R2_KEYS),
            ],
        ];
    }

    /**
     * 写入店铺级配置。
     *
     * 只写密钥留空表示保持原值 —— 前端从不回显密钥，如果按空值落库，商家改一次
     * App ID 就会把密钥抹掉。
     *
     * @param  'meta'|'r2'  $section
     * @param  array<string, mixed>  $values
     * @return list<string> 真正发生变化的字段名
     */
    public function update(
        string $section,
        Store $store,
        array $values,
        ?User $actor,
        ?string $shopDomain = null,
    ): array {
        $keys = $section === 'meta' ? self::META_KEYS : self::R2_KEYS;
        $setting = $this->setting($store);

        $changed = [];
        $attributes = [];
        foreach ($keys as $key) {
            if (! array_key_exists($key, $values)) {
                continue;
            }

            $value = is_string($values[$key]) ? trim($values[$key]) : $values[$key];
            $column = $this->column($section, $key);

            // 留空的只写密钥：跳过，保持原值。
            if (in_array($key, self::WRITE_ONLY_KEYS, true) && blank($value)) {
                continue;
            }

            $normalized = $key === 'public_base_url' && is_string($value)
                ? rtrim($value, '/')
                : $value;
            $stored = blank($normalized) ? null : (string) $normalized;

            if ((string) ($setting?->{$column} ?? '') !== (string) ($stored ?? '')) {
                $changed[] = $key;
            }
            $attributes[$column] = $stored;
        }

        if ($attributes === []) {
            return [];
        }

        InstagramFeedStoreSetting::query()->updateOrCreate(
            ['store_id' => $store->id],
            [
                ...$attributes,
                'organization_id' => $store->organization_id,
                'updated_by' => $actor?->getKey(),
                'updated_from' => $actor ? 'admin' : 'shopify_app_session',
            ],
        );

        if ($changed !== []) {
            AuditLog::query()->create([
                'organization_id' => $store->organization_id,
                'store_id' => $store->id,
                'user_id' => $actor?->getKey(),
                'action' => 'instagram_feed_store_settings_updated',
                'metadata' => [
                    'section' => $section,
                    // 只记字段名，不记值。
                    'changed_keys' => $changed,
                    'actor_type' => $actor ? 'user' : 'shopify_app_session',
                    'shop_domain' => $shopDomain ?? $store->shopify_domain,
                ],
            ]);
        }

        // 本次请求后续动作（例如保存后立刻探活）要用新值。
        $this->apply($store->refresh());

        return $changed;
    }

    /**
     * 所有店铺级 Meta 应用密钥，供 signed_request 验签逐个尝试。
     *
     * Meta 的 deauthorize / data-deletion 回调里没有店铺信息，只能拿全部候选密钥
     * 去比对，所以这里返回去重后的集合。
     *
     * @return list<string>
     */
    public function allMetaAppSecrets(): array
    {
        $secrets = [];
        InstagramFeedStoreSetting::query()
            ->whereNotNull('store_id')
            ->get(['instagram_app_secret', 'facebook_app_secret'])
            ->each(function (InstagramFeedStoreSetting $setting) use (&$secrets): void {
                foreach ([$setting->instagram_app_secret, $setting->facebook_app_secret] as $secret) {
                    if (filled($secret)) {
                        $secrets[] = (string) $secret;
                    }
                }
            });

        return array_values(array_unique($secrets));
    }

    private function setting(Store $store): ?InstagramFeedStoreSetting
    {
        return InstagramFeedStoreSetting::query()->where('store_id', $store->id)->first();
    }

    /**
     * @param  array{instagram: array<string, mixed>, facebook: array<string, mixed>, r2: array<string, mixed>}  $baseline
     * @return array<string, string>
     */
    private function resolveMeta(?InstagramFeedStoreSetting $setting, array $baseline): array
    {
        return [
            'instagram_app_id' => $this->pick($setting, 'instagram_app_id', $baseline['instagram']['app_id'] ?? ''),
            'instagram_app_secret' => $this->pick($setting, 'instagram_app_secret', $baseline['instagram']['app_secret'] ?? ''),
            'facebook_app_id' => $this->pick($setting, 'facebook_app_id', $baseline['facebook']['app_id'] ?? ''),
            'facebook_app_secret' => $this->pick($setting, 'facebook_app_secret', $baseline['facebook']['app_secret'] ?? ''),
            'facebook_login_config_id' => $this->pick(
                $setting,
                'facebook_login_config_id',
                $baseline['facebook']['login_config_id'] ?? '',
            ),
        ];
    }

    /**
     * @param  array{instagram: array<string, mixed>, facebook: array<string, mixed>, r2: array<string, mixed>}  $baseline
     * @return array<string, string>
     */
    private function resolveR2(?InstagramFeedStoreSetting $setting, array $baseline): array
    {
        $resolved = [];
        foreach (self::R2_KEYS as $key) {
            $resolved[$key] = $this->pick(
                $setting,
                self::R2_COLUMN_PREFIX.$key,
                $baseline['r2'][$key] ?? '',
            );
        }
        $resolved['public_base_url'] = rtrim($resolved['public_base_url'], '/');

        return $resolved;
    }

    private function pick(?InstagramFeedStoreSetting $setting, string $column, mixed $fallback): string
    {
        $value = $setting?->{$column};

        return filled($value) ? trim((string) $value) : trim((string) $fallback);
    }

    /**
     * 这一组是店铺自己配的，还是在用平台默认？只要有任意一个字段落库就算 store。
     *
     * @param  list<string>  $keys
     */
    private function sectionSource(?InstagramFeedStoreSetting $setting, array $keys): string
    {
        if (! $setting) {
            return 'platform';
        }

        $prefix = $keys === self::R2_KEYS ? self::R2_COLUMN_PREFIX : '';
        foreach ($keys as $key) {
            if (filled($setting->{$prefix.$key})) {
                return 'store';
            }
        }

        return 'platform';
    }

    private function column(string $section, string $key): string
    {
        return $section === 'r2' ? self::R2_COLUMN_PREFIX.$key : $key;
    }

    /**
     * @return array{instagram: array<string, mixed>, facebook: array<string, mixed>, r2: array<string, mixed>}
     */
    private function baseline(): array
    {
        // 首次调用时 config 里还是平台级值（AppServiceProvider 启动期已应用 system_settings）。
        return $this->baseline ??= [
            'instagram' => [
                'app_id' => (string) config('instagram_feed.instagram.app_id', ''),
                'app_secret' => (string) config('instagram_feed.instagram.app_secret', ''),
            ],
            'facebook' => [
                'app_id' => (string) config('instagram_feed.facebook.app_id', ''),
                'app_secret' => (string) config('instagram_feed.facebook.app_secret', ''),
                'login_config_id' => (string) config('instagram_feed.facebook.login_config_id', ''),
            ],
            'r2' => [
                'account_id' => (string) config('instagram_feed.r2.account_id', ''),
                'access_key_id' => (string) config('instagram_feed.r2.access_key_id', ''),
                'secret_access_key' => (string) config('instagram_feed.r2.secret_access_key', ''),
                'bucket' => (string) config('instagram_feed.r2.bucket', ''),
                'public_base_url' => (string) config('instagram_feed.r2.public_base_url', ''),
            ],
        ];
    }
}
