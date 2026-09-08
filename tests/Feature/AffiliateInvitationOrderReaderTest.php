<?php

namespace Tests\Feature;

use App\Domain\ReferralAffiliate\Services\AffiliateAppTokenService;
use App\Domain\ReferralAffiliate\Services\AffiliateInvitationOrderReader;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AffiliateInvitationOrderReaderTest extends TestCase
{
    use RefreshDatabase;

    public function test_invitation_requires_marketing_consent_and_a_current_paid_purchase(): void
    {
        $org = Organization::query()->create(['name' => 'Invite reader', 'code' => 'invite-reader']);
        $store = $org->stores()->create(['name' => 'Test', 'shopify_domain' => 'macfox-test-app.myshopify.com', 'currency' => 'USD', 'status' => 'active']);
        $this->mock(AffiliateAppTokenService::class)->shouldReceive('accessTokenFor')->andReturn('synthetic-token');
        Http::preventStrayRequests();
        $cases = [['SUBSCRIBED', 'PAID', null, true], ['UNSUBSCRIBED', 'PAID', null, false], ['SUBSCRIBED', 'REFUNDED', null, false], ['SUBSCRIBED', 'PAID', now()->toIso8601String(), false]];
        $sequence = Http::sequence();
        foreach ($cases as [$consent,$status,$cancelled,$expected]) {
            $sequence->push(['data' => ['order' => ['id' => 'gid://shopify/Order/1', 'createdAt' => now()->toIso8601String(), 'displayFinancialStatus' => $status, 'cancelledAt' => $cancelled, 'customer' => ['id' => 'gid://shopify/Customer/1', 'defaultEmailAddress' => ['emailAddress' => 'reader@example.invalid', 'marketingState' => $consent]]]]]);
        }
        Http::fake(['*' => $sequence]);
        foreach ($cases as $case) {
            $result = app(AffiliateInvitationOrderReader::class)->eligibleContact($store, 'gid://shopify/Order/1');
            $this->assertSame($case[3], $result !== null);
        }

    }
}
