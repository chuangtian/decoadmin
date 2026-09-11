<?php

namespace Tests\Feature;

use App\Domain\ReferralAffiliate\Models\AffiliateClick;
use App\Domain\ReferralAffiliate\Models\AffiliateCoupon;
use App\Domain\ReferralAffiliate\Models\AffiliateLink;
use App\Domain\ReferralAffiliate\Models\AffiliateProgram;
use App\Domain\ReferralAffiliate\Models\AffiliateProgramMembership;
use App\Domain\ReferralAffiliate\Models\AffiliatePromoter;
use App\Domain\ReferralAffiliate\Models\AffiliateStoreSetting;
use App\Domain\ReferralAffiliate\Services\AffiliateAttributionEngine;
use App\Domain\ReferralAffiliate\Services\AffiliateTrackingTokenService;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AffiliateAttributionSignalsTest extends TestCase
{
    use RefreshDatabase;

    public static function modes(): array
    {
        return [['first_click', 0], ['last_click', 1], ['coupon_wins', 1]];
    }

    #[DataProvider('modes')]
    public function test_click_owner_and_evidence_agree_and_valid_coupon_keeps_priority(string $mode, int $index): void
    {
        $this->travelTo(now()->startOfSecond());
        $org = Organization::query()->create(['name' => 'Signals', 'code' => 'signals']);
        $store = $org->stores()->create(['name' => 'Test', 'shopify_domain' => 'macfox-test-app.myshopify.com', 'status' => 'active', 'currency' => 'USD']);
        AffiliateStoreSetting::query()->create(['organization_id' => $org->id, 'store_id' => $store->id, 'affiliate_enabled' => true]);
        $program = AffiliateProgram::query()->create(['organization_id' => $org->id, 'store_id' => $store->id, 'name' => 'Signals', 'type' => 'affiliate', 'currency' => 'USD', 'status' => 'active', 'attribution_model' => $mode, 'attribution_window_days' => 30]);
        $members = $clicks = $tokens = [];
        foreach ([0, 1] as $i) {
            $promoter = AffiliatePromoter::query()->create(['organization_id' => $org->id, 'email_encrypted' => 'signals'.$i.'@example.invalid', 'email_hash' => hash('sha256', 'signals'.$i), 'display_name' => 'Signals '.$i, 'type' => 'affiliate', 'status' => 'active']);
            $member = AffiliateProgramMembership::query()->create(['organization_id' => $org->id, 'store_id' => $store->id, 'program_id' => $program->id, 'promoter_id' => $promoter->id, 'status' => 'approved']);
            $link = AffiliateLink::query()->create(['organization_id' => $org->id, 'store_id' => $store->id, 'membership_id' => $member->id, 'referral_code' => 'signals'.$i, 'normalized_referral_code' => 'SIGNALS'.$i, 'status' => 'active']);
            $this->travel(2)->seconds();
            $click = AffiliateClick::query()->create(['organization_id' => $org->id, 'store_id' => $store->id, 'membership_id' => $member->id, 'link_id' => $link->id, 'visitor_token' => hash('sha256', 'visitor'.$i), 'occurred_at' => now()]);
            $members[] = $member;
            $clicks[] = $click;
            $tokens[] = app(AffiliateTrackingTokenService::class)->issue($link, $click);
        }
        $this->travel(2)->seconds();
        $order = ['id' => 'gid://shopify/Order/999', 'currency' => 'USD', 'ordered_at' => now()->toIso8601String(), 'discount_codes' => [], 'first_tracking_token' => $tokens[0], 'last_tracking_token' => $tokens[1]];
        $engine = app(AffiliateAttributionEngine::class);
        $result = $engine->resolve($store, $order);
        $this->assertSame($members[$index]->id, $result['membership']->id);
        $this->assertSame($clicks[$index]->id, $result['source_click_id']);
        AffiliateCoupon::query()->create(['organization_id' => $org->id, 'store_id' => $store->id, 'membership_id' => $members[0]->id, 'code' => 'SIGNALS', 'normalized_code' => 'SIGNALS', 'status' => 'active', 'last_synced_at' => now()]);
        $order['discount_codes'] = ['signals'];
        $result = $engine->resolve($store, $order);
        $this->assertSame($members[0]->id, $result['membership']->id);
        $this->assertSame('coupon', $result['source']);
        $this->assertSame($clicks[0]->id, $result['source_click_id']);
    }
}
