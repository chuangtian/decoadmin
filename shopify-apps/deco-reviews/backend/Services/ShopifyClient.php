<?php

namespace DecoReviews\Services;

use App\Models\Store;
use DecoReviews\Models\Installation;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class ShopifyClient
{
    public function installation(Store $store): ?Installation
    {
        app(ReviewService::class)->active($store);

        return Installation::where('organization_id', $store->organization_id)->where('store_id', $store->id)
            ->where('environment', config('deco_reviews.environment'))->where('client_id', config('deco_reviews.active.client_id'))
            ->whereNotNull('access_token')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()->addMinute()))->first();
    }

    private function domain(Store $store): string
    {
        app(ReviewService::class)->active($store);
        abort_unless(preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/', (string) $store->shopify_domain), 422);

        return 'https://'.$store->shopify_domain;
    }

    public function connect(Store $store, string $token): void
    {
        $url = $this->domain($store).'/admin/oauth/access_token';
        try {
            $response = Http::asForm()->acceptJson()->connectTimeout(5)->timeout(20)->post($url, [
                'client_id' => config('deco_reviews.active.client_id'), 'client_secret' => config('deco_reviews.active.client_secret'),
                'grant_type' => 'urn:ietf:params:oauth:grant-type:token-exchange', 'subject_token' => $token,
                'subject_token_type' => 'urn:ietf:params:oauth:token-type:id_token',
                'requested_token_type' => 'urn:shopify:params:oauth:token-type:offline-access-token',
            ]);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['connection' => 'Shopify 连接失败，请刷新应用重试。']);
        }
        if (! $response->successful() || ! is_string($response->json('access_token')) || ! $response->json('access_token')) {
            throw ValidationException::withMessages(['connection' => 'Shopify 身份交换失败，请重新打开应用。']);
        }
        Installation::updateOrCreate(['organization_id' => $store->organization_id, 'store_id' => $store->id, 'environment' => config('deco_reviews.environment')], [
            'client_id' => config('deco_reviews.active.client_id'), 'access_token' => $response->json('access_token'),
            'expires_at' => is_numeric($response->json('expires_in')) ? now()->addSeconds((int) $response->json('expires_in')) : null,
        ]);
        app(ReviewService::class)->audit($store, null, 'app.connected');
    }

    public function query(Store $store, string $query, array $variables): array
    {
        $url = $this->domain($store).'/admin/api/2026-07/graphql.json';
        $installation = $this->installation($store);
        if (! $installation) {
            throw ValidationException::withMessages(['connection' => '请先在 Shopify 打开独立 Deco Reviews 应用完成连接。']);
        }
        try {
            $response = Http::acceptJson()->withHeaders(['X-Shopify-Access-Token' => $installation->access_token])->connectTimeout(5)->timeout(20)
                ->post($url, ['query' => $query, 'variables' => (object) $variables]);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['connection' => 'Shopify 暂不可用，未执行发送。']);
        }
        if (! $response->successful() || $response->json('errors') || ! is_array($response->json('data'))) {
            throw ValidationException::withMessages(['connection' => 'Shopify 权限或数据验证失败，未执行发送。']);
        }

        return $response->json('data');
    }

    public function order(Store $store, string $externalId): array
    {
        $id = str_starts_with($externalId, 'gid://') ? $externalId : 'gid://shopify/Order/'.$externalId;

        return $this->query($store, file_get_contents(__DIR__.'/../graphql/order.graphql'), ['id' => $id]);
    }
}
