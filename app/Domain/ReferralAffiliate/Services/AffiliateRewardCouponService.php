<?php

namespace App\Domain\ReferralAffiliate\Services;

use App\Domain\ReferralAffiliate\Models\AffiliateReward;
use App\Domain\ReferralAffiliate\Support\Money;
use App\Exceptions\AffiliateException;
use App\Models\Store;
use App\Services\Shopify\ShopifyGraphQLClient;

class AffiliateRewardCouponService
{
    private const FIND = <<<'GRAPHQL'
query ReferralRewardCode($code: String!) {
 codeDiscountNodeByCode(code: $code) { id codeDiscount {
  ... on DiscountCodeBasic { title status asyncUsageCount }
  ... on DiscountCodeFreeShipping { title status asyncUsageCount }
 } }
}
GRAPHQL;

    private const BASIC = <<<'GRAPHQL'
mutation ReferralRewardBasic($input: DiscountCodeBasicInput!) {
 discountCodeBasicCreate(basicCodeDiscount: $input) { codeDiscountNode { id } userErrors { code } }
}
GRAPHQL;

    private const SHIPPING = <<<'GRAPHQL'
mutation ReferralRewardShipping($input: DiscountCodeFreeShippingInput!) {
 discountCodeFreeShippingCreate(freeShippingCodeDiscount: $input) { codeDiscountNode { id } userErrors { code } }
}
GRAPHQL;

    private const DISABLE = <<<'GRAPHQL'
mutation RevokeReferralReward($id: ID!) {
 discountCodeDeactivate(id: $id) { codeDiscountNode { id } userErrors { code } }
}
GRAPHQL;

    public function synchronize(Store $store, AffiliateReward $reward, bool $revoke): array
    {
        app(AffiliateShopGuard::class)->store($store);
        abort_unless((int) $reward->store_id === (int) $store->id && (int) $reward->organization_id === (int) $store->organization_id, 403);
        $token = app(AffiliateAppTokenService::class)->accessTokenFor($store);
        $call = fn ($query, $variables) => app(ShopifyGraphQLClient::class)->queryWithAccessToken($store->shopify_domain, $token, $query, $variables, 20, '2026-07');
        $node = data_get($call(self::FIND, ['code' => $reward->code]), 'data.codeDiscountNodeByCode');
        $title = 'Deco Referral Reward '.$reward->public_id;
        if ($node && (data_get($node, 'codeDiscount.title') !== $title || ($reward->shopify_discount_id && $node['id'] !== $reward->shopify_discount_id))) {
            throw new AffiliateException('REWARD_CODE_CONFLICT', '同名奖励码不属于此记录，未修改。', 409);
        }
        $used = $reward->redeemed_order_id || $reward->status === 'redeemed' || (int) data_get($node, 'codeDiscount.asyncUsageCount', 0) > 0;
        if ($revoke) {
            if ($node && data_get($node, 'codeDiscount.status') !== 'EXPIRED') {
                $this->result($call(self::DISABLE, ['id' => $node['id']]), 'discountCodeDeactivate');
            }

            return ['id' => $node['id'] ?? $reward->shopify_discount_id, 'status' => $used ? 'redeemed' : 'revoked'];
        }
        if ($node) {
            return ['id' => $node['id'], 'status' => $used ? 'redeemed' : (data_get($node, 'codeDiscount.status') === 'EXPIRED' ? 'expired' : 'issued')];
        }
        if ($reward->shopify_discount_id) {
            throw new AffiliateException('REWARD_REMOVED', '奖励码已在 Shopify 被移除，需要人工检查。', 409);
        }
        $rule = $reward->rule_snapshot;
        $input = ['title' => $title, 'code' => $reward->code, 'startsAt' => now()->toIso8601String(), 'endsAt' => $reward->expires_at->toIso8601String(),
            'context' => ['customers' => ['add' => [$reward->customer_id]]], 'usageLimit' => 1, 'appliesOncePerCustomer' => true,
            'combinesWith' => ['orderDiscounts' => false, 'productDiscounts' => false, 'shippingDiscounts' => false]];
        if ($rule['type'] === 'free_shipping') {
            $input += ['destination' => ['all' => true]];
            $id = $this->result($call(self::SHIPPING, ['input' => $input]), 'discountCodeFreeShippingCreate');
        } else {
            $items = match ($rule['scope'] ?? 'all') {
                'product' => ['products' => ['productsToAdd' => $rule['resource_ids']]],'collection' => ['collections' => ['add' => $rule['resource_ids']]],default => ['all' => true]
            };
            $value = $rule['type'] === 'percentage' ? ['percentage' => $rule['basis_points'] / 10000] : ['discountAmount' => ['amount' => Money::decimal($rule['amount_minor'], $store->currency), 'appliesOnEachItem' => false]];
            $input['customerGets'] = ['items' => $items, 'value' => $value];
            $id = $this->result($call(self::BASIC, ['input' => $input]), 'discountCodeBasicCreate');
        }

        return ['id' => $id, 'status' => 'issued'];
    }

    private function result(array $data, string $operation): string
    {
        $id = data_get($data, 'data.'.$operation.'.codeDiscountNode.id');
        if (! $id || data_get($data, 'data.'.$operation.'.userErrors')) {
            throw new AffiliateException('REWARD_DISCOUNT_REJECTED', 'Shopify 未接受奖励码设置，请检查后重试。', 502);
        }

        return $id;
    }
}
