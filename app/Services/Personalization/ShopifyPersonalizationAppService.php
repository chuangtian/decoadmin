<?php

namespace App\Services\Personalization;

use App\Exceptions\PersonalizationException;
use App\Models\AppInstallation;
use App\Models\AuditLog;
use App\Models\PersonalizationEventSource;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\DB;

class ShopifyPersonalizationAppService
{
    private const INSTALLATION_QUERY = <<<'GRAPHQL'
        query CurrentPersonalizationInstallation {
          currentAppInstallation {
            id
            accessScopes { handle }
          }
        }
        GRAPHQL;

    private const SET_PROXY_PATH_MUTATION = <<<'GRAPHQL'
        mutation SetPersonalizationProxyPath($metafields: [MetafieldsSetInput!]!) {
          metafieldsSet(metafields: $metafields) {
            metafields { id namespace key value }
            userErrors { field message code }
          }
        }
        GRAPHQL;

    private const WEB_PIXEL_QUERY = <<<'GRAPHQL'
        query CurrentPersonalizationWebPixel {
          webPixel {
            id
            settings
          }
        }
        GRAPHQL;

    private const CREATE_WEB_PIXEL_MUTATION = <<<'GRAPHQL'
        mutation CreatePersonalizationWebPixel($webPixel: WebPixelInput!) {
          webPixelCreate(webPixel: $webPixel) {
            webPixel { id settings }
            userErrors { field message code }
          }
        }
        GRAPHQL;

    private const UPDATE_WEB_PIXEL_MUTATION = <<<'GRAPHQL'
        mutation UpdatePersonalizationWebPixel($id: ID!, $webPixel: WebPixelInput!) {
          webPixelUpdate(id: $id, webPixel: $webPixel) {
            webPixel { id settings }
            userErrors { field message code }
          }
        }
        GRAPHQL;

    public function __construct(
        private HttpFactory $http,
        private PersonalizationAppRegistryService $registry,
        private PersonalizationShopGuard $shopGuard,
    ) {}

    public function managementStore(User $user, string $shop): Store
    {
        $store = $this->activeStore($this->shopGuard->assertAllowed($shop));
        if (! $user->canAccessStore($store)
            || ! $user->hasPermission('personalization.view', $store->organization, $store)) {
            throw new PersonalizationException(
                'STORE_ACCESS_DENIED',
                '无权访问该店铺的个性化推荐后台。',
                403,
            );
        }

        return $store;
    }

    public function connectedStore(string $shop): ?Store
    {
        $shop = $this->shopGuard->assertAllowed($shop);

        return Store::query()
            ->where('shopify_domain', $shop)
            ->where('status', 'active')
            ->whereHas('organization', fn ($query) => $query->where('status', 'active'))
            ->with('organization')
            ->first();
    }

    /** @return array{app_installation_id: string, granted_scopes: list<string>, proxy_path: string, checkout_configuration_url: string, web_pixel_id: string} */
    public function bootstrap(Store $store, string $idToken): array
    {
        $shop = $this->shopGuard->assertAllowed((string) $store->shopify_domain);
        $connection = $store->shopifyConnection()->whereIn('status', ['connected', 'warning'])->first();
        if (! $connection) {
            throw new PersonalizationException(
                'STORE_NOT_CONNECTED',
                '该 Shopify 店铺尚未连接 DecoAdmin Commerce Hub。',
                409,
            );
        }

        $token = $this->exchangeOfflineToken($shop, $idToken);
        $installationPayload = $this->graphql($shop, $token['access_token'], self::INSTALLATION_QUERY);
        $installationId = data_get($installationPayload, 'data.currentAppInstallation.id');
        if (! is_string($installationId) || $installationId === '') {
            throw new PersonalizationException(
                'SHOPIFY_APP_NOT_INSTALLED',
                '未找到当前个性化推荐 App 的安装记录。',
                409,
            );
        }
        $installationScopes = collect(data_get($installationPayload, 'data.currentAppInstallation.accessScopes', []))
            ->pluck('handle')
            ->filter(fn (mixed $scope): bool => is_string($scope) && $scope !== '')
            ->values()
            ->all();
        $this->assertRequiredScopes($installationScopes);

        $proxyPath = (string) config('personalization.active_proxy_path');
        $checkoutConfigurationUrl = $this->checkoutConfigurationUrl();
        $proxyPayload = $this->graphql($shop, $token['access_token'], self::SET_PROXY_PATH_MUTATION, [
            'metafields' => [
                [
                    'ownerId' => $installationId,
                    'namespace' => 'deco_personalization',
                    'key' => 'proxy_path',
                    'type' => 'single_line_text_field',
                    'value' => $proxyPath,
                ],
                [
                    'ownerId' => $installationId,
                    'namespace' => 'deco_personalization',
                    'key' => 'checkout_configuration_url',
                    'type' => 'single_line_text_field',
                    'value' => $checkoutConfigurationUrl,
                ],
            ],
        ]);
        $proxyErrors = data_get($proxyPayload, 'data.metafieldsSet.userErrors', []);
        $savedMetafields = collect(data_get($proxyPayload, 'data.metafieldsSet.metafields', []))
            ->filter(fn (mixed $metafield): bool => is_array($metafield))
            ->keyBy(fn (array $metafield): string => (string) ($metafield['key'] ?? ''));
        $savedProxyPath = data_get($savedMetafields->get('proxy_path'), 'value');
        $savedCheckoutConfigurationUrl = data_get($savedMetafields->get('checkout_configuration_url'), 'value');
        if ($proxyPath === ''
            || (is_array($proxyErrors) && $proxyErrors !== [])
            || ! is_string($savedProxyPath)
            || ! hash_equals($proxyPath, $savedProxyPath)
            || ! is_string($savedCheckoutConfigurationUrl)
            || ! hash_equals($checkoutConfigurationUrl, $savedCheckoutConfigurationUrl)) {
            throw new PersonalizationException(
                'SHOPIFY_APP_METAFIELDS_WRITE_FAILED',
                'Shopify 未能保存个性化推荐 App 运行配置。',
                502,
            );
        }

        $eventSource = $this->eventSource($store);
        $webPixelId = $this->synchronizeWebPixel($shop, $token['access_token'], $eventSource);

        DB::transaction(function () use ($store, $connection, $installationId, $installationScopes, $proxyPath, $checkoutConfigurationUrl, $webPixelId, $eventSource, $token): void {
            $installation = $this->registry->synchronizeInstallation(
                $store,
                $connection,
                'active',
                $installationScopes,
                'personalization_bootstrap',
                $installationId,
            );
            $this->storeOfflineToken($installation, $token);
            $eventSource->forceFill([
                'web_pixel_id' => $webPixelId,
                'status' => 'active',
                'activated_at' => now(),
                'purge_after' => null,
            ])->save();

            AuditLog::query()->create([
                'organization_id' => $store->organization_id,
                'store_id' => $store->id,
                'action' => 'personalization_shopify_app_bootstrapped',
                'subject_type' => Store::class,
                'subject_id' => $store->id,
                'metadata' => [
                    'scope' => 'store',
                    'environment' => (string) config('personalization.environment'),
                    'app_installation_id' => $installationId,
                    'granted_scopes' => $installationScopes,
                    'proxy_path' => $proxyPath,
                    'checkout_configuration_url' => $checkoutConfigurationUrl,
                    'web_pixel_id' => $webPixelId,
                ],
            ]);
        });

        return [
            'app_installation_id' => $installationId,
            'granted_scopes' => $installationScopes,
            'proxy_path' => $proxyPath,
            'checkout_configuration_url' => $checkoutConfigurationUrl,
            'web_pixel_id' => $webPixelId,
        ];
    }

    private function checkoutConfigurationUrl(): string
    {
        $origin = rtrim((string) config('personalization.active.app_url'), '/');
        $endpoint = $origin.route('personalization.checkout.configuration', [], false);
        $parts = parse_url($endpoint);
        if (($parts['scheme'] ?? null) !== 'https'
            || ! is_string($parts['host'] ?? null)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new PersonalizationException(
                'PERSONALIZATION_CHECKOUT_ENDPOINT_INVALID',
                '个性化推荐 Checkout 配置地址无效。',
                503,
            );
        }

        return $endpoint;
    }

    private function eventSource(Store $store): PersonalizationEventSource
    {
        $source = PersonalizationEventSource::query()->firstOrCreate(
            ['store_id' => $store->id],
            ['organization_id' => $store->organization_id, 'status' => 'inactive'],
        );
        if ((int) $source->organization_id !== (int) $store->organization_id) {
            throw new PersonalizationException(
                'PERSONALIZATION_EVENT_SOURCE_CONFLICT',
                '个性化推荐事件来源与店铺范围冲突。',
                409,
            );
        }
        // Fail closed while Shopify settings are being synchronized. If the
        // external mutation or local transaction fails, ingestion stays off.
        $source->forceFill(['status' => 'inactive'])->save();

        return $source;
    }

    private function synchronizeWebPixel(
        string $shop,
        string $accessToken,
        PersonalizationEventSource $source,
    ): string {
        $origin = rtrim((string) config('personalization.active.app_url'), '/');
        $endpoint = $origin.route('personalization.events.receive', ['source' => $source->ingest_key], false);
        $parts = parse_url($endpoint);
        if (($parts['scheme'] ?? null) !== 'https'
            || ! is_string($parts['host'] ?? null)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new PersonalizationException(
                'PERSONALIZATION_EVENT_ENDPOINT_INVALID',
                '个性化推荐事件接收地址无效。',
                503,
            );
        }

        $currentPayload = $this->graphql(
            $shop,
            $accessToken,
            self::WEB_PIXEL_QUERY,
            allowedErrorCodes: ['RESOURCE_NOT_FOUND'],
        );
        $currentId = data_get($currentPayload, 'data.webPixel.id');
        $input = ['settings' => ['endpoint' => $endpoint]];
        if (is_string($currentId) && $currentId !== '') {
            $payload = $this->graphql($shop, $accessToken, self::UPDATE_WEB_PIXEL_MUTATION, [
                'id' => $currentId,
                'webPixel' => $input,
            ]);
            $result = data_get($payload, 'data.webPixelUpdate');
        } else {
            $payload = $this->graphql($shop, $accessToken, self::CREATE_WEB_PIXEL_MUTATION, [
                'webPixel' => $input,
            ]);
            $result = data_get($payload, 'data.webPixelCreate');
        }
        $webPixelId = data_get($result, 'webPixel.id');
        $errors = data_get($result, 'userErrors', []);
        if (! is_array($result)
            || ! is_array($errors) || $errors !== []
            || ! is_string($webPixelId) || $webPixelId === '') {
            throw new PersonalizationException(
                'SHOPIFY_WEB_PIXEL_SYNC_FAILED',
                'Shopify 未能启用个性化推荐 Web Pixel。',
                502,
            );
        }

        return $webPixelId;
    }

    /**
     * @return array{access_token: string, refresh_token: string, expires_in: int, refresh_token_expires_in: int, granted_scopes: list<string>}
     */
    private function exchangeOfflineToken(string $shop, string $idToken): array
    {
        try {
            $response = $this->http
                ->asForm()
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(20)
                ->post("https://{$shop}/admin/oauth/access_token", [
                    'client_id' => (string) config('personalization.active.client_id'),
                    'client_secret' => (string) config('personalization.active.client_secret'),
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:token-exchange',
                    'subject_token' => $idToken,
                    'subject_token_type' => 'urn:ietf:params:oauth:token-type:id_token',
                    'requested_token_type' => 'urn:shopify:params:oauth:token-type:offline-access-token',
                    'expiring' => 1,
                ]);
        } catch (ConnectionException) {
            throw new PersonalizationException(
                'SHOPIFY_TOKEN_EXCHANGE_TIMEOUT',
                'Shopify 身份交换超时，请稍后重试。',
                502,
            );
        }

        $accessToken = $response->json('access_token');
        $refreshToken = $response->json('refresh_token');
        $expiresIn = $response->json('expires_in');
        $refreshTokenExpiresIn = $response->json('refresh_token_expires_in');
        if ($response->status() === 400) {
            throw new PersonalizationException(
                'INVALID_SHOPIFY_ID_TOKEN',
                'Shopify 身份令牌已失效，请刷新后重试。',
                401,
            );
        }
        if ($response->failed()
            || ! is_string($accessToken) || $accessToken === ''
            || ! is_string($refreshToken) || $refreshToken === ''
            || ! is_numeric($expiresIn) || (int) $expiresIn <= 0
            || ! is_numeric($refreshTokenExpiresIn) || (int) $refreshTokenExpiresIn <= 0) {
            throw new PersonalizationException(
                'SHOPIFY_TOKEN_EXCHANGE_FAILED',
                '无法建立 Shopify Admin API 会话。',
                502,
            );
        }

        $grantedScopes = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $response->json('scope', '')),
        )));
        $this->assertRequiredScopes($grantedScopes);

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in' => (int) $expiresIn,
            'refresh_token_expires_in' => (int) $refreshTokenExpiresIn,
            'granted_scopes' => $grantedScopes,
        ];
    }

    /**
     * @param  array{access_token: string, refresh_token: string, expires_in: int, refresh_token_expires_in: int, granted_scopes: list<string>}  $token
     */
    private function storeOfflineToken(AppInstallation $installation, array $token): void
    {
        $issuedAt = now();
        $installation->forceFill([
            'access_token_encrypted' => $token['access_token'],
            'refresh_token_encrypted' => $token['refresh_token'],
            'token_type' => 'offline',
            'access_token_expires_at' => $issuedAt->copy()->addSeconds($token['expires_in']),
            'refresh_token_expires_at' => $issuedAt->copy()->addSeconds($token['refresh_token_expires_in']),
        ])->save();
    }

    /** @param list<string> $grantedScopes */
    private function assertRequiredScopes(array $grantedScopes): void
    {
        $missing = collect(config('personalization.required_scopes', []))
            ->filter(fn (mixed $required): bool => is_string($required) && ! $this->scopeIsGranted($required, $grantedScopes))
            ->values()
            ->all();
        if ($missing !== []) {
            throw new PersonalizationException(
                'SHOPIFY_REQUIRED_SCOPES_MISSING',
                '个性化推荐 App 尚未授予完整权限，请更新安装授权后重试。',
                403,
            );
        }
    }

    /** @param list<string> $grantedScopes */
    private function scopeIsGranted(string $required, array $grantedScopes): bool
    {
        return in_array($required, $grantedScopes, true)
            || (str_starts_with($required, 'read_')
                && in_array('write_'.substr($required, 5), $grantedScopes, true));
    }

    /** @param array<string, mixed> $variables @return array<string, mixed> */
    private function graphql(
        string $shop,
        string $accessToken,
        string $query,
        array $variables = [],
        array $allowedErrorCodes = [],
    ): array {
        try {
            $response = $this->http
                ->acceptJson()
                ->asJson()
                ->withHeaders(['X-Shopify-Access-Token' => $accessToken])
                ->connectTimeout(5)
                ->timeout(20)
                ->post("https://{$shop}/admin/api/".(string) config('shopify.api_version').'/graphql.json', [
                    'query' => $query,
                    'variables' => (object) $variables,
                ]);
        } catch (ConnectionException) {
            throw new PersonalizationException(
                'SHOPIFY_ADMIN_API_TIMEOUT',
                'Shopify Admin API 请求超时。',
                502,
            );
        }

        $payload = $response->json();
        $errors = is_array($payload) ? ($payload['errors'] ?? []) : null;
        if ($response->failed()
            || ! is_array($payload)
            || ! is_array($errors)
            || ($errors !== [] && ! $this->onlyAllowedTopLevelErrors($errors, $allowedErrorCodes))) {
            throw new PersonalizationException(
                'SHOPIFY_ADMIN_API_FAILED',
                'Shopify Admin API 请求失败。',
                502,
            );
        }

        return $payload;
    }

    /** @param array<int, mixed> $errors @param list<string> $allowedErrorCodes */
    private function onlyAllowedTopLevelErrors(array $errors, array $allowedErrorCodes): bool
    {
        if ($allowedErrorCodes === []) {
            return false;
        }

        foreach ($errors as $error) {
            $code = is_array($error) ? data_get($error, 'extensions.code') : null;
            if (! is_string($code) || ! in_array($code, $allowedErrorCodes, true)) {
                return false;
            }
        }

        return true;
    }

    private function activeStore(string $shop): Store
    {
        $store = $this->connectedStore($shop);
        if (! $store || ! $store->organization) {
            throw new PersonalizationException('STORE_NOT_CONNECTED', '未找到对应的 DecoAdmin 店铺。', 404);
        }

        return $store;
    }
}
