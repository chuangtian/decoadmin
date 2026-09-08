<?php

namespace Tests\Feature;

use App\Domain\ReferralAffiliate\Models\AffiliatePortalToken;
use App\Domain\ReferralAffiliate\Models\AffiliateProgram;
use App\Domain\ReferralAffiliate\Models\AffiliateProgramMembership;
use App\Domain\ReferralAffiliate\Models\AffiliatePromoter;
use App\Domain\ReferralAffiliate\Models\AffiliateStoreSetting;
use App\Domain\ReferralAffiliate\Services\AffiliatePortalService;
use App\Http\Middleware\ConfigureAffiliatePortalSession;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_payment_method_is_restored_and_old_verification_cannot_change_profile(): void
    {
        $this->withoutMiddleware(ConfigureAffiliatePortalSession::class);
        [$store, $member] = $this->context();
        $this->withSession(['affiliate_member' => $member->id, 'affiliate_verified_at' => now()->timestamp])
            ->from('/referral-portal')->post('/referral-portal/profile', ['country' => 'Test', 'payment_method' => 'bank', 'payment_reference' => 'TEST-NO-TRANSFER'])->assertRedirect('/referral-portal#profile');
        $this->assertSame('bank', data_get($member->promoter->fresh()->profile_encrypted, 'payment_method'));
        $html = $this->get('/referral-portal')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<option value="bank"[^>]*selected/', $html);
        $this->travel(16)->minutes();
        $this->post('/referral-portal/profile', ['payment_method' => 'paypal', 'payment_reference' => 'SHOULD-NOT-SAVE'])->assertForbidden();
        $this->assertSame('bank', data_get($member->promoter->fresh()->profile_encrypted, 'payment_method'));
    }

    public function test_invalid_invitation_submission_keeps_token_for_correction_without_accepting(): void
    {
        $this->withoutMiddleware(ConfigureAffiliatePortalSession::class);
        [$store, $member] = $this->context();
        $member->update(['status' => 'pending']);
        AffiliateStoreSetting::query()->create(['organization_id' => $store->organization_id, 'store_id' => $store->id, 'affiliate_enabled' => true]);
        $token = bin2hex(random_bytes(32));
        DB::table('affiliate_invitations')->insert(['organization_id' => $store->organization_id, 'store_id' => $store->id, 'membership_id' => $member->id, 'created_by' => User::factory()->create()->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(7), 'created_at' => now(), 'updated_at' => now()]);
        $this->from('/referral-portal/invitation')->post('/referral-portal/invitation', ['token' => $token, 'terms' => '1', 'website' => 'ftp://invalid.example.com', 'notes' => 'Retain my notes'])->assertSessionHasErrors('website');
        $this->get('/referral-portal/invitation')->assertOk()->assertSee('value="'.$token.'"', false)->assertSee('Retain my notes');
        $this->assertNull(DB::table('affiliate_invitations')->value('accepted_at'));
        $this->post('/referral-portal/invitation', ['token' => $token, 'terms' => '1', 'website' => 'https://example.com'])->assertRedirect('/referral-portal');
        $this->assertNotNull(DB::table('affiliate_invitations')->value('accepted_at'));
    }

    public function test_profile_without_referrer_returns_to_profile_even_after_script_request(): void
    {
        $this->withoutMiddleware(ConfigureAffiliatePortalSession::class);
        [$store, $member] = $this->context();
        $this->withSession(['affiliate_member' => $member->id, 'affiliate_verified_at' => now()->timestamp, '_previous' => ['url' => url('/referral-portal/portal.js')]])
            ->post('/referral-portal/profile', ['country' => 'TEST', 'payment_method' => 'bank', 'payment_reference' => 'TEST ONLY'])
            ->assertRedirect('/referral-portal#profile');
        $this->withSession(['_previous' => ['url' => url('/referral-portal/portal.js')]])
            ->post('/referral-portal/profile', ['payment_method' => 'bad', 'payment_reference' => 'TEST'])
            ->assertRedirect('/referral-portal#profile')->assertSessionHasErrors('payment_method');
    }

    public function test_used_invitation_has_actionable_html_error_without_changing_json_status(): void
    {
        $this->context();
        $values = ['token' => str_repeat('a', 64), 'terms' => '1'];
        $this->post('/referral-portal/invitation', $values)->assertStatus(410)
            ->assertSee('邀请已使用或失效')->assertSee('返回推广者门户')->assertDontSee('Something is broken');
        $this->postJson('/referral-portal/invitation', $values)->assertStatus(410);
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
