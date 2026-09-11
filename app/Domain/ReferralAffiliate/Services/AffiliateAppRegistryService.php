<?php

namespace App\Domain\ReferralAffiliate\Services;

use App\Exceptions\AffiliateException;
use App\Models\App;
use App\Models\AppInstallation;
use App\Models\ShopifyConnection;
use App\Models\Store;
use Illuminate\Support\Facades\DB;

class AffiliateAppRegistryService
{
    /**
     * @param  list<string>  $grantedScopes
     */
    public function synchronizeInstallation(
        Store $store,
        ShopifyConnection $connection,
        string $status,
        array $grantedScopes,
        string $source,
        ?string $externalInstallationId = null,
    ): AppInstallation {
        app(AffiliateShopGuard::class)->store($store);
        abort_unless((int) $connection->store_id === (int) $store->id, 403);

        return DB::transaction(function () use ($store, $connection, $status, $grantedScopes, $source, $externalInstallationId): AppInstallation {
            $app = $this->configuredApp();
            $installation = AppInstallation::withTrashed()
                ->where('app_id', $app->id)
                ->where('store_id', $store->id)
                ->lockForUpdate()
                ->first() ?? new AppInstallation;

            $scopes = array_values(array_unique(array_filter(
                $grantedScopes,
                fn (mixed $scope): bool => is_string($scope) && $scope !== '',
            )));
            sort($scopes);

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
                    'environment' => (string) config('referral.environment'),
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

    private function configuredApp(): App
    {
        $handle = trim((string) config('referral.active.handle'));
        $name = 'Deco 推荐与联盟 '.config('referral.environment');
        $clientId = trim((string) config('referral.active.client_id'));
        $secret = (string) config('referral.active.client_secret');
        if ($handle === '' || $name === '' || $clientId === '' || $secret === '') {
            throw new AffiliateException('AFFILIATE_APP_NOT_CONFIGURED', '推荐与联盟 App 当前环境尚未配置。', 503);
        }

        $app = App::withTrashed()->where('handle', $handle)->lockForUpdate()->first();
        if ($app && ($app->organization_id !== null
            || (filled($app->client_id) && ! hash_equals((string) $app->client_id, $clientId)))) {
            throw new AffiliateException('AFFILIATE_APP_REGISTRY_CONFLICT', '推荐与联盟 App 注册信息冲突。', 409);
        }

        $app ??= new App(['handle' => $handle]);
        $scopes = array_values((array) config('referral.required_scopes', []));
        $settings = [
            'managed_by' => 'referral_config',
            'environment' => (string) config('referral.environment'),
        ];
        $secretMatches = is_string($app->client_secret_encrypted)
            && hash_equals($app->client_secret_encrypted, $secret);
        $needsUpdate = ! $app->exists
            || $app->trashed()
            || $app->name !== $name
            || $app->client_id !== $clientId
            || ! $secretMatches
            || $app->distribution !== 'custom'
            || $app->status !== 'active'
            || $app->scopes !== $scopes
            || $app->webhook_api_version !== (string) config('shopify.api_version')
            || $app->settings !== $settings;

        if ($needsUpdate) {
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
