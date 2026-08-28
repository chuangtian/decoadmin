<?php

namespace App\Services\Shopify;

use App\Exceptions\ShopifyOAuthException;
use App\Jobs\RegisterShopifyWebhooksJob;
use App\Models\AppInstallation;
use App\Models\AuditLog;
use App\Models\OAuthState;
use App\Models\ShopifyConnection;
use App\Models\Store;
use Illuminate\Support\Facades\DB;
use Throwable;

class ShopifyConnectionService
{
    public function __construct(
        private ShopifyConnectionLifecycleService $lifecycle,
        private ShopifyConnectionHealthService $health,
    ) {}

    /**
     * @param  array{access_token: string, scope?: string, expires_in?: int, refresh_token?: string, refresh_token_expires_in?: int}  $token
     */
    public function connect(OAuthState $state, array $token): Store
    {
        $state->loadMissing(['app', 'user']);
        if (data_get($state->app?->settings, 'oauth_token_storage') === 'app_installation') {
            return $this->connectApplicationInstallation($state, $token);
        }

        $store = DB::transaction(function () use ($state, $token): Store {
            $store = Store::query()->lockForUpdate()->findOrFail($state->store_id);
            $scopes = array_values(array_filter(array_map(
                'trim',
                explode(',', (string) ($token['scope'] ?? '')),
            )));
            $installedAt = now();

            $store->forceFill([
                'shopify_domain' => $state->shop_domain,
                'status' => 'active',
            ])->save();

            $connection = ShopifyConnection::withTrashed()->firstOrNew(['store_id' => $store->getKey()]);
            $previousStatus = $connection->exists
                ? $connection->status
                : ShopifyConnectionLifecycleService::NEW_CONNECTION;
            $connection->fill([
                'shop_domain' => $state->shop_domain,
                'access_token_encrypted' => $token['access_token'],
                'refresh_token_encrypted' => $token['refresh_token'] ?? null,
                'token_type' => 'offline',
                'access_token_expires_at' => isset($token['expires_in'])
                    ? now()->addSeconds((int) $token['expires_in'])
                    : null,
                'scopes' => $scopes,
                'api_version' => (string) config('shopify.api_version'),
                'installed_at' => $installedAt,
                'uninstalled_at' => null,
                'metadata' => isset($token['refresh_token_expires_in'])
                    ? ['refresh_token_expires_at' => now()->addSeconds((int) $token['refresh_token_expires_in'])->toIso8601String()]
                    : null,
            ]);
            $connection->deleted_at = null;
            $connection->save();
            $this->lifecycle->markConnected(
                $connection,
                $state->user,
                $previousStatus === ShopifyConnectionLifecycleService::NEW_CONNECTION
                    ? 'Shopify OAuth 授权完成。'
                    : 'Shopify OAuth 重新授权完成。',
                previousStatus: $previousStatus,
            );

            $installation = AppInstallation::withTrashed()->firstOrNew([
                'app_id' => $state->app_id,
                'store_id' => $store->getKey(),
            ]);
            $installationSettings = $installation->settings ?? [];
            $installationSettings['modules'] = array_replace(
                array_fill_keys(array_keys(config('shopify.marketing_modules', [])), true),
                is_array($installationSettings['modules'] ?? null) ? $installationSettings['modules'] : [],
            );
            $installation->fill([
                'shopify_connection_id' => $connection->getKey(),
                'installed_by' => $state->user_id,
                'status' => 'active',
                'granted_scopes' => $scopes,
                'settings' => $installationSettings,
                'installed_at' => $installedAt,
                'uninstalled_at' => null,
            ]);
            $installation->deleted_at = null;
            $installation->save();

            return $store->fresh(['shopifyConnection', 'appInstallations.app']);
        });

        $installation = $store->appInstallations->firstWhere('app_id', $state->app_id);

        $connection = $store->shopifyConnection;

        if ($connection) {
            try {
                $this->health->refreshMetadata($connection, $state->user);
                $store->refresh();
            } catch (Throwable $exception) {
                // Metadata is refreshed again by scheduled health checks. A
                // temporary API failure must not invalidate a completed OAuth.
                report($exception);
            }
        }

        if ($installation) {
            try {
                RegisterShopifyWebhooksJob::dispatch($installation->getKey())->afterCommit();
            } catch (Throwable $exception) {
                // Webhook registration is retryable maintenance and must never invalidate a successful OAuth callback.
                report($exception);
            }
        }

        return $store;
    }

    /**
     * Connect a secondary Shopify App without replacing the store's primary
     * Commerce Hub token. App-specific credentials live only on the matching
     * AppInstallation record.
     *
     * @param  array{access_token: string, scope?: string, expires_in?: int, refresh_token?: string, refresh_token_expires_in?: int}  $token
     */
    private function connectApplicationInstallation(OAuthState $state, array $token): Store
    {
        $store = DB::transaction(function () use ($state, $token): Store {
            $store = Store::query()->lockForUpdate()->findOrFail($state->store_id);
            $connection = ShopifyConnection::query()
                ->where('store_id', $store->getKey())
                ->whereIn('status', ['connected', 'warning'])
                ->lockForUpdate()
                ->first();

            if (! $connection) {
                throw new ShopifyOAuthException('请先在 DecoAdmin 为当前店铺建立有效的 Shopify Commerce Hub 连接。');
            }

            $scopes = array_values(array_filter(array_map(
                'trim',
                explode(',', (string) ($token['scope'] ?? '')),
            )));
            $installedAt = now();
            $installation = AppInstallation::withTrashed()->firstOrNew([
                'app_id' => $state->app_id,
                'store_id' => $store->getKey(),
            ]);
            $settings = is_array($installation->settings) ? $installation->settings : [];
            $settings['modules'] = array_replace(
                array_fill_keys(array_keys(config('shopify.marketing_modules', [])), true),
                is_array($settings['modules'] ?? null) ? $settings['modules'] : [],
            );
            $settings['environment'] = data_get($state->app?->settings, 'environment');
            $installation->fill([
                'shopify_connection_id' => $connection->getKey(),
                'installed_by' => $state->user_id,
                'status' => 'active',
                'granted_scopes' => $scopes,
                'access_token_encrypted' => $token['access_token'],
                'refresh_token_encrypted' => $token['refresh_token'] ?? null,
                'token_type' => 'offline',
                'access_token_expires_at' => isset($token['expires_in'])
                    ? now()->addSeconds((int) $token['expires_in'])
                    : null,
                'refresh_token_expires_at' => isset($token['refresh_token_expires_in'])
                    ? now()->addSeconds((int) $token['refresh_token_expires_in'])
                    : null,
                'settings' => $settings,
                'installed_at' => $installedAt,
                'uninstalled_at' => null,
            ]);
            $installation->deleted_at = null;
            $installation->save();

            AuditLog::query()->create([
                'organization_id' => $store->organization_id,
                'store_id' => $store->getKey(),
                'user_id' => $state->user_id,
                'action' => 'shopify_app_installed',
                'subject_type' => AppInstallation::class,
                'subject_id' => $installation->getKey(),
                'metadata' => [
                    'scope' => 'store',
                    'app_id' => $state->app_id,
                    'app_handle' => $state->app?->handle,
                    'environment' => data_get($state->app?->settings, 'environment'),
                    'granted_scopes' => $scopes,
                ],
            ]);

            return $store->fresh(['shopifyConnection', 'appInstallations.app']);
        });

        $installation = $store->appInstallations->firstWhere('app_id', $state->app_id);
        if ($installation) {
            try {
                RegisterShopifyWebhooksJob::dispatch($installation->getKey())->afterCommit();
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $store;
    }
}
