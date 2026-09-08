<?php

namespace Tests\Feature;

use App\Domain\ReferralAffiliate\Models\AffiliatePortalToken;
use App\Domain\ReferralAffiliate\Models\AffiliateProgram;
use App\Domain\ReferralAffiliate\Models\AffiliateProgramMembership;
use App\Domain\ReferralAffiliate\Models\AffiliatePromoter;
use App\Domain\ReferralAffiliate\Services\AffiliatePortalService;
use App\Models\Organization;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AffiliatePortalTest extends TestCase
{
    use RefreshDatabase;

    public function test_magic_link_is_hashed_single_use_and_membership_scoped(): void
    {
        [$store,$member] = $this->context();
        $service = app(AffiliatePortalService::class);
        $raw = $service->issue($store, $member);
        $this->assertSame(hash('sha256', $raw), AffiliatePortalToken::query()->sole()->token_hash);
        $this->assertSame($member->id, $service->consume($store, $raw)->id);
        $this->expectException(HttpException::class);
        $service->consume($store, $raw);
    }

    public function test_expired_or_revoked_membership_cannot_use_magic_link(): void
    {
        [$store,$member] = $this->context();
        $service = app(AffiliatePortalService::class);
        $raw = $service->issue($store, $member);
        $this->travel(16)->minutes();
        $this->expectException(HttpException::class);
        $service->consume($store, $raw);
    }

    public function test_public_portal_uses_separate_cookie_and_never_embeds_raw_login_token(): void
    {
        $this->context();
        $response = $this->get('/referral-portal/login');
        $response->assertOk()->assertSee('location.hash', false)->assertHeader('Referrer-Policy', 'no-referrer');
        $cookies = $response->headers->getCookies();
        $cookie = collect($cookies)->first(fn ($c) => $c->getName() === 'deco_referral_portal');
        $this->assertNotNull($cookie);
        $this->assertSame('/referral-portal', $cookie->getPath());
        $this->assertNotSame('deco_referral_portal', config('session.cookie'));
    }

    public function test_paused_program_remains_available_for_login_but_not_application(): void
    {
        [$store,$member] = $this->context();
        $member->program->update(['status' => 'paused']);
        $response = $this->get('/referral-portal')->assertOk();
        $html = $response->getContent();
        $parts = explode('action="/referral-portal/request-login"', $html);
        $this->assertCount(2, $parts);
        $this->assertStringNotContainsString($member->program->public_id, $parts[0]);
        $this->assertStringContainsString($member->program->public_id, $parts[1]);
    }

    public function test_revoked_membership_blocks_a_previously_issued_token(): void
    {
        [$store,$member] = $this->context();
        $service = app(AffiliatePortalService::class);
        $token = $service->issue($store, $member);
        $member->update(['status' => 'suspended']);
        $this->expectException(ModelNotFoundException::class);
        $service->consume($store, $token);
    }

    private function context(): array
    {
        $org = Organization::query()->create(['name' => 'Portal', 'code' => 'portal']);
        $store = $org->stores()->create(['name' => 'Test', 'shopify_domain' => 'macfox-test-app.myshopify.com', 'status' => 'active', 'currency' => 'USD']);
        $program = AffiliateProgram::query()->create(['organization_id' => $org->id, 'store_id' => $store->id, 'name' => 'Test', 'type' => 'affiliate', 'currency' => 'USD', 'status' => 'active']);
        $promoter = AffiliatePromoter::query()->create(['organization_id' => $org->id, 'email_encrypted' => 'test@example.com', 'email_hash' => str_repeat('a', 64), 'display_name' => 'Test', 'type' => 'affiliate', 'status' => 'active']);
        $member = AffiliateProgramMembership::query()->create(['organization_id' => $org->id, 'store_id' => $store->id, 'program_id' => $program->id, 'promoter_id' => $promoter->id, 'status' => 'approved']);

        return [$store, $member];
    }
}
