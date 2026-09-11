<?php

namespace Tests\Feature;

use App\Domain\ReferralAffiliate\Models\AffiliateCoupon;
use App\Domain\ReferralAffiliate\Models\AffiliateProgram;
use App\Domain\ReferralAffiliate\Models\AffiliateProgramMembership;
use App\Domain\ReferralAffiliate\Models\AffiliatePromoter;
use App\Domain\ReferralAffiliate\Models\AffiliateReward;
use App\Domain\ReferralAffiliate\Models\AffiliateRewardLedgerEntry;
use App\Domain\ReferralAffiliate\Models\AffiliateStoreSetting;
use App\Domain\ReferralAffiliate\Services\AffiliateAccountingService;
use App\Domain\ReferralAffiliate\Services\AffiliateRewardCouponService;
use App\Domain\ReferralAffiliate\Services\AffiliateRewardService;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AffiliateRewardTest extends TestCase
{
    use RefreshDatabase;

    public function test_advocate_earns_non_cash_reward_after_hold_and_refund_revokes_it(): void
    {
        [$store,$member,$order] = $this->fixture();
        $accounting = app(AffiliateAccountingService::class);
        $conversion = $accounting->reconcile($store, $order);
        $reward = AffiliateReward::query()->sole();
        $this->assertSame(0, $conversion->commission_minor);
        $this->assertDatabaseCount('affiliate_ledger_entries', 0);
        $gateway = $this->mock(AffiliateRewardCouponService::class);
        $gateway->shouldReceive('synchronize')->once()->withArgs(fn ($s, $r, $revoke) => $r->id === $reward->id && ! $revoke)->andReturn(['id' => 'gid://shopify/DiscountCodeNode/100', 'status' => 'issued']);
        $gateway->shouldReceive('synchronize')->once()->withArgs(fn ($s, $r, $revoke) => $r->id === $reward->id && $revoke)->andReturn(['id' => 'gid://shopify/DiscountCodeNode/100', 'status' => 'revoked']);
        $service = app(AffiliateRewardService::class);
        $service->sync($store->organization_id, $store->id, $reward->id);
        $this->assertSame('pending', $reward->fresh()->status);
        $this->travel(2)->days();
        $service->sync($store->organization_id, $store->id, $reward->id);
        $this->assertSame('issued', $reward->fresh()->status);
        $this->assertSame(0, $reward->fresh()->attempts);
        $service->record($conversion->fresh());
        $this->assertDatabaseCount('affiliate_rewards', 2);
        $order['updated_at'] = now()->toIso8601String();
        $order['refunds'] = [['id' => 'gid://shopify/Refund/10', 'created_at' => now()->toIso8601String(), 'lines' => [['line_id' => 'gid://shopify/LineItem/10', 'quantity' => 1, 'base_minor' => 10000]]]];
        $accounting->reconcile($store, $order);
        $service->sync($store->organization_id, $store->id, $reward->id);
        $this->assertSame('revoked', $reward->fresh()->status);
        $history = AffiliateRewardLedgerEntry::query()->where('reward_id', $reward->id)->orderBy('id')->get();
        $this->assertSame(['earned', 'issued', 'revoked'], $history->pluck('event')->all());
        $this->assertSame(1000, $history->first()->rule_snapshot['amount_minor']);
        $this->assertSame('refunded', $conversion->fresh()->status);
        $this->assertDatabaseCount('affiliate_ledger_entries', 0);
    }

    public function test_existing_customer_requires_review_and_cannot_receive_reward(): void
    {
        [$store,$member,$order] = $this->fixture();
        $order['customer_eligibility'] = ['eligible' => false, 'reason' => 'previous_paid_order'];
        $conversion = app(AffiliateAccountingService::class)->reconcile($store, $order);
        $this->assertSame('review', $conversion->status);
        $this->travel(2)->days();
        $this->mock(AffiliateRewardCouponService::class)->shouldNotReceive('synchronize');
        $reward = AffiliateReward::query()->sole();
        app(AffiliateRewardService::class)->sync($store->organization_id, $store->id, $reward->id);
        $this->assertSame('pending', $reward->fresh()->status);
    }

    public function test_used_milestone_after_refund_holds_new_rewards_without_rewriting_history(): void
    {
        [$store,$member,$order] = $this->fixture();
        $accounting = app(AffiliateAccountingService::class);
        $conversion = $accounting->reconcile($store, $order);
        $this->travel(2)->days();
        $service = app(AffiliateRewardService::class);
        $service->record($conversion);
        $milestone = AffiliateReward::query()->whereNotNull('threshold')->sole();
        $milestone->update(['status' => 'issued', 'issued_at' => now(), 'shopify_discount_id' => 'gid://shopify/DiscountCodeNode/200']);
        $order['updated_at'] = now()->toIso8601String();
        $order['refunds'] = [['id' => 'gid://shopify/Refund/11', 'created_at' => now()->toIso8601String(), 'lines' => [['line_id' => 'gid://shopify/LineItem/10', 'quantity' => 1, 'base_minor' => 10000]]]];
        $accounting->reconcile($store, $order);
        $this->mock(AffiliateRewardCouponService::class)->shouldReceive('synchronize')->once()->withArgs(fn ($s, $r, $revoke) => $r->id === $milestone->id && $revoke)->andReturn(['id' => $milestone->shopify_discount_id, 'status' => 'redeemed']);
        $service->sync($store->organization_id, $store->id, $milestone->id);
        $this->assertSame('redeemed', $milestone->fresh()->status);
        $this->assertSame('open', $conversion->risks()->where('rule', 'reward_used_after_refund')->sole()->status);
        $next = $order;
        $next['id'] = 'gid://shopify/Order/20';
        $next['ordered_at'] = now()->toIso8601String();
        $next['refunds'] = [];
        $next['customer_id'] = 'gid://shopify/Customer/30';
        $new = $accounting->reconcile($store, $next);
        $this->travel(2)->days();
        $pending = AffiliateReward::query()->where('conversion_id', $new->id)->sole();
        $service->sync($store->organization_id, $store->id, $pending->id);
        $this->assertSame('pending', $pending->fresh()->status);
        $this->assertDatabaseCount('affiliate_ledger_entries', 0);
    }

    public function test_paid_order_records_reward_use_before_async_counter_updates(): void
    {
        [$store, $member, $order] = $this->fixture();
        app(AffiliateAccountingService::class)->reconcile($store, $order);
        $reward = AffiliateReward::query()->sole();
        $reward->update(['status' => 'issued', 'shopify_discount_id' => 'gid://shopify/DiscountCodeNode/500']);
        $purchase = $order;
        $purchase['id'] = 'gid://shopify/Order/500';
        $purchase['customer_id'] = $member->shopify_customer_id;
        $purchase['discount_codes'] = [$reward->code];
        app(AffiliateAccountingService::class)->reconcile($store, $purchase);
        app(AffiliateAccountingService::class)->reconcile($store, $purchase);
        $this->assertSame('redeemed', $reward->fresh()->status);
        $this->assertSame($purchase['id'], $reward->fresh()->redeemed_order_id);
        $this->assertSame(1, AffiliateRewardLedgerEntry::query()->where('reward_id', $reward->id)->where('event', 'redeemed')->count());
    }

    private function fixture(): array
    {
        Queue::fake();
        $this->travelTo(now()->startOfSecond());
        $org = Organization::query()->create(['name' => 'Rewards', 'code' => 'rewards']);
        $store = $org->stores()->create(['name' => 'Test', 'shopify_domain' => 'macfox-test-app.myshopify.com', 'currency' => 'USD', 'status' => 'active']);
        AffiliateStoreSetting::query()->create(['organization_id' => $org->id, 'store_id' => $store->id, 'affiliate_enabled' => false, 'customer_referral_enabled' => true]);
        $rule = ['type' => 'fixed', 'amount_minor' => 1000, 'valid_days' => 30, 'scope' => 'all', 'resource_ids' => []];
        $program = AffiliateProgram::query()->create(['organization_id' => $org->id, 'store_id' => $store->id, 'name' => 'Refer friends', 'type' => 'advocate', 'status' => 'active', 'currency' => 'USD', 'hold_days' => 1, 'settings' => ['reward' => $rule, 'milestones' => [['threshold' => 1, 'reward' => $rule]]]]);
        $program->rules()->create(['organization_id' => $org->id, 'store_id' => $store->id, 'scope' => 'program', 'commission_type' => 'percentage', 'rate_basis_points' => 1000]);
        $promoter = AffiliatePromoter::query()->create(['organization_id' => $org->id, 'email_encrypted' => 'advocate@example.invalid', 'email_hash' => str_repeat('b', 64), 'display_name' => 'Advocate', 'type' => 'advocate', 'status' => 'active']);
        $member = AffiliateProgramMembership::query()->create(['organization_id' => $org->id, 'store_id' => $store->id, 'program_id' => $program->id, 'promoter_id' => $promoter->id, 'status' => 'approved', 'shopify_customer_id' => 'gid://shopify/Customer/10']);
        AffiliateCoupon::query()->create(['organization_id' => $org->id, 'store_id' => $store->id, 'membership_id' => $member->id, 'code' => 'FRIEND10', 'normalized_code' => 'FRIEND10', 'status' => 'active', 'last_synced_at' => now()]);
        $this->travel(5)->seconds();

        return [$store, $member, ['id' => 'gid://shopify/Order/10', 'name' => '#REWARD', 'ordered_at' => now()->toIso8601String(), 'updated_at' => now()->toIso8601String(), 'paid' => true, 'cancelled' => false, 'is_test' => true, 'currency' => 'USD', 'customer_id' => 'gid://shopify/Customer/20', 'customer_eligibility' => ['eligible' => true],
            'discount_codes' => ['FRIEND10'], 'lines' => [['id' => 'gid://shopify/LineItem/10', 'quantity' => 1, 'base_minor' => 10000]], 'refunds' => []]];
    }
}
