<?php

namespace App\Services\StudentDiscount;

use App\Exceptions\StudentDiscountException;
use App\Jobs\SendStudentDiscountDecisionMail;
use App\Models\Store;
use App\Models\StudentDiscountCampaign;
use App\Models\StudentDiscountClaim;
use App\Models\StudentDiscountCode;
use App\Services\Shopify\ShopifyGraphQLClient;
use Illuminate\Support\Facades\Cache;
use Throwable;

class StudentDiscountCodeService
{
    private const CREATE_MUTATION = <<<'GRAPHQL'
        mutation CreateStudentDiscount($basicCodeDiscount: DiscountCodeBasicInput!) {
          discountCodeBasicCreate(basicCodeDiscount: $basicCodeDiscount) {
            codeDiscountNode { id }
            userErrors { field message code }
          }
        }
        GRAPHQL;

    private const FIND_QUERY = <<<'GRAPHQL'
        query FindStudentDiscount($code: String!) {
          codeDiscountNodeByCode(code: $code) {
            id
            codeDiscount {
              ... on DiscountCodeBasic {
                endsAt
                usageLimit
                codes(first: 1) { nodes { code asyncUsageCount } }
              }
            }
          }
        }
        GRAPHQL;

    public function __construct(
        private ShopifyGraphQLClient $shopify,
        private StudentDiscountAppTokenService $tokens,
    ) {}

    public function reusableForEmail(Store $store, string $normalizedEmail): ?StudentDiscountCode
    {
        $code = StudentDiscountCode::query()
            ->where('store_id', $store->id)
            ->where('normalized_email', $normalizedEmail)
            ->where('expires_at', '>', now())
            ->whereColumn('usage_count', '<', 'usage_limit')
            ->latest('generated_at')
            ->first();

        if (! $code) {
            return null;
        }

        $this->syncUsage($code);

        return $code->fresh()->isReusable() ? $code->fresh() : null;
    }

    public function issue(StudentDiscountClaim $claim, StudentDiscountCampaign $campaign): StudentDiscountCode
    {
        $idempotencyKey = 'claim:'.$claim->uuid;
        $existing = StudentDiscountCode::query()
            ->where('store_id', $claim->store_id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
        if ($existing) {
            return $existing;
        }

        return Cache::lock("student-discount-code:{$claim->store_id}:{$claim->uuid}", 45)
            ->block(10, function () use ($claim, $campaign, $idempotencyKey): StudentDiscountCode {
                $existing = StudentDiscountCode::query()
                    ->where('store_id', $claim->store_id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();
                if ($existing) {
                    return $existing;
                }

                $store = $claim->store;
                $accessToken = $this->tokens->accessTokenFor($store);

                $code = $this->codeFor($claim, $campaign);
                $startsAt = now()->utc();
                $expiresAt = $startsAt->copy()->addDays($campaign->validity_days);
                $shopifyId = $this->findShopifyId($store->shopify_domain, $accessToken, $code);
                if (! $shopifyId) {
                    $payload = $this->shopify->queryWithAccessToken($store->shopify_domain, $accessToken, self::CREATE_MUTATION, [
                        'basicCodeDiscount' => $this->shopifyInput($store, $campaign, $code, $startsAt->toIso8601String(), $expiresAt->toIso8601String()),
                    ]);
                    $errors = data_get($payload, 'data.discountCodeBasicCreate.userErrors', []);
                    $shopifyId = data_get($payload, 'data.discountCodeBasicCreate.codeDiscountNode.id');
                    if (! is_string($shopifyId) || $shopifyId === '') {
                        $shopifyId = $this->findShopifyId($store->shopify_domain, $accessToken, $code);
                    }
                    if (! $shopifyId) {
                        throw new StudentDiscountException(
                            'SHOPIFY_DISCOUNT_CREATE_FAILED',
                            is_array($errors) && $errors !== [] ? 'Shopify 未能创建优惠码，请稍后重试。' : 'Shopify 返回了无效的优惠码创建结果。',
                            502,
                        );
                    }
                }

                return StudentDiscountCode::query()->create([
                    'organization_id' => $claim->organization_id,
                    'store_id' => $claim->store_id,
                    'claim_id' => $claim->id,
                    'normalized_email' => $claim->normalized_email,
                    'shopify_discount_id' => $shopifyId,
                    'code' => $code,
                    'status' => 'unused',
                    'usage_count' => 0,
                    'usage_limit' => $campaign->usage_limit,
                    'idempotency_key' => $idempotencyKey,
                    'generated_at' => $startsAt,
                    'expires_at' => $expiresAt,
                ]);
            });
    }

    public function syncUsage(StudentDiscountCode $code): StudentDiscountCode
    {
        try {
            $store = $code->store;
            $accessToken = $this->tokens->accessTokenFor($store);
            $payload = $this->shopify->queryWithAccessToken(
                $store->shopify_domain,
                $accessToken,
                self::FIND_QUERY,
                ['code' => $code->code],
            );
            $usage = data_get($payload, 'data.codeDiscountNodeByCode.codeDiscount.codes.nodes.0.asyncUsageCount');
            if (is_numeric($usage)) {
                $code->usage_count = max(0, (int) $usage);
            }
            $code->last_synced_at = now();
            $code->refreshStatus()->save();
        } catch (Throwable) {
            $code->refreshStatus();
        }

        return $code;
    }

    public function dispatchDecisionEmail(StudentDiscountClaim $claim, ?StudentDiscountCode $code): void
    {
        SendStudentDiscountDecisionMail::dispatch(
            (int) $claim->organization_id,
            (int) $claim->store_id,
            (int) $claim->id,
            $code?->id,
        )->afterCommit();
    }

    private function findShopifyId(string $shopDomain, string $accessToken, string $code): ?string
    {
        $payload = $this->shopify->queryWithAccessToken($shopDomain, $accessToken, self::FIND_QUERY, ['code' => $code]);
        $id = data_get($payload, 'data.codeDiscountNodeByCode.id');

        return is_string($id) && $id !== '' ? $id : null;
    }

    private function codeFor(StudentDiscountClaim $claim, StudentDiscountCampaign $campaign): string
    {
        $prefix = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $campaign->code_prefix) ?: 'STUDENT');

        return substr($prefix, 0, 16).'-'.strtoupper(substr(str_replace('-', '', $claim->uuid), 0, 12));
    }

    /** @return array<string, mixed> */
    private function shopifyInput(Store $store, StudentDiscountCampaign $campaign, string $code, string $startsAt, string $endsAt): array
    {
        $items = match ($campaign->applies_to) {
            'products' => ['products' => ['productsToAdd' => $campaign->target_ids ?: []]],
            'collections' => ['collections' => ['add' => $campaign->target_ids ?: []]],
            default => ['all' => true],
        };
        if ($campaign->applies_to !== 'all' && empty($campaign->target_ids)) {
            throw new StudentDiscountException('CAMPAIGN_TARGETS_REQUIRED', '活动尚未配置适用商品或集合。', 409);
        }

        $value = $campaign->discount_type === 'fixed_amount'
            ? ['discountAmount' => ['amount' => (string) $campaign->discount_value, 'appliesOnEachItem' => false]]
            : ['percentage' => (float) $campaign->discount_value / 100];

        return [
            'title' => $code,
            'code' => $code,
            'startsAt' => $startsAt,
            'endsAt' => $endsAt,
            'context' => ['all' => true],
            'customerGets' => ['value' => $value, 'items' => $items],
            'usageLimit' => $campaign->usage_limit,
            'appliesOncePerCustomer' => false,
            'combinesWith' => [
                'orderDiscounts' => $campaign->combines_with_order_discounts,
                'productDiscounts' => $campaign->combines_with_product_discounts,
                'shippingDiscounts' => $campaign->combines_with_shipping_discounts,
            ],
        ];
    }
}
