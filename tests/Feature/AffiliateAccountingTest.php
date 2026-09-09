<?php

namespace Tests\Feature;

use App\Domain\ReferralAffiliate\Models\AffiliateCoupon;
use App\Domain\ReferralAffiliate\Models\AffiliateLedgerEntry;
use App\Domain\ReferralAffiliate\Models\AffiliateProgram;
use App\Domain\ReferralAffiliate\Models\AffiliateProgramMembership;
use App\Domain\ReferralAffiliate\Models\AffiliatePromoter;
use App\Domain\ReferralAffiliate\Models\AffiliateStoreSetting;
use App\Domain\ReferralAffiliate\Services\AffiliateAccountingService;
use App\Domain\ReferralAffiliate\Services\AffiliateRuleHistory;
use App\Domain\ReferralAffiliate\Support\Money;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AffiliateAccountingTest extends TestCase
{
    use RefreshDatabase;

    public function test_money_uses_currency_precision_and_half_up_without_float(): void
    {
        $this->assertSame(1234, Money::minor('12.34', 'USD'));
        $this->assertSame(1234, Money::minor('1234', 'JPY'));
        $this->assertSame(1234, Money::minor('1.234', 'KWD'));
        $this->assertSame('1.234', Money::decimal(1234, 'KWD'));
        $this->assertSame(2, Money::proportional(15, 1000, 10000));
        $this->assertSame(-2, Money::proportional(-15, 1000, 10000));
    }

    public function test_paid_order_is_unique_and_original_rules_are_frozen(): void
    {
        [$store, $member, $order] = $this->fixture();
        $service = app(AffiliateAccountingService::class);
        $first = $service->reconcile($store, $order);
        $this->assertSame(17269, $first->commission_minor);
        $this->assertSame(143910, $first->base_minor);
        $this->assertSame('coupon', $first->source);
        $member->program->rules()->update(['rate_basis_points' => 5000]);
        $second = $service->reconcile($store, $order);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(17269, $second->commission_minor);
        $this->assertDatabaseCount('affiliate_conversions', 1);
        $this->assertDatabaseCount('affiliate_ledger_entries', 1);
        $entry = AffiliateLedgerEntry::query()->sole();
        $this->assertSame('pending', $entry->status);
        $this->expectException(\LogicException::class);
        $entry->update(['amount_minor' => 1]);
    }

    public function test_refunds_and_cancellation_append_only_and_never_over_reverse(): void
    {
        [$store, , $order] = $this->fixture();
        $service = app(AffiliateAccountingService::class);
        $first = $service->reconcile($store, $order);
        $order['refunds'] = [['id' => 'gid://shopify/Refund/1', 'created_at' => now()->toIso8601String(),
            'lines' => [['line_id' => 'gid://shopify/LineItem/1', 'quantity' => 1, 'base_minor' => 71955]]]];
        $refund = $service->reconcile($store, $order);
        $this->assertSame(8635, $refund->reversed_minor);
        $service->reconcile($store, $order);
        $this->assertDatabaseCount('affiliate_refund_records', 1);
        $this->assertDatabaseCount('affiliate_ledger_entries', 2);
        $order['cancelled'] = true;
        $cancelled = $service->reconcile($store, $order);
        $this->assertSame('cancelled', $cancelled->status);
        $this->assertSame($first->commission_minor, $cancelled->reversed_minor);
        $order['refunds'][] = ['id' => 'gid://shopify/Refund/2', 'created_at' => now()->toIso8601String(),
            'lines' => [['line_id' => 'gid://shopify/LineItem/1', 'quantity' => 1, 'base_minor' => 71955]]];
        $service->reconcile($store, $order);
        $this->assertSame(0, (int) AffiliateLedgerEntry::query()->sum('amount_minor'));
        $this->assertDatabaseHas('affiliate_ledger_entries', ['type' => 'commission_accrual', 'amount_minor' => 17269]);
        $this->assertDatabaseHas('affiliate_ledger_entries', ['type' => 'cancellation_adjustment', 'amount_minor' => -8634]);
    }

    public function test_refund_before_first_processing_is_reconciled_in_same_transaction(): void
    {
        [$store, , $order] = $this->fixture();
        $order['refunds'] = [['id' => 'gid://shopify/Refund/3', 'created_at' => now()->toIso8601String(),
            'lines' => [['line_id' => 'gid://shopify/LineItem/1', 'quantity' => 2, 'base_minor' => 143910]]]];
        $conversion = app(AffiliateAccountingService::class)->reconcile($store, $order);
        $this->assertSame('refunded', $conversion->status);
        $this->assertSame(0, (int) AffiliateLedgerEntry::query()->sum('amount_minor'));
    }

    public function test_unpaid_order_and_cross_store_coupon_do_not_accrue(): void
    {
        [$store, , $order] = $this->fixture();
        $service = app(AffiliateAccountingService::class);
        $order['paid'] = false;
        $this->assertNull($service->reconcile($store, $order));
        $order['paid'] = true;
        $order['discount_codes'] = ['OTHER-SHOP-CODE'];
        $this->assertSame('unattributed', $service->reconcile($store, $order)->status);
        $this->assertDatabaseCount('affiliate_ledger_entries', 0);
        $store->shopify_domain = 'another-shop.myshopify.com';
        $this->expectException(HttpException::class);
        $service->reconcile($store, $order);
    }

    public function test_self_purchase_is_blocked_and_email_match_requires_review(): void
    {
        [$store, $member, $order] = $this->fixture();
        $member->update(['shopify_customer_id' => 'gid://shopify/Customer/9']);
        $order['customer_id'] = 'gid://shopify/Customer/9';
        $service = app(AffiliateAccountingService::class);
        $this->assertSame('rejected', $service->reconcile($store, $order)->status);
        $this->assertDatabaseCount('affiliate_ledger_entries', 0);
        $order['id'] = 'gid://shopify/Order/2';
        $order['customer_id'] = 'gid://shopify/Customer/10';
        $order['customer_email_hash'] = $member->promoter->email_hash;
        $this->assertSame('review', $service->reconcile($store, $order)->status);
        $this->assertSame('pending', AffiliateLedgerEntry::query()->sole()->status);
    }

    public function test_delayed_paid_order_uses_rules_from_order_time(): void
    {
        [$store,$member,$order] = $this->fixture();
        $history = app(AffiliateRuleHistory::class);
        $history->baseline($member->program);
        $this->travel(1)->day();
        $member->program->rules()->update(['rate_basis_points' => 5000]);
        $member->program->update(['hold_days' => 60]);
        $history->record($member->program);
        $conversion = app(AffiliateAccountingService::class)->reconcile($store, $order);
        $this->assertSame(17269, $conversion->commission_minor);
        $this->assertSame(30, $conversion->rule_snapshot['hold_days']);
        $this->assertSame(30, (int) $conversion->ordered_at->diffInDays($conversion->available_at));
    }

    private function fixture(): array
    {
        $this->travelTo(now()->startOfSecond());
        $org = Organization::query()->create(['name' => 'Accounting test', 'code' => 'accounting-test']);
        $store = $org->stores()->create(['name' => 'Test', 'shopify_domain' => 'macfox-test-app.myshopify.com', 'status' => 'active', 'currency' => 'USD']);
        AffiliateStoreSetting::query()->create(['organization_id' => $org->id, 'store_id' => $store->id, 'affiliate_enabled' => true]);
        $program = AffiliateProgram::query()->create(['organization_id' => $org->id, 'store_id' => $store->id, 'name' => '12%', 'type' => 'affiliate', 'status' => 'active', 'currency' => 'USD', 'hold_days' => 30, 'coupon_enabled' => true]);
        $program->rules()->create(['organization_id' => $org->id, 'store_id' => $store->id, 'scope' => 'program', 'commission_type' => 'percentage', 'rate_basis_points' => 1200]);
        $promoter = AffiliatePromoter::query()->create(['organization_id' => $org->id, 'email_encrypted' => 'promoter@example.invalid', 'email_hash' => str_repeat('a', 64), 'display_name' => 'Test', 'type' => 'affiliate', 'status' => 'active']);
        $member = AffiliateProgramMembership::query()->create(['organization_id' => $org->id, 'store_id' => $store->id, 'program_id' => $program->id, 'promoter_id' => $promoter->id, 'status' => 'approved', 'approved_at' => now()]);
        AffiliateCoupon::query()->create(['organization_id' => $org->id, 'store_id' => $store->id, 'membership_id' => $member->id, 'code' => 'TEST12', 'normalized_code' => 'TEST12', 'status' => 'active', 'last_synced_at' => now()]);
        $this->travel(5)->seconds();
        $order = ['id' => 'gid://shopify/Order/1', 'name' => '#TEST', 'ordered_at' => now()->toIso8601String(), 'updated_at' => now()->toIso8601String(),
            'paid' => true, 'is_test' => true, 'cancelled' => false, 'currency' => 'USD', 'discount_codes' => ['TEST12'], 'refunds' => [],
            'lines' => [['id' => 'gid://shopify/LineItem/1', 'quantity' => 2, 'base_minor' => 143910, 'is_gift_card' => false]]];

        return [$store, $member, $order];
    }
}
