<?php

namespace App\Domain\ReferralAffiliate\Services;

use App\Exceptions\AffiliateException;
use App\Models\Store;
use App\Services\Shopify\ShopifyGraphQLClient;
use Carbon\CarbonImmutable;

class AffiliateCustomerEligibilityService
{
    private const FIND = <<<'GRAPHQL'
query ReferralCustomerByEmail($query: String!) {
 customers(first: 2, query: $query) { nodes { id defaultEmailAddress { emailAddress } } }
}
GRAPHQL;

    private const HISTORY = <<<'GRAPHQL'
query ReferralCustomerHistory($id: ID!, $query: String!) {
 customer(id: $id) {
  id numberOfOrders
  orders(first: 100, reverse: true, query: $query) {
   pageInfo { hasNextPage }
   nodes { id createdAt displayFinancialStatus test }
  }
 }
}
GRAPHQL;

    private const SEGMENTS = <<<'GRAPHQL'
query ReferralNewCustomerSegments($after: String) {
 segments(first: 100, after: $after) { nodes { id query } pageInfo { hasNextPage endCursor } }
}
GRAPHQL;

    public function newCustomerSegment(Store $store): string
    {
        app(AffiliateShopGuard::class)->store($store);
        $after = null;
        $count = 0;
        do {
            $connection = data_get($this->call($store, self::SEGMENTS, ['after' => $after]), 'data.segments');
            foreach ($connection['nodes'] ?? [] as $segment) {
                if (preg_match('/^\s*number_of_orders\s*=\s*0\s*$/i', $segment['query'])) {
                    return $segment['id'];
                }
                $count++;
            }
            $after = ($connection['pageInfo']['hasNextPage'] ?? false) ? $connection['pageInfo']['endCursor'] : null;
        } while ($after && $count < 1000);
        throw new AffiliateException('NEW_CUSTOMER_SEGMENT_REQUIRED', '请先在 Shopify 建立「从未下单的顾客」细分，再同步好友优惠码。', 422);
    }

    public function purchasedCustomer(Store $store, string $email): array
    {
        app(AffiliateShopGuard::class)->store($store);
        $normalized = mb_strtolower(trim($email));
        $data = $this->call($store, self::FIND, ['query' => 'email:"'.addcslashes($normalized, '\\"').'"']);
        $matches = collect(data_get($data, 'data.customers.nodes', []))->filter(fn ($row) => is_string(data_get($row, 'defaultEmailAddress.emailAddress')) && mb_strtolower(trim(data_get($row, 'defaultEmailAddress.emailAddress'))) === $normalized);
        if ($matches->count() !== 1) {
            return ['eligible' => false, 'reason' => 'customer_not_verified', 'customer_id' => null];
        }
        $id = $matches->first()['id'];
        $history = $this->history($store, $id, false);
        $paid = collect($history['orders']['nodes'])->contains(fn ($order) => in_array($order['displayFinancialStatus'], ['PAID', 'PARTIALLY_REFUNDED'], true));
        if (! $paid && app()->environment(['local', 'testing', 'test', 'staging'])) {
            $testHistory = $this->history($store, $id, true);
            $paid = collect($testHistory['orders']['nodes'])->contains(fn ($order) => in_array($order['displayFinancialStatus'], ['PAID', 'PARTIALLY_REFUNDED'], true));
        }

        return ['eligible' => $paid, 'reason' => $paid ? 'verified_paid_customer' : 'no_verified_paid_purchase', 'customer_id' => $id];
    }

    public function newCustomer(Store $store, string $customerId, string $orderId, string $orderedAt, bool $isTest): array
    {
        $customer = $this->history($store, $customerId, $isTest);
        $at = CarbonImmutable::parse($orderedAt);
        $orders = collect($customer['orders']['nodes']);
        $prior = $orders->first(fn ($order) => $order['id'] !== $orderId && (bool) $order['test'] === $isTest
            && CarbonImmutable::parse($order['createdAt'])->lte($at)
            && in_array($order['displayFinancialStatus'], ['PAID', 'PARTIALLY_REFUNDED', 'REFUNDED'], true));
        if ($prior) {
            return ['eligible' => false, 'reason' => 'previous_paid_order'];
        }
        $visibleRealOrders = $orders->where('test', false)->count();
        if ($customer['orders']['pageInfo']['hasNextPage'] || (! $isTest && (int) $customer['numberOfOrders'] > $visibleRealOrders)) {
            return ['eligible' => null, 'reason' => 'history_requires_review'];
        }

        return ['eligible' => true, 'reason' => 'no_previous_paid_order'];
    }

    private function history(Store $store, string $id, bool $isTest): array
    {
        app(AffiliateShopGuard::class)->store($store);
        abort_unless(preg_match('~^gid://shopify/Customer/[0-9]+$~D', $id), 422);
        $customer = data_get($this->call($store, self::HISTORY, ['id' => $id, 'query' => $isTest ? 'test:true status:any' : 'test:false status:any']), 'data.customer');
        if (! $customer) {
            throw new AffiliateException('CUSTOMER_NOT_FOUND', '无法核验本店顾客，请检查关联记录。', 422);
        }

        return $customer;
    }

    private function call(Store $store, string $query, array $variables): array
    {
        $token = app(AffiliateAppTokenService::class)->accessTokenFor($store);

        return app(ShopifyGraphQLClient::class)->queryWithAccessToken($store->shopify_domain, $token, $query, $variables, 20, '2026-07');
    }
}
