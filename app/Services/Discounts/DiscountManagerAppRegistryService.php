<?php

namespace App\Services\Discounts;

use App\Exceptions\DiscountManagerException;
use App\Models\App;
use App\Models\AppInstallation;
use App\Models\ShopifyConnection;
use App\Models\Store;
use Illuminate\Support\Facades\DB;

class DiscountManagerAppRegistryService
{
    /** @param list<string> $grantedScopes */
    public function synchronizeInstallation(
        Store $store,
        ShopifyConnection $connection,
        string $status,
        array $grantedScopes,
        string $source,
        ?string $externalInstallationId = null,
    ): AppInstallation {
        return DB::transaction(function () use ($store, $connection, $status, $grantedScopes, $source, $externalInstallationId): AppInstallation {
            $app = $this->configuredApp();
            $installation = AppInstallation::withTrashed()
                ->where('app_id', $app->id)
                ->where('store_id', $store->id)
                ->lockForUpdate()
                ->first() ?? new AppInstallation;
            $scopes = collect($grantedScopes)
                ->filter(fn (mixed $scope): bool => is_string($scope) && $scope !== '')
                ->unique()->sort()->values()->all();

            $installation->fill([
                'app_id' => $app->id,
                'store_id' => $store->id,
                'shopify_connection_id' => $connection->id,
                'status' => $status,
                'granted_scopes' => $status === 'uninstalled'
                    ? (is_array($installation->granted_scopes) ? $installation->granted_scopes : $scopes)
                    : $scopes,
                'settings' => [
                    'source' => $source,
                    'environment' => (string) config('discount_manager.environment'),
                ],
                'installed_at' => $installation->installed_at ?? now(),
                'uninstalled_at' => $status === 'uninstalled' ? now() : null,
                ...($externalInstallationId ? ['external_installation_id' => $externalInstallationId] : []),
            ]);
            $installation->deleted_at = null;
            $installation->save();

            return $installation;
        });
    }

    public function configuredApp(): App
    {
        $environment = (string) config('discount_manager.environment');
        $handle = trim((string) config('discount_manager.active.handle'));
        $name = trim((string) config('discount_manager.active.name'));
        $clientId = trim((string) config('discount_manager.active.client_id'));
        $secret = (string) config('discount_manager.active.client_secret');
        if (! in_array($environment, ['test', 'production'], true)
            || $handle === '' || $name === '' || $clientId === '' || $secret === '') {
            throw new DiscountManagerException(
                'DISCOUNT_MANAGER_APP_NOT_CONFIGURED',
                '折扣管理 Shopify App 当前环境尚未配置。',
                503,
            );
        }

        $app = App::withTrashed()->where('handle', $handle)->lockForUpdate()->first();
        if ($app && ($app->organization_id !== null
            || (filled($app->client_id) && ! hash_equals((string) $app->client_id, $clientId)))) {
            throw new DiscountManagerException('DISCOUNT_MANAGER_APP_REGISTRY_CONFLICT', '折扣管理 App 注册信息冲突。', 409);
        }

        $app ??= new App(['handle' => $handle]);
        $scopes = collect((array) config('discount_manager.required_scopes', []))->sort()->values()->all();
        $settings = ['managed_by' => 'discount_manager_config', 'environment' => $environment];
        $secretMatches = is_string($app->client_secret_encrypted)
            && hash_equals($app->client_secret_encrypted, $secret);

        if (! $app->exists || $app->trashed() || $app->name !== $name || $app->client_id !== $clientId
            || ! $secretMatches || $app->distribution !== 'custom' || $app->status !== 'active'
            || $app->scopes !== $scopes || $app->webhook_api_version !== (string) config('shopify.api_version')
            || $app->settings !== $settings) {
            $app->fill([
                'organization_id' => null,
                'name' => $name,
                'client_id' => $clientId,
                'client_secret_encrypted' => $secret,
                'distribution' => 'custom',
                'status' => 'active',
                'scopes' => $scopes,
                'webhook_api_version' => (string) config('shopify.api_version'),
                'settings' => $settings,
            ]);
            $app->deleted_at = null;
            $app->save();
        }

        return $app;
    }
}
