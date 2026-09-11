<?php

namespace CommunityReviews\Services;

use App\Models\AuditLog;
use App\Models\Store;
use CommunityReviews\Models\Installation;
use CommunityReviews\ReviewException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class ShopifyClient
{
    public function installation(Store $store): ?Installation
    {
        return Installation::query()->where('organization_id', $store->organization_id)
            ->where('store_id', $store->id)->where('environment', config('community_reviews.environment'))
            ->whereNotNull('access_token_encrypted')->first();
    }

    public function graphql(Store $store, string $query, array $variables = [], ?string $token = null): array
    {
        $token ??= $this->installation($store)?->access_token_encrypted;
        if (! $token) {
            throw new ReviewException('APP_NOT_CONNECTED', '请先在 Shopify 后台打开 Community Reviews 完成连接。', 409);
        }
        if (! preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/', (string) $store->shopify_domain)) {
            throw new ReviewException('INVALID_SHOP', '店铺域名无效。', 422);
        }
        try {
            $response = Http::acceptJson()->withHeaders(['X-Shopify-Access-Token' => $token])
                ->connectTimeout(5)->timeout(20)->post('https://'.$store->shopify_domain.'/admin/api/'.config('shopify.api_version').'/graphql.json', ['query' => $query, 'variables' => (object) $variables]);
        } catch (ConnectionException) {
            throw new ReviewException('SHOPIFY_UNAVAILABLE', '暂时无法连接 Shopify，请稍后重试。');
        }
        if ($response->failed() || ! is_array($response->json()) || $response->json('errors')) {
            throw new ReviewException('SHOPIFY_REQUEST_FAILED', '无法读取 Shopify 商品，请检查应用连接后重试。');
        }

        return $response->json('data', []);
    }

    public function bootstrap(Store $store, string $idToken): void
    {
        try {
            $response = Http::asForm()->acceptJson()->connectTimeout(5)->timeout(20)
                ->post('https://'.$store->shopify_domain.'/admin/oauth/access_token', [
                    'client_id' => config('community_reviews.active.client_id'),
                    'client_secret' => config('community_reviews.active.client_secret'),
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:token-exchange',
                    'subject_token' => $idToken,
                    'subject_token_type' => 'urn:ietf:params:oauth:token-type:id_token',
                    'requested_token_type' => 'urn:shopify:params:oauth:token-type:offline-access-token',
                ]);
        } catch (ConnectionException) {
            throw new ReviewException('SHOPIFY_UNAVAILABLE', 'Shopify 连接超时，请重试。');
        }
        $token = $response->json('access_token');
        if ($response->failed() || ! is_string($token) || $token === '') {
            throw new ReviewException('INVALID_SESSION', 'Shopify 会话已失效，请刷新应用。', 401);
        }
        $data = $this->graphql($store, '{ currentAppInstallation { id accessScopes { handle } } }', [], $token);
        $scopes = array_column($data['currentAppInstallation']['accessScopes'] ?? [], 'handle');
        if (! array_intersect(['read_products', 'write_products'], $scopes) || empty($data['currentAppInstallation']['id'])) {
            throw new ReviewException('MISSING_SCOPES', '应用缺少商品读取权限。', 403);
        }
        Installation::query()->updateOrCreate(['store_id' => $store->id], [
            'organization_id' => $store->organization_id,
            'environment' => config('community_reviews.environment'),
            'app_installation_id' => $data['currentAppInstallation']['id'],
            'access_token_encrypted' => $token, 'granted_scopes' => $scopes, 'installed_at' => now(),
        ]);
        AuditLog::query()->create([
            'organization_id' => $store->organization_id, 'store_id' => $store->id,
            'action' => 'community_reviews_connected', 'subject_type' => Store::class, 'subject_id' => $store->id,
            'metadata' => ['environment' => config('community_reviews.environment')],
        ]);
    }

    public function availableProducts(Store $store, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_STRING);
        return app(FeedCache::class)->remember($store, 'products:'.hash('sha256', implode(',', $ids)), 30, fn () => $this->loadProducts($store, $ids));
    }

    private function loadProducts(Store $store, array $ids): array
    {
        $data = $this->graphql($store, <<<'GRAPHQL'
            query CommunityReviewProducts($ids: [ID!]!) {
              nodes(ids: $ids) {
                ... on Product {
                  id title handle status onlineStoreUrl publishedAt
                  featuredImage { url(transform: {maxWidth: 180, maxHeight: 180, preferredContentType: WEBP}) altText }
                }
              }
            }
            GRAPHQL, ['ids' => array_values(array_unique($ids))]);
        $result = [];
        foreach ($data['nodes'] ?? [] as $node) {
            if (! is_array($node) || ($node['status'] ?? '') !== 'ACTIVE'
                || empty($node['publishedAt']) || strtotime($node['publishedAt']) > time() || empty($node['handle'])) {
                continue;
            }
            $result[$node['id']] = $node;
        }

        return $result;
    }
}
