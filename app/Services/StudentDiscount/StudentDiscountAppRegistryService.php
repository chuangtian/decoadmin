<?php

namespace App\Services\StudentDiscount;

use App\Exceptions\StudentDiscountException;
use App\Models\App;
use App\Models\AppInstallation;
use App\Models\ShopifyConnection;
use App\Models\Store;
use Illuminate\Support\Facades\DB;

class StudentDiscountAppRegistryService
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
                    'environment' => (string) config('student_discount.environment'),
                ],
                'installed_at' => $installation->installed_at ?? now(),
                'uninstalled_at' => $status === 'uninstalled' ? now() : null,
                ...($externalInstallationId === null ? [] : ['external_installation_id' => $externalInstallationId]),
            ]);
            $installation->deleted_at = null;
            $installation->save();

            return $installation;
        });
    }

    private function configuredApp(): App
    {
        $handle = trim((string) config('student_discount.active.handle'));
        $name = trim((string) config('student_discount.active.name'));
        $clientId = trim((string) config('student_discount.active.client_id'));
        $secret = (string) config('student_discount.active.client_secret');
        if ($handle === '' || $name === '' || $clientId === '' || $secret === '') {
            throw new StudentDiscountException('STUDENT_DISCOUNT_APP_NOT_CONFIGURED', '学生优惠 App 当前环境尚未配置。', 503);
        }

        $app = App::withTrashed()->where('handle', $handle)->lockForUpdate()->first();
        if ($app && ($app->organization_id !== null
            || (filled($app->client_id) && ! hash_equals((string) $app->client_id, $clientId)))) {
            throw new StudentDiscountException('STUDENT_DISCOUNT_APP_REGISTRY_CONFLICT', '学生优惠 App 注册信息冲突。', 409);
        }

        $app ??= new App(['handle' => $handle]);
        $scopes = array_values((array) config('student_discount.required_scopes', []));
        $settings = [
            'managed_by' => 'student_discount_config',
            'environment' => (string) config('student_discount.environment'),
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
