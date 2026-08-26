<?php

namespace App\Services\InstagramFeed;

use App\Exceptions\InstagramFeedException;
use App\Models\App;

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
                'Instagram 内容 App 当前环境尚未配置完整。',
                503,
            );
        }

        return compact('clientId', 'clientSecret', 'handle', 'name', 'appUrl');
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
                'Instagram 内容 App 注册信息冲突。',
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
                'Instagram 内容 App 尚未授予完整权限，请更新安装授权后重试。',
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
