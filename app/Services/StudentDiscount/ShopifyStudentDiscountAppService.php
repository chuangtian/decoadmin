<?php

namespace App\Services\StudentDiscount;

use App\Exceptions\StudentDiscountException;
use App\Models\AuditLog;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\DB;

class ShopifyStudentDiscountAppService
{
    private const INSTALLATION_QUERY = <<<'GRAPHQL'
        query CurrentStudentDiscountInstallation {
          currentAppInstallation {
            id
            accessScopes { handle }
          }
        }
        GRAPHQL;

    private const SET_PROXY_PATH_MUTATION = <<<'GRAPHQL'
        mutation SetStudentDiscountProxyPath($metafields: [MetafieldsSetInput!]!) {
          metafieldsSet(metafields: $metafields) {
            metafields { id namespace key value }
            userErrors { field message code }
          }
        }
        GRAPHQL;

    public function __construct(
        private HttpFactory $http,
        private StudentDiscountAppRegistryService $registry,
    ) {}

    public function managementStore(User $user, string $shop): Store
    {
        $shop = strtolower(trim($shop));
        if (! preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/', $shop)) {
            throw new StudentDiscountException('STORE_NOT_CONNECTED', '未找到对应的 DecoAdmin 店铺。', 404);
        }

        $store = Store::query()
            ->where('shopify_domain', $shop)
            ->where('status', 'active')
            ->whereHas('organization', fn ($query) => $query->where('status', 'active'))
            ->with('organization')
            ->first();
        if (! $store || ! $store->organization) {
            throw new StudentDiscountException('STORE_NOT_CONNECTED', '未找到对应的 DecoAdmin 店铺。', 404);
        }
        if (! $user->canAccessStore($store)
            || ! $user->hasPermission('student_discount.claim.read', $store->organization, $store)) {
            throw new StudentDiscountException('STORE_ACCESS_DENIED', '无权访问该店铺的学生优惠后台。', 403);
        }

        return $store;
    }

    /** @return array{app_installation_id: string, proxy_path: string} */
    public function bootstrap(Store $store, string $idToken): array
    {
        $shop = $store->shopify_domain;
        $token = $this->exchangeOnlineToken($shop, $idToken);
        $installationPayload = $this->graphql($shop, $token['access_token'], self::INSTALLATION_QUERY);
        $installationId = data_get($installationPayload, 'data.currentAppInstallation.id');
        if (! is_string($installationId) || $installationId === '') {
            throw new StudentDiscountException('SHOPIFY_APP_NOT_INSTALLED', '未找到当前学生优惠 App 的安装记录。', 409);
        }
        $installationScopes = collect(data_get($installationPayload, 'data.currentAppInstallation.accessScopes', []))
            ->pluck('handle')
            ->filter(fn (mixed $scope): bool => is_string($scope) && $scope !== '')
            ->values()
            ->all();
        $this->assertRequiredScopes($installationScopes);

        $proxyPath = (string) config('student_discount.active.proxy_path');
        $payload = $this->graphql($shop, $token['access_token'], self::SET_PROXY_PATH_MUTATION, [
            'metafields' => [[
                'ownerId' => $installationId,
                'namespace' => 'deco_student_discount',
                'key' => 'proxy_path',
                'type' => 'single_line_text_field',
                'value' => $proxyPath,
            ]],
        ]);
        $errors = data_get($payload, 'data.metafieldsSet.userErrors', []);
        $savedValue = data_get($payload, 'data.metafieldsSet.metafields.0.value');
        if ((is_array($errors) && $errors !== []) || ! is_string($savedValue) || ! hash_equals($proxyPath, $savedValue)) {
            throw new StudentDiscountException('SHOPIFY_PROXY_PATH_WRITE_FAILED', 'Shopify 未能保存 App Proxy 路径。', 502);
        }

        $connection = $store->shopifyConnection()->where('status', 'active')->first();
        if (! $connection) {
            throw new StudentDiscountException('STORE_NOT_CONNECTED', '该 Shopify 店铺尚未连接 DecoAdmin。', 409);
        }

        DB::transaction(function () use ($store, $connection, $installationId, $installationScopes, $proxyPath): void {
            $this->registry->synchronizeInstallation(
                $store,
                $connection,
                'active',
                $installationScopes,
                'student_discount_bootstrap',
                $installationId,
            );

            AuditLog::query()->create([
                'organization_id' => $store->organization_id,
                'store_id' => $store->id,
                'action' => 'student_discount_shopify_app_bootstrapped',
                'subject_type' => Store::class,
                'subject_id' => $store->id,
                'metadata' => [
                    'scope' => 'store',
                    'environment' => (string) config('student_discount.environment'),
                    'proxy_path' => $proxyPath,
                    'app_installation_id' => $installationId,
                    'granted_scopes' => $installationScopes,
                ],
            ]);
        });

        return ['app_installation_id' => $installationId, 'proxy_path' => $proxyPath];
    }

    /** @return array{access_token: string, granted_scopes: list<string>} */
    private function exchangeOnlineToken(string $shop, string $idToken): array
    {
        try {
            $response = $this->http
                ->asForm()
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(20)
                ->post("https://{$shop}/admin/oauth/access_token", [
                    'client_id' => (string) config('student_discount.active.client_id'),
                    'client_secret' => (string) config('student_discount.active.client_secret'),
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:token-exchange',
                    'subject_token' => $idToken,
                    'subject_token_type' => 'urn:ietf:params:oauth:token-type:id_token',
                    'requested_token_type' => 'urn:shopify:params:oauth:token-type:online-access-token',
                ]);
        } catch (ConnectionException) {
            throw new StudentDiscountException('SHOPIFY_TOKEN_EXCHANGE_TIMEOUT', 'Shopify 身份交换超时，请稍后重试。', 502);
        }

        $accessToken = $response->json('access_token');
        if ($response->status() === 400) {
            throw new StudentDiscountException('INVALID_SHOPIFY_ID_TOKEN', 'Shopify 身份令牌已失效，请刷新后重试。', 401);
        }
        if ($response->failed() || ! is_string($accessToken) || $accessToken === '') {
            throw new StudentDiscountException('SHOPIFY_TOKEN_EXCHANGE_FAILED', '无法建立 Shopify Admin API 会话。', 502);
        }

        $grantedScopes = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $response->json('scope', '')),
        )));
        $this->assertRequiredScopes($grantedScopes);

        return ['access_token' => $accessToken, 'granted_scopes' => $grantedScopes];
    }

    /** @param list<string> $grantedScopes */
    private function assertRequiredScopes(array $grantedScopes): void
    {
        $missing = collect(config('student_discount.required_scopes', []))
            ->filter(fn (mixed $required): bool => is_string($required) && ! $this->scopeIsGranted($required, $grantedScopes))
            ->values()
            ->all();

        if ($missing !== []) {
            throw new StudentDiscountException(
                'SHOPIFY_REQUIRED_SCOPES_MISSING',
                '学生优惠 App 尚未授予完整权限，请更新安装授权后重试。',
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

    /** @param array<string, mixed> $variables
     * @return array<string, mixed>
     */
    private function graphql(string $shop, string $accessToken, string $query, array $variables = []): array
    {
        try {
            $response = $this->http
                ->acceptJson()
                ->asJson()
                ->withHeaders(['X-Shopify-Access-Token' => $accessToken])
                ->connectTimeout(5)
                ->timeout(20)
                ->post("https://{$shop}/admin/api/".(string) config('shopify.api_version').'/graphql.json', [
                    'query' => $query,
                    // GraphQL requires variables to be a JSON object. PHP's empty array
                    // would otherwise be encoded as `[]` and rejected by Shopify.
                    'variables' => (object) $variables,
                ]);
        } catch (ConnectionException) {
            throw new StudentDiscountException('SHOPIFY_ADMIN_API_TIMEOUT', 'Shopify Admin API 请求超时。', 502);
        }

        $payload = $response->json();
        if ($response->failed() || ! is_array($payload) || ! empty($payload['errors'])) {
            throw new StudentDiscountException('SHOPIFY_ADMIN_API_FAILED', 'Shopify Admin API 请求失败。', 502);
        }

        return $payload;
    }
}
