<?php

namespace Tests\Feature;

use App\Domain\ReferralAffiliate\Models\AffiliateProgram;
use App\Domain\ReferralAffiliate\Models\AffiliateProgramMembership;
use App\Domain\ReferralAffiliate\Models\AffiliatePromoter;
use App\Domain\ReferralAffiliate\Models\AffiliateReward;
use App\Domain\ReferralAffiliate\Services\AffiliateAppTokenService;
use App\Domain\ReferralAffiliate\Services\AffiliateRewardCouponService;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AffiliateRewardCouponTest extends TestCase
{
    use RefreshDatabase;

    public function test_reward_is_customer_restricted_single_use_and_creation_is_idempotent(): void
    {
        [$store,$reward] = $this->fixture('fixed');
        $remote = null;
        $creates = 0;
        Http::fake(function ($request) use (&$remote, &$creates, $reward) {
            if (str_contains($request['query'], 'ReferralRewardCode')) {
                return Http::response(['data' => ['codeDiscountNodeByCode' => $remote]]);
            }
            if (str_contains($request['query'], 'ReferralRewardBasic')) {
                $creates++;
                $input = $request['variables']['input'];
                $this->assertSame(['customers' => ['add' => ['gid://shopify/Customer/1']]], $input['context']);
                $this->assertSame(1, $input['usageLimit']);
                $this->assertTrue($input['appliesOncePerCustomer']);
                $this->assertArrayNotHasKey('appliesOnSubscription', $input['customerGets']);
                $this->assertArrayNotHasKey('appliesOnOneTimePurchase', $input['customerGets']);
                $this->assertSame('10.00', $input['customerGets']['value']['discountAmount']['amount']);
                $remote = ['id' => 'gid://shopify/DiscountCodeNode/1', 'codeDiscount' => ['title' => 'Deco Referral Reward '.$reward->public_id, 'status' => 'ACTIVE', 'asyncUsageCount' => 0]];

                return Http::response(['data' => ['discountCodeBasicCreate' => ['codeDiscountNode' => ['id' => $remote['id']], 'userErrors' => []]]]);
            }

            return Http::response(['data' => ['discountCodeDeactivate' => ['codeDiscountNode' => ['id' => $remote['id']], 'userErrors' => []]]]);
        });
        $service = app(AffiliateRewardCouponService::class);
        $a = $service->synchronize($store, $reward, false);
        $b = $service->synchronize($store, $reward, false);
        $this->assertSame($a, $b);
        $this->assertSame(1, $creates);
        $this->assertSame('revoked', $service->synchronize($store, $reward, true)['status']);
    }

    public function test_shipping_reward_uses_shipping_api_and_no_product_amount(): void
    {
        [$store,$reward] = $this->fixture('free_shipping');
        Http::fake(function ($request) {
            if (str_contains($request['query'], 'ReferralRewardCode')) {
                return Http::response(['data' => ['codeDiscountNodeByCode' => null]]);
            }
            $this->assertStringContainsString('ReferralRewardShipping', $request['query']);
            $input = $request['variables']['input'];
            $this->assertSame(['all' => true], $input['destination']);
            $this->assertArrayNotHasKey('customerGets', $input);

            return Http::response(['data' => ['discountCodeFreeShippingCreate' => ['codeDiscountNode' => ['id' => 'gid://shopify/DiscountCodeNode/2'], 'userErrors' => []]]]);
        });
        $this->assertSame('issued', app(AffiliateRewardCouponService::class)->synchronize($store, $reward, false)['status']);
    }

    public function test_verified_redemption_is_not_lost_when_shopify_usage_counter_lags(): void
    {
        [$store, $reward] = $this->fixture('fixed');
        $reward->update(['status' => 'redeemed', 'redeemed_order_id' => 'gid://shopify/Order/10', 'shopify_discount_id' => 'gid://shopify/DiscountCodeNode/1']);
        Http::fake(function ($request) use ($reward) {
            if (str_contains($request['query'], 'ReferralRewardCode')) {
                return Http::response(['data' => ['codeDiscountNodeByCode' => ['id' => $reward->shopify_discount_id, 'codeDiscount' => ['title' => 'Deco Referral Reward '.$reward->public_id, 'status' => 'ACTIVE', 'asyncUsageCount' => 0]]]]);
            }

            return Http::response(['data' => ['discountCodeDeactivate' => ['codeDiscountNode' => ['id' => $reward->shopify_discount_id], 'userErrors' => []]]]);
        });
        $service = app(AffiliateRewardCouponService::class);
        $this->assertSame('redeemed', $service->synchronize($store, $reward, false)['status']);
        $this->assertSame('redeemed', $service->synchronize($store, $reward, true)['status']);
    }

    private function fixture(string $type): array
    {
        $org = Organization::query()->create(['name' => 'Reward code', 'code' => 'reward-code']);
        $store = $org->stores()->create(['name' => 'Test', 'shopify_domain' => 'macfox-test-app.myshopify.com', 'currency' => 'USD', 'status' => 'active']);
        $program = AffiliateProgram::query()->create(['organization_id' => $org->id, 'store_id' => $store->id, 'name' => 'Reward', 'type' => 'advocate', 'currency' => 'USD']);
        $promoter = AffiliatePromoter::query()->create(['organization_id' => $org->id, 'display_name' => 'Test', 'email_encrypted' => 'code@example.invalid', 'email_hash' => str_repeat('e', 64), 'type' => 'advocate', 'status' => 'active']);
        $member = AffiliateProgramMembership::query()->create(['organization_id' => $org->id, 'store_id' => $store->id, 'program_id' => $program->id, 'promoter_id' => $promoter->id, 'status' => 'approved']);
        $reward = AffiliateReward::query()->create(['organization_id' => $org->id, 'store_id' => $store->id, 'membership_id' => $member->id, 'dedupe_key' => 'test', 'customer_id' => 'gid://shopify/Customer/1', 'rule_snapshot' => ['type' => $type, 'amount_minor' => 1000, 'basis_points' => 1000, 'scope' => 'all', 'currency' => 'USD'], 'available_at' => now(), 'expires_at' => now()->addDays(30)]);
        $this->mock(AffiliateAppTokenService::class)->shouldReceive('accessTokenFor')->andReturn('reward-test-token');
        Http::preventStrayRequests();

        return [$store, $reward];
    }
}
