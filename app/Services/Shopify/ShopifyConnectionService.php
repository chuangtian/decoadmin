<?php

namespace App\Services\Shopify;

use App\Models\AppInstallation;
use App\Models\OAuthState;
use App\Models\ShopifyConnection;
use App\Models\Store;
use Illuminate\Support\Facades\DB;

class ShopifyConnectionService
{
    /**
     * @param  array{access_token: string, scope?: string, expires_in?: int, refresh_token?: string, refresh_token_expires_in?: int}  $token
     */
    public function connect(OAuthState $state, array $token): Store
    {
        return DB::transaction(function () use ($state, $token): Store {
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
                'status' => 'active',
                'installed_at' => $installedAt,
                'uninstalled_at' => null,
                'last_verified_at' => $installedAt,
                'metadata' => isset($token['refresh_token_expires_in'])
                    ? ['refresh_token_expires_at' => now()->addSeconds((int) $token['refresh_token_expires_in'])->toIso8601String()]
                    : null,
            ]);
            $connection->deleted_at = null;
            $connection->save();

            $installation = AppInstallation::withTrashed()->firstOrNew([
                'app_id' => $state->app_id,
                'store_id' => $store->getKey(),
            ]);
            $installation->fill([
                'shopify_connection_id' => $connection->getKey(),
                'installed_by' => $state->user_id,
                'status' => 'active',
                'granted_scopes' => $scopes,
                'installed_at' => $installedAt,
                'uninstalled_at' => null,
            ]);
            $installation->deleted_at = null;
            $installation->save();

            return $store->fresh(['shopifyConnection', 'appInstallations.app']);
        });
    }
}
