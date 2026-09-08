<?php

namespace Tests\Feature;

use App\Domain\ReferralAffiliate\Services\AffiliateAppTokenService;
use App\Domain\ReferralAffiliate\Services\AffiliateCustomerEligibilityService;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AffiliateCustomerEligibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_incomplete_history_is_reviewed_instead_of_assumed_new(): void
    {
        $store = $this->store();
        $this->fakeHistory(5, [['id' => 'gid://shopify/Order/2', 'createdAt' => '2026-09-08T00:00:00Z', 'displayFinancialStatus' => 'PAID', 'test' => false]]);
        $result = app(AffiliateCustomerEligibilityService::class)->newCustomer($store, 'gid://shopify/Customer/1', 'gid://shopify/Order/2', '2026-09-08T00:00:00Z', false);
        $this->assertNull($result['eligible']);
        $this->assertSame('history_requires_review', $result['reason']);
    }

    public function test_refunded_previous_purchase_prevents_new_customer_eligibility(): void
    {
        $store = $this->store();
        $this->fakeHistory(0, [['id' => 'gid://shopify/Order/1', 'createdAt' => '2026-09-07T00:00:00Z', 'displayFinancialStatus' => 'REFUNDED', 'test' => true]]);
        $result = app(AffiliateCustomerEligibilityService::class)->newCustomer($store, 'gid://shopify/Customer/1', 'gid://shopify/Order/2', '2026-09-08T00:00:00Z', true);
        $this->assertFalse($result['eligible']);
        $this->assertSame('previous_paid_order', $result['reason']);
    }

    public function test_customer_lookup_requires_exact_verified_email_match(): void
    {
        $store = $this->store();
        Http::fake(['*' => Http::response(['data' => ['customers' => ['nodes' => [['id' => 'gid://shopify/Customer/1', 'defaultEmailAddress' => ['emailAddress' => 'different@example.invalid']]]]]])]);
        $result = app(AffiliateCustomerEligibilityService::class)->purchasedCustomer($store, 'member@example.invalid');
        $this->assertFalse($result['eligible']);
        $this->assertNull($result['customer_id']);
        Http::assertSentCount(1);
    }

    private function store()
    {
        $org = Organization::query()->create(['name' => 'Eligibility', 'code' => 'eligibility']);
        $store = $org->stores()->create(['name' => 'Test', 'shopify_domain' => 'macfox-test-app.myshopify.com', 'status' => 'active', 'currency' => 'USD']);
        $this->mock(AffiliateAppTokenService::class)->shouldReceive('accessTokenFor')->andReturn('test-only-token');
        Http::preventStrayRequests();

        return $store;
    }

    private function fakeHistory(int $count, array $orders): void
    {
        Http::fake(['*' => Http::response(['data' => ['customer' => ['id' => 'gid://shopify/Customer/1', 'numberOfOrders' => $count, 'orders' => ['nodes' => $orders, 'pageInfo' => ['hasNextPage' => false]]]]])]);
    }
}
