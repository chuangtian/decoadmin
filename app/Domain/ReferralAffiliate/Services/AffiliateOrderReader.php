<?php

namespace App\Domain\ReferralAffiliate\Services;

use App\Domain\ReferralAffiliate\Support\Money;
use App\Exceptions\AffiliateException;
use App\Models\Store;
use App\Services\Shopify\ShopifyGraphQLClient;

class AffiliateOrderReader
{
    private const QUERY = <<<'GRAPHQL'
query ReferralOrderSnapshot($id: ID!, $after: String) {
 order(id: $id) {
  id name createdAt updatedAt cancelledAt test currencyCode displayFinancialStatus email
  customer { id }
  totalReceivedSet { shopMoney { amount currencyCode } }
  discountCodes customAttributes { key value }
  lineItems(first: 25, after: $after) {
   pageInfo { hasNextPage endCursor }
   nodes {
    id quantity isGiftCard
    originalTotalSet { shopMoney { amount currencyCode } presentmentMoney { amount currencyCode } }
    discountAllocations { allocatedAmountSet { shopMoney { amount currencyCode } } }
    variant { id }
    product { id collections(first: 10) { pageInfo { hasNextPage endCursor } nodes { id } } }
   }
  }
  refunds(first: 100) { id createdAt }
 }
}
GRAPHQL;

    private const REFUND_QUERY = <<<'GRAPHQL'
query ReferralRefund($id: ID!, $after: String) {
 node(id: $id) { ... on Refund {
  refundLineItems(first: 100, after: $after) {
   pageInfo { hasNextPage endCursor }
   nodes { quantity lineItem { id } subtotalSet { shopMoney { amount currencyCode } } }
  }
 } }
}
GRAPHQL;

    private const COLLECTION_QUERY = <<<'GRAPHQL'
query ReferralCollections($id: ID!, $after: String) {
 node(id: $id) { ... on Product {
  collections(first: 100, after: $after) { pageInfo { hasNextPage endCursor } nodes { id } }
 } }
}
GRAPHQL;

    public function __construct(private ShopifyGraphQLClient $api, private AffiliateAppTokenService $tokens) {}

    public function read(Store $store, string $orderId): array
    {
        app(AffiliateShopGuard::class)->store($store);
        if (ctype_digit($orderId)) {
            $orderId = 'gid://shopify/Order/'.$orderId;
        }
        abort_unless(preg_match('/^gid:\/\/shopify\/Order\/\d+$/D', $orderId) === 1, 422);
        $token = $this->tokens->accessTokenFor($store);
        $after = null;
        $lines = [];
        $collectionCache = [];
        $first = null;
        do {
            $data = $this->api->queryWithAccessToken($store->shopify_domain, $token, self::QUERY, ['id' => $orderId, 'after' => $after], 20, '2026-07');
            $order = data_get($data, 'data.order');
            if (! $order) {
                throw new AffiliateException('ORDER_NOT_FOUND', '未找到当前店铺订单。', 404);
            }
            if ($first && $first['updatedAt'] !== $order['updatedAt']) {
                throw new AffiliateException('ORDER_CHANGED', '读取过程中订单发生变化，请重试。', 409);
            }
            $first ??= $order;
            $currency = $order['currencyCode'];
            if ($currency !== strtoupper((string) $store->currency)) {
                throw new AffiliateException('CURRENCY_MISMATCH', '订单本位币与店铺不符，请人工核对。', 409);
            }
            foreach ($order['lineItems']['nodes'] as $line) {
                $productId = data_get($line, 'product.id');
                if ($productId && ! isset($collectionCache[$productId])) {
                    $groups = data_get($line, 'product.collections');
                    $ids = array_column($groups['nodes'], 'id');
                    while ($groups['pageInfo']['hasNextPage']) {
                        $page = $this->api->queryWithAccessToken($store->shopify_domain, $token, self::COLLECTION_QUERY,
                            ['id' => $productId, 'after' => $groups['pageInfo']['endCursor']], 20, '2026-07');
                        $groups = data_get($page, 'data.node.collections');
                        if (! $groups) {
                            throw new AffiliateException('PRODUCT_READ_FAILED', '无法读取商品集合。', 502);
                        }
                        $ids = array_merge($ids, array_column($groups['nodes'], 'id'));
                        if (count($ids) > 10000) {
                            throw new AffiliateException('PRODUCT_REVIEW_REQUIRED', '商品集合数量超过自动处理范围。', 409);
                        }
                    }
                    $collectionCache[$productId] = array_values(array_unique($ids));
                }
                $gross = $this->amount($line['originalTotalSet']['shopMoney'], $currency);
                $discount = 0;
                foreach ($line['discountAllocations'] as $allocation) {
                    $discount += $this->amount($allocation['allocatedAmountSet']['shopMoney'], $currency);
                }
                $lines[] = ['id' => $line['id'], 'quantity' => $line['quantity'], 'base_minor' => max(0, $gross - $discount),
                    'is_gift_card' => $line['isGiftCard'], 'is_tip' => ! $line['product'] && ! $line['variant'],
                    'product_id' => data_get($line, 'product.id'), 'variant_id' => data_get($line, 'variant.id'),
                    'collection_ids' => $collectionCache[$productId] ?? [],
                    'presentment' => $line['originalTotalSet']['presentmentMoney']];
            }
            if (count($lines) > 10000) {
                throw new AffiliateException('ORDER_REVIEW_REQUIRED', '订单商品数量超过自动处理范围。', 409);
            }
            $after = $order['lineItems']['pageInfo']['hasNextPage'] ? $order['lineItems']['pageInfo']['endCursor'] : null;
        } while ($after);
        if (count($first['refunds']) >= 100) {
            throw new AffiliateException('REFUND_REVIEW_REQUIRED', '退款数量超过自动处理范围。', 409);
        }
        $refunds = [];
        foreach ($first['refunds'] as $refund) {
            $refundLines = [];
            $refundAfter = null;
            do {
                $payload = $this->api->queryWithAccessToken($store->shopify_domain, $token, self::REFUND_QUERY,
                    ['id' => $refund['id'], 'after' => $refundAfter], 20, '2026-07');
                $connection = data_get($payload, 'data.node.refundLineItems');
                if (! $connection) {
                    throw new AffiliateException('REFUND_READ_FAILED', '无法读取退款商品。', 502);
                }
                foreach ($connection['nodes'] as $line) {
                    $refundLines[] = [
                        'line_id' => $line['lineItem']['id'], 'quantity' => $line['quantity'],
                        'base_minor' => $this->amount($line['subtotalSet']['shopMoney'], $currency),
                    ];
                }
                $refundAfter = $connection['pageInfo']['hasNextPage'] ? $connection['pageInfo']['endCursor'] : null;
            } while ($refundAfter);
            $refunds[] = ['id' => $refund['id'], 'created_at' => $refund['createdAt'], 'lines' => $refundLines];
        }
        $attributes = array_column($first['customAttributes'], 'value', 'key');

        return ['id' => $orderId, 'name' => $first['name'], 'ordered_at' => $first['createdAt'], 'updated_at' => $first['updatedAt'],
            'cancelled' => $first['cancelledAt'] !== null, 'is_test' => $first['test'], 'currency' => $currency,
            'paid' => in_array($first['displayFinancialStatus'], ['PAID', 'PARTIALLY_REFUNDED', 'REFUNDED'], true),
            'customer_id' => data_get($first, 'customer.id'),
            'customer_email_hash' => ! empty($first['email']) ? hash_hmac('sha256', $store->organization_id.'|'.mb_strtolower(trim($first['email'])), (string) config('app.key')) : null,
            'discount_codes' => $first['discountCodes'],
            'first_tracking_token' => $attributes['deco_aff_first__'] ?? null,
            'last_tracking_token' => $attributes['deco_aff_last__'] ?? null, 'tracking_token' => $attributes['deco_aff'] ?? $attributes['deco_aff__'] ?? null,
            'lines' => $lines, 'refunds' => $refunds];
    }

    private function amount(array $money, string $currency): int
    {
        if (($money['currencyCode'] ?? null) !== $currency) {
            throw new AffiliateException('CURRENCY_MISMATCH', '金额币种不一致。', 409);
        }

        return Money::minor((string) $money['amount'], $currency);
    }
}
