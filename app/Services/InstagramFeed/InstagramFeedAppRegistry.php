<?php

namespace App\Services\InstagramFeed;

use App\Exceptions\InstagramFeedException;
use App\Models\App;
use App\Models\AppInstallation;
use App\Models\Store;
use Illuminate\Support\Facades\DB;

/**
 * instagram-feed Shopify App 在 DecoAdmin 侧的注册入口。
 *
 * client_id / handle / name 明文放在 config/instagram_feed.php（非机密），
 * client_secret 走 env。这里负责校验当前环境是否配置完整，并把配置同步成
 * 一条 apps 表记录，供 webhook 事件和 OAuth state 外键引用。
 */
class InstagramFeedAppRegistry
{
    public function environment(): string
    {
        return (string) config('instagram_feed.environment');
    }

    /** @return array{client_id: string, client_secret: string, handle: string, name: string, app_url: string} */
    public function credentials(): array
    {
        $clientId = trim((string) config('instagram_feed.active.client_id'));
        $clientSecret = (string) config('instagram_feed.active.client_secret');
        $handle = trim((string) config('instagram_feed.active.handle'));
        $name = trim((string) config('instagram_feed.active.name'));
        $appUrl = rtrim((string) config('instagram_feed.active.app_url'), '/');

        if ($clientId === '' || $clientSecret === '' || $handle === '' || $name === '' || $appUrl === '') {
            throw new InstagramFeedException(
                'INSTAGRAM_FEED_APP_NOT_CONFIGURED',
                'Instagram Feed App 当前环境尚未配置完整。',
                503,
            );
        }

        // 键名必须与上面的 @return 声明以及全部调用点保持一致（snake_case）。
        // 不要用 compact()：它以变量名为键，会返回 camelCase，导致 configuredApp()、
        // clientSecret() 与 token exchange 全部读不到 client_id / client_secret / app_url。
        return [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'handle' => $handle,
            'name' => $name,
            'app_url' => $appUrl,
        ];
    }

    public function clientSecret(): string
    {
        return $this->credentials()['client_secret'];
    }

    /**
     * 按配置同步 apps 表记录。
     *
     * 只接受平台级（organization_id 为 null）且 client_id 一致的记录，避免把
     * 另一个组织自建的同 handle App 覆盖掉。
     */
    public function configuredApp(): App
    {
        $credentials = $this->credentials();

        $app = App::withTrashed()->where('handle', $credentials['handle'])->lockForUpdate()->first();
        if ($app && ($app->organization_id !== null
            || (filled($app->client_id) && ! hash_equals((string) $app->client_id, $credentials['client_id'])))) {
            throw new InstagramFeedException(
                'INSTAGRAM_FEED_APP_REGISTRY_CONFLICT',
                'Instagram Feed App 注册信息冲突。',
                409,
            );
        }
        $app ??= new App(['handle' => $credentials['handle']]);

        $scopes = array_values((array) config('instagram_feed.required_scopes', []));
        $apiVersion = (string) config('shopify.api_version');
        $settings = [
            'managed_by' => 'instagram_feed_config',
            'environment' => $this->environment(),
        ];
        $secretMatches = is_string($app->client_secret_encrypted)
            && hash_equals($app->client_secret_encrypted, $credentials['client_secret']);
        $needsUpdate = ! $app->exists
            || $app->trashed()
            || $app->name !== $credentials['name']
            || $app->client_id !== $credentials['client_id']
            || ! $secretMatches
            || $app->distribution !== 'custom'
            || $app->status !== 'active'
            || $app->scopes !== $scopes
            || $app->webhook_api_version !== $apiVersion
            || $app->settings !== $settings;

        if ($needsUpdate) {
            $app->fill([
                'organization_id' => null,
                'name' => $credentials['name'],
                'client_id' => $credentials['client_id'],
                'client_secret_encrypted' => $credentials['client_secret'],
                'distribution' => 'custom',
                'status' => 'active',
                'scopes' => $scopes,
                'webhook_api_version' => $apiVersion,
                'settings' => $settings,
            ]);
            $app->deleted_at = null;
            $app->save();
        }

        return $app;
    }

    /**
     * 把安装状态同步成一条 app_installations 记录。
     *
     * 这条记录是应用中心的唯一数据来源：列表可见性、侧边栏「应用中心」条目和配置卡片
     * 都要求平台级 App 在当前店铺存在安装记录，所以 bootstrap 与 webhook 两条链路
     * 都必须走这里，不要各写一份 upsert。
     *
     * app_installations.shopify_connection_id 非空，且有 (shopify_connection_id, store_id)
     * 复合外键做租户隔离，所以登记安装记录要求店铺已经连接 DecoAdmin 主 App。已有记录时
     * 沿用它上面的连接，避免卸载或权限更新时因为连接状态变化而失败。
     *
     * @param  list<string>  $grantedScopes
     */
    public function synchronizeInstallation(
        Store $store,
        string $status,
        array $grantedScopes,
        string $source,
        ?string $externalInstallationId = null,
    ): AppInstallation {
        return DB::transaction(function () use ($store, $status, $grantedScopes, $source, $externalInstallationId): AppInstallation {
            $app = $this->configuredApp();
            $installation = AppInstallation::withTrashed()
                ->where('app_id', $app->id)
                ->where('store_id', $store->id)
                ->lockForUpdate()
                ->first() ?? new AppInstallation;

            $connectionId = $installation->shopify_connection_id
                ?? $store->shopifyConnection()->value('id');
            if ($connectionId === null) {
                throw new InstagramFeedException(
                    'STORE_NOT_CONNECTED',
                    '该 Shopify 店铺尚未连接 DecoAdmin，无法登记 Instagram Feed 的安装记录。',
                    409,
                );
            }

            $scopes = array_values(array_unique(array_filter(
                $grantedScopes,
                fn (mixed $scope): bool => is_string($scope) && $scope !== '',
            )));
            sort($scopes);

            $installation->fill([
                'app_id' => $app->id,
                'store_id' => $store->id,
                'shopify_connection_id' => $connectionId,
                'status' => $status,
                // 卸载事件不带权限列表，保留最后一次已知的授权范围。
                'granted_scopes' => $status === 'uninstalled'
                    ? (is_array($installation->granted_scopes) ? $installation->granted_scopes : $scopes)
                    : $scopes,
                'settings' => [
                    'source' => $source,
                    'environment' => $this->environment(),
                ],
                'installed_at' => $installation->installed_at ?? now(),
                'uninstalled_at' => $status === 'uninstalled' ? now() : null,
                ...($externalInstallationId === null ? [] : ['external_installation_id' => $externalInstallationId]),
            ]);
            if ($status === 'uninstalled') {
                $installation->forceFill([
                    'access_token_encrypted' => null,
                    'refresh_token_encrypted' => null,
                    'token_type' => null,
                    'access_token_expires_at' => null,
                    'refresh_token_expires_at' => null,
                ]);
            }
            $installation->deleted_at = null;
            $installation->save();

            return $installation;
        });
    }

    /**
     * 校验安装授予的权限是否覆盖必需权限。read_x 可被 write_x 覆盖。
     *
     * @param  list<string>  $grantedScopes
     */
    public function assertRequiredScopes(array $grantedScopes): void
    {
        $missing = collect(config('instagram_feed.required_scopes', []))
            ->filter(fn (mixed $required): bool => is_string($required) && ! $this->scopeIsGranted($required, $grantedScopes))
            ->values()
            ->all();

        if ($missing !== []) {
            throw new InstagramFeedException(
                'SHOPIFY_REQUIRED_SCOPES_MISSING',
                'Instagram Feed App 尚未授予完整权限，请更新安装授权后重试。',
                403,
            );
        }
    }

    /** @param list<string> $grantedScopes */
    private function scopeIsGranted(string $required, array $grantedScopes): bool
    {
        if (in_array($required, $grantedScopes, true)) {
            return true;
        }

        return str_starts_with($required, 'read_')
            && in_array('write_'.substr($required, 5), $grantedScopes, true);
    }
}
