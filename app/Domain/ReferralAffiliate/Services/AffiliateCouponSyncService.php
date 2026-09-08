<?php

namespace App\Domain\ReferralAffiliate\Services;

use App\Domain\ReferralAffiliate\Models\AffiliateCoupon;
use App\Domain\ReferralAffiliate\Models\AffiliateStoreSetting;
use App\Domain\ReferralAffiliate\Support\Money;
use App\Exceptions\AffiliateException;
use App\Models\AuditLog;
use App\Models\Store;
use App\Services\Shopify\ShopifyGraphQLClient;
use Illuminate\Support\Facades\Cache;
use Throwable;

class AffiliateCouponSyncService
{
    private const FIND = <<<'GRAPHQL'
        query ReferralCoupon($code: String!) {
          codeDiscountNodeByCode(code: $code) {
            id
            codeDiscount { ... on DiscountCodeBasic { title status } }
          }
        }
        GRAPHQL;

    private const CREATE = <<<'GRAPHQL'
        mutation CreateReferralCoupon($input: DiscountCodeBasicInput!) {
          discountCodeBasicCreate(basicCodeDiscount: $input) {
            codeDiscountNode { id }
            userErrors { code }
          }
        }
        GRAPHQL;

    private const UPDATE = <<<'GRAPHQL'
        mutation UpdateReferralCoupon($id: ID!, $input: DiscountCodeBasicInput!) {
          discountCodeBasicUpdate(id: $id, basicCodeDiscount: $input) {
            codeDiscountNode { id }
            userErrors { code }
          }
        }
        GRAPHQL;

    private const DEACTIVATE = <<<'GRAPHQL'
        mutation DisableReferralCoupon($id: ID!) {
          discountCodeDeactivate(id: $id) {
            codeDiscountNode { id }
            userErrors { code }
          }
        }
        GRAPHQL;

    public function __construct(private ShopifyGraphQLClient $api, private AffiliateAppTokenService $tokens) {}

    public function sync(int $organizationId, int $storeId, int $couponId): AffiliateCoupon
    {
        $store = Store::query()->where('organization_id', $organizationId)->findOrFail($storeId);
        app(AffiliateShopGuard::class)->store($store);

        return Cache::lock("affiliate-coupon:{$storeId}:{$couponId}", 120)->block(5, function () use ($organizationId, $storeId, $couponId, $store) {
            $coupon = AffiliateCoupon::query()->where('organization_id', $organizationId)->where('store_id', $storeId)->findOrFail($couponId);
            $coupon->load(['membership.program', 'membership.promoter']);
            $membership = $coupon->membership;
            $program = $membership?->program;
            abort_unless($membership && $program && $membership->promoter
                && (int) $membership->organization_id === $organizationId && (int) $membership->store_id === $storeId
                && (int) $program->organization_id === $organizationId && (int) $program->store_id === $storeId
                && (int) $membership->promoter->organization_id === $organizationId, 403);
            $enabled = $membership->status->value === 'approved' && $membership->promoter->status === 'active'
                && $program->status->value === 'active' && $program->coupon_enabled
                && (! $program->ends_at || $program->ends_at->isFuture())
                && AffiliateStoreSetting::query()->where('organization_id', $organizationId)->where('store_id', $storeId)->where('affiliate_enabled', true)->exists();
            $coupon->forceFill(['status' => $enabled ? 'enable_pending' : 'disable_pending', 'last_error' => null])->save();
            try {
                $token = $this->tokens->accessTokenFor($store);
                $call = fn (string $query, array $variables) => $this->api->queryWithAccessToken($store->shopify_domain, $token, $query, $variables, 20, '2026-07');
                $node = data_get($call(self::FIND, ['code' => $coupon->code]), 'data.codeDiscountNodeByCode');
                $title = 'Deco Referral '.$coupon->public_id;
                if ($node && (data_get($node, 'codeDiscount.title') !== $title
                    || ($coupon->shopify_discount_id && $coupon->shopify_discount_id !== $node['id']))) {
                    throw new AffiliateException('AFFILIATE_COUPON_CONFLICT', '同名优惠码不属于该推广记录，未修改 Shopify 折扣。', 409);
                }
                if (! $node && $coupon->shopify_discount_id && $enabled) {
                    throw new AffiliateException('AFFILIATE_COUPON_REMOVED', 'Shopify 优惠码已被移除，请检查后处理。', 409);
                }
                $id = $node['id'] ?? null;
                if ($enabled) {
                    $coupon->starts_at ??= $program->starts_at ?? now();
                    $coupon->save();
                    if ($program->customer_discount_type === 'percentage') {
                        $rate = (int) $program->customer_discount_rate_basis_points;
                        abort_unless($rate > 0 && $rate <= 10000, 422);
                        $value = ['percentage' => $rate / 10000];
                    } else {
                        abort_unless($program->currency === strtoupper((string) $store->currency)
                            && $program->customer_discount_amount_minor > 0, 422);
                        $value = ['discountAmount' => ['amount' => Money::decimal($program->customer_discount_amount_minor, $program->currency), 'appliesOnEachItem' => false]];
                    }
                    $input = ['title' => $title, 'code' => $coupon->code,
                        'startsAt' => $coupon->starts_at->toIso8601String(), 'endsAt' => $program->ends_at?->toIso8601String(),
                        'context' => ['all' => 'ALL'], 'customerGets' => ['items' => ['all' => true], 'value' => $value],
                        'appliesOncePerCustomer' => false,
                        'combinesWith' => ['orderDiscounts' => false, 'productDiscounts' => false, 'shippingDiscounts' => false]];
                    $operation = $id ? 'discountCodeBasicUpdate' : 'discountCodeBasicCreate';
                    $result = $call($id ? self::UPDATE : self::CREATE, $id ? ['id' => $id, 'input' => $input] : ['input' => $input]);
                    $id = $this->resultId($result, $operation);
                } elseif ($id && data_get($node, 'codeDiscount.status') !== 'EXPIRED') {
                    $this->resultId($call(self::DEACTIVATE, ['id' => $id]), 'discountCodeDeactivate');
                }
                $coupon->forceFill(['shopify_discount_id' => $id ?? $coupon->shopify_discount_id,
                    'status' => $enabled ? ($coupon->starts_at->isFuture() ? 'scheduled' : 'active') : 'disabled',
                    'ends_at' => $program->ends_at, 'last_error' => null, 'last_synced_at' => now()])->save();
                AuditLog::query()->create(['organization_id' => $organizationId, 'store_id' => $storeId,
                    'action' => 'affiliate_coupon_synced', 'subject_type' => AffiliateCoupon::class, 'subject_id' => $coupon->id,
                    'metadata' => ['status' => $coupon->status, 'shopify_discount_id' => $coupon->shopify_discount_id]]);

                return $coupon;
            } catch (Throwable $exception) {
                $message = $exception instanceof AffiliateException ? $exception->getMessage() : 'Shopify 优惠码同步失败，将重试；当前状态尚未确认。';
                $coupon->forceFill(['last_error' => $message])->save();
                // Never include raw Shopify error bodies or tokens in queue error logs.
                throw new AffiliateException('AFFILIATE_COUPON_SYNC_FAILED', $message, 502);
            }
        });
    }

    private function resultId(array $result, string $operation): string
    {
        $id = data_get($result, "data.{$operation}.codeDiscountNode.id");
        if (! is_string($id) || $id === '' || ! empty(data_get($result, "data.{$operation}.userErrors"))) {
            throw new AffiliateException('AFFILIATE_DISCOUNT_REJECTED', 'Shopify 未接受优惠码设置，请检查后重试。', 502);
        }

        return $id;
    }
}
