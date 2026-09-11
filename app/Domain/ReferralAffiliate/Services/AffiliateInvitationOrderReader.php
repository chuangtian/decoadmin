<?php

namespace App\Domain\ReferralAffiliate\Services;

use App\Models\Store;
use App\Services\Shopify\ShopifyGraphQLClient;

class AffiliateInvitationOrderReader
{
    private const QUERY = <<<'GRAPHQL'
query ReferralInvitationCustomer($id: ID!) {
 order(id: $id) {
  id createdAt displayFinancialStatus cancelledAt
  customer { id defaultEmailAddress { emailAddress marketingState } }
 }
}
GRAPHQL;

    public function eligibleContact(Store $store, string $orderId): ?array
    {
        app(AffiliateShopGuard::class)->store($store);
        abort_unless(preg_match('~^gid://shopify/Order/[0-9]+$~D', $orderId), 422);
        $token = app(AffiliateAppTokenService::class)->accessTokenFor($store);
        $order = data_get(app(ShopifyGraphQLClient::class)->queryWithAccessToken($store->shopify_domain, $token, self::QUERY, ['id' => $orderId], 20, '2026-07'), 'data.order');
        if (! $order || $order['cancelledAt'] || ! in_array($order['displayFinancialStatus'], ['PAID', 'PARTIALLY_REFUNDED'], true)) {
            return null;
        }
        $email = data_get($order, 'customer.defaultEmailAddress.emailAddress');
        if (data_get($order, 'customer.defaultEmailAddress.marketingState') !== 'SUBSCRIBED' || ! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return ['customer_id' => $order['customer']['id'], 'email' => mb_strtolower(trim($email)), 'ordered_at' => $order['createdAt']];
    }
}
