<?php

namespace DecoMarketing\Services;

use App\Models\App as ShopifyApp;
use App\Models\AppInstallation;
use App\Models\Store;
use App\Services\Shopify\ShopifyGraphQLClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class Tokens
{
    private function exchange(Store $store, array $grant): array
    {
        app(Guard::class)->store($store);
        if (! config('marketing.active.client_id') || ! config('marketing.active.client_secret')) {
            throw ValidationException::withMessages(['shopify' => '独立测试应用尚未配置。']);
        }
        try {
            $response = Http::asForm()->acceptJson()->connectTimeout(5)->timeout(15)->post('https://'.$store->shopify_domain.'/admin/oauth/access_token', [
                'client_id' => config('marketing.active.client_id'), 'client_secret' => config('marketing.active.client_secret'), ...$grant]);
            if (! $response->successful()) {
                throw new \RuntimeException;
            }
            $data = $response->json();
            if (! is_string($data['access_token'] ?? null) || ! $data['access_token'] || ! is_string($data['refresh_token'] ?? null)
                || ($data['expires_in'] ?? 0) <= 0 || ($data['refresh_token_expires_in'] ?? 0) <= 0) {
                throw new \RuntimeException;
            }

            return $data;
        } catch (\Throwable) {
            throw ValidationException::withMessages(['shopify' => '应用授权未完成或已过期，请从 Shopify 测试店铺重新打开应用。']);
        }
    }

    private function values(array $token): array
    {
        return ['token_type' => 'offline', 'access_token_encrypted' => $token['access_token'], 'refresh_token_encrypted' => $token['refresh_token'],
            'access_token_expires_at' => now()->addSeconds($token['expires_in']), 'refresh_token_expires_at' => now()->addSeconds($token['refresh_token_expires_in'])];
    }

    public function bootstrap(Store $store, string $idToken): void
    {
        app(Guard::class)->store($store);
        $connection = $store->shopifyConnection()->firstOrFail();
        $token = $this->exchange($store, ['grant_type' => 'urn:ietf:params:oauth:grant-type:token-exchange', 'subject_token' => $idToken,
            'subject_token_type' => 'urn:ietf:params:oauth:token-type:id_token', 'requested_token_type' => 'urn:shopify:params:oauth:token-type:offline-access-token', 'expiring' => 1]);
        $data = app(ShopifyGraphQLClient::class)->queryWithAccessToken($store->shopify_domain, $token['access_token'],
            'query MarketingInstallation { shop { myshopifyDomain } currentAppInstallation { id accessScopes { handle } } }', [], 15, config('marketing.active.api_version'));
        abort_unless(data_get($data, 'data.shop.myshopifyDomain') === $store->shopify_domain, 403);
        $scopes = array_column(data_get($data, 'data.currentAppInstallation.accessScopes', []), 'handle');
        foreach (['read_orders', 'read_customers', 'read_products', 'read_inventory', 'read_fulfillments'] as $scope) {
            if (! in_array($scope, $scopes, true) && ! in_array(str_replace('read_', 'write_', $scope), $scopes, true)) {
                throw ValidationException::withMessages(['shopify' => '测试应用缺少必要权限。']);
            }
        }
        if (app(Guard::class)->previewOnly($store) && collect($scopes)->contains(fn ($scope) => str_starts_with($scope, 'write_') && $scope !== 'write_app_proxy')) {
            throw ValidationException::withMessages(['shopify' => '只读对账应用不能包含写权限。']);
        }
        $externalId = data_get($data, 'data.currentAppInstallation.id');
        abort_unless(is_string($externalId) && $externalId !== '', 409);
        DB::transaction(function () use ($store, $connection, $token, $externalId, $scopes) {
            $app = ShopifyApp::withTrashed()->where('handle', config('marketing.active.handle'))->first();
            abort_if($app && ($app->organization_id !== null || ($app->client_id && $app->client_id !== config('marketing.active.client_id'))), 409, '应用注册信息冲突。');
            $app ??= new ShopifyApp(['handle' => config('marketing.active.handle')]);
            $app->fill(['name' => 'Deco Marketing Test', 'client_id' => config('marketing.active.client_id'), 'distribution' => 'custom', 'status' => 'active', 'scopes' => $scopes, 'settings' => ['environment' => config('marketing.environment'), 'managed_by' => 'marketing_config']]);
            $app->deleted_at = null;
            $app->save();
            AppInstallation::withTrashed()->updateOrCreate(['app_id' => $app->id, 'store_id' => $store->id], [
                'shopify_connection_id' => $connection->id, 'status' => 'active', 'deleted_at' => null, 'granted_scopes' => $scopes, 'external_installation_id' => $externalId,
                'settings' => ['environment' => config('marketing.environment')], 'installed_at' => now(), 'uninstalled_at' => null, ...$this->values($token)]);
        });
    }

    public function access(Store $store): string
    {
        app(Guard::class)->store($store);
        $installation = app(Shopify::class)->installation($store);
        if (! $installation?->access_token_encrypted) {
            throw ValidationException::withMessages(['shopify' => '请先安装独立营销测试应用。']);
        }
        if ($installation->access_token_expires_at?->gt(now()->addMinutes(2))) {
            return $installation->access_token_encrypted;
        }

        return Cache::lock('marketing:token:'.$installation->id, 40)->block(5, function () use ($store, $installation) {
            $installation->refresh();
            if ($installation->status !== 'active') {
                throw ValidationException::withMessages(['shopify' => '应用已停用。']);
            }
            if ($installation->access_token_expires_at?->gt(now()->addMinutes(2))) {
                return $installation->access_token_encrypted;
            }
            if (! $installation->refresh_token_encrypted || ! $installation->refresh_token_expires_at?->isFuture()) {
                throw ValidationException::withMessages(['shopify' => '需要重新授权测试应用。']);
            }
            $token = $this->exchange($store, ['grant_type' => 'refresh_token', 'refresh_token' => $installation->refresh_token_encrypted]);
            $installation->update($this->values($token));

            return $token['access_token'];
        });
    }
}
