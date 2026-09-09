<?php

namespace App\Domain\ReferralAffiliate\Services;

use App\Jobs\SyncAffiliateOrder;
use App\Models\Store;
use App\Services\Shopify\ShopifyGraphQLClient;

class AffiliateReconciliationService
{
    private const QUERY = <<<'GRAPHQL'
query ReferralRecentOrders($query: String!, $after: String) {
 orders(first: 100, query: $query, after: $after, sortKey: UPDATED_AT) {
  pageInfo { hasNextPage endCursor }
  nodes { id }
 }
}
GRAPHQL;

    public function enqueueRecent(Store $store): int
    {
        app(AffiliateShopGuard::class)->store($store);
        $token = app(AffiliateAppTokenService::class)->accessTokenFor($store);
        $after = null;
        $count = 0;
        do {
            $payload = app(ShopifyGraphQLClient::class)->queryWithAccessToken($store->shopify_domain, $token, self::QUERY,
                ['query' => 'updated_at:>='.now()->subDays(3)->toIso8601String(), 'after' => $after], 20, '2026-07');
            foreach (data_get($payload, 'data.orders.nodes', []) as $order) {
                SyncAffiliateOrder::dispatch((int) $store->organization_id, (int) $store->id, $order['id']);
                $count++;
            }
            $after = data_get($payload, 'data.orders.pageInfo.hasNextPage') ? data_get($payload, 'data.orders.pageInfo.endCursor') : null;
            if ($count >= 10000 && $after) {
                throw new \RuntimeException('Order reconciliation requires a narrower window');
            }
        } while ($after);

        return $count;
    }
}
