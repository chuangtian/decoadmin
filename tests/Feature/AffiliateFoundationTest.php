<?php

namespace Tests\Feature;

use App\Domain\ReferralAffiliate\Models\AffiliateClick;
use App\Domain\ReferralAffiliate\Models\AffiliateProgram;
use App\Domain\ReferralAffiliate\Models\AffiliateProgramMembership;
use App\Domain\ReferralAffiliate\Models\AffiliatePromoter;
use App\Domain\ReferralAffiliate\Services\AffiliateAppTokenService;
use App\Domain\ReferralAffiliate\Services\AffiliateManagementService;
use App\Domain\ReferralAffiliate\Services\AffiliateTrackingTokenService;
use App\Exceptions\AffiliateException;
use App\Models\App;
use App\Models\AppInstallation;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ShopifyConnection;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AffiliateFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['inertia.ssr.enabled' => false]);
    }

    public function test_store_admin_can_create_store_scoped_program_and_promoter_without_exposing_email_hash(): void
    {
        [$actor, $organization, $store] = $this->context('store-admin');
        $session = $this->contextSession($organization, $store);
        $this->actingAs($actor)->withSession($session)->post(route('affiliate.programs.store', [$organization, $store]), [
            'name' => 'Creator 12%', 'type' => 'influencer', 'attribution_model' => 'coupon_wins',
            'attribution_window_days' => 30, 'hold_days' => 30,
            'commission_type' => 'percentage', 'rate_basis_points' => 1200,
            'coupon_enabled' => true, 'customer_discount_type' => 'percentage',
            'customer_discount_rate_basis_points' => 1000,
        ])->assertRedirect();

        $program = AffiliateProgram::query()->sole();
        $this->assertSame($store->id, $program->store_id);
        $this->assertSame(1200, $program->rules()->sole()->rate_basis_points);
        $this->actingAs($actor)->withSession($session)->post(route('affiliate.promoters.store', [$organization, $store]), [
            'display_name' => 'Alice Creator', 'email' => 'Alice@Example.com',
            'type' => 'influencer', 'program_public_id' => $program->public_id,
        ])->assertRedirect();

        $promoter = AffiliatePromoter::query()->sole();
        $this->assertSame('alice@example.com', $promoter->email_encrypted);
        $this->assertNotSame('alice@example.com', $promoter->getRawOriginal('email_encrypted'));
        $membership = AffiliateProgramMembership::query()->sole();
        $this->assertSame($store->id, $membership->store_id);
        $this->actingAs($actor)->withSession($session)
            ->post(route('affiliate.programs.transition', [$organization, $store, $program->public_id]), ['action' => 'activate'])
            ->assertRedirect();
        $this->assertSame('active', $program->fresh()->status->value);
        $this->actingAs($actor)->withSession($session)
            ->post(route('affiliate.memberships.transition', [$organization, $store, $membership->public_id]), ['action' => 'approve'])
            ->assertRedirect();
        $this->assertSame('approved', $membership->fresh()->status->value);
        $this->assertNotNull($membership->fresh()->approved_at);
        $this->assertDatabaseCount('affiliate_links', 1);
        $this->assertDatabaseHas('affiliate_coupons', ['membership_id' => $membership->id, 'status' => 'provisioning']);
        $link = $membership->fresh()->link;
        $this->actingAs($actor)->withSession($session)->put(route('affiliate.settings.update', [$organization, $store]), [
            'affiliate_enabled' => true, 'customer_referral_enabled' => false,
        ])->assertRedirect();
        $redirect = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.8', 'HTTP_USER_AGENT' => 'Affiliate Test'])
            ->get(route('affiliate.tracking.redirect', $link->public_id))
            ->assertRedirect();
        $this->assertStringStartsWith('https://macfox-test-app.myshopify.com/?ref=', (string) $redirect->headers->get('Location'));
        $click = AffiliateClick::query()->sole();
        $this->assertNotSame('203.0.113.8', $click->ip_hash);
        parse_str(parse_url((string) $redirect->headers->get('Location'), PHP_URL_QUERY), $query);
        $tokens = app(AffiliateTrackingTokenService::class);
        $this->assertSame($click->id, $tokens->verify($store, $query['deco_aff'])?->id);
        $this->assertNull($tokens->verify($store, $query['deco_aff'].'tampered'));
        $payload = json_decode(base64_decode(strtr(explode('.', $query['deco_aff'])[0], '-_', '+/')), true);
        $this->assertArrayNotHasKey('store', $payload);
        $this->assertSame($click->occurred_at->copy()->addDays(30)->timestamp, $payload['exp']);
        $this->travel(31)->days();
        $this->assertNull($tokens->verify($store, $query['deco_aff']));
        $this->travelBack();

        $this->assertStringNotContainsString('203.0.113.8', json_encode($click->toArray(), JSON_THROW_ON_ERROR));
        $this->actingAs($actor)->withSession($session)
            ->post(route('affiliate.memberships.transition', [$organization, $store, $membership->public_id]), ['action' => 'suspend'])
            ->assertRedirect();
        $this->assertSame('suspended', $membership->fresh()->status->value);
        $this->assertSame('disabled', $link->fresh()->status);
        $this->assertSame('disable_pending', $membership->fresh()->coupon->status);
        $this->get(route('affiliate.tracking.redirect', $link->public_id))->assertNotFound();
        $this->assertDatabaseHas('audit_logs', ['store_id' => $store->id, 'action' => 'affiliate_program_status_changed']);
        $this->assertDatabaseHas('audit_logs', ['store_id' => $store->id, 'action' => 'affiliate_membership_status_changed']);
        $this->actingAs($actor)->withSession($session)->get(route('affiliate.promoters.index', [$organization, $store]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Affiliate/Index')
            ->where('memberships.0.promoter.email', 'alice@example.com')
            ->missing('memberships.0.promoter.email_hash'));
    }

    public function test_program_from_another_store_cannot_be_used_for_membership(): void
    {
        [$actor, $organization, $store] = $this->context('store-admin');
        $otherStore = $this->store($organization, $actor, 'Other', 'other-affiliate.myshopify.com');
        $program = AffiliateProgram::query()->create([
            'organization_id' => $organization->id, 'store_id' => $otherStore->id, 'name' => 'Other Program',
            'type' => 'affiliate', 'status' => 'draft', 'attribution_model' => 'coupon_wins',
            'attribution_window_days' => 30, 'hold_days' => 30, 'currency' => 'USD',
        ]);

        $this->actingAs($actor)->withSession($this->contextSession($organization, $store))
            ->post(route('affiliate.promoters.store', [$organization, $store]), [
                'display_name' => 'Cross Store', 'email' => 'cross@example.com',
                'type' => 'affiliate', 'program_public_id' => $program->public_id,
            ])->assertNotFound();
        $this->assertDatabaseCount('affiliate_program_memberships', 0);
        $this->assertDatabaseCount('affiliate_promoters', 0);
    }

    public function test_viewer_can_view_but_cannot_manage_and_settings_are_store_scoped(): void
    {
        [$viewer, $organization, $store] = $this->context('viewer');
        $session = $this->contextSession($organization, $store);
        $this->actingAs($viewer)->withSession($session)->get(route('affiliate.index', [$organization, $store]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Affiliate/Index')->where('settings.affiliate_enabled', false)
            ->where('permissions.manageSettings', false));
        $this->assertDatabaseCount('affiliate_store_settings', 0);
        $this->actingAs($viewer)->withSession($session)->put(route('affiliate.settings.update', [$organization, $store]), [
            'affiliate_enabled' => true, 'customer_referral_enabled' => false,
        ])->assertForbidden();
    }

    public function test_permission_and_role_seeders_are_idempotent(): void
    {
        [, $organization] = $this->context('store-admin');
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->assertSame(17, Permission::query()->where('group', 'affiliate')->count());
        $this->assertSame(17, Role::query()->whereBelongsTo($organization)->where('slug', 'store-admin')->sole()->permissions()->where('group', 'affiliate')->count());
        $this->assertSame(7, Role::query()->whereBelongsTo($organization)->where('slug', 'viewer')->sole()->permissions()->where('group', 'affiliate')->count());
    }

    public function test_other_store_is_blocked_even_for_a_super_admin_and_direct_service_calls(): void
    {
        [$actor, $organization, $store] = $this->context('super-admin');
        $other = $this->store($organization, $actor, 'Unauthorized fixture', 'unauthorized-fixture.myshopify.com');
        $this->actingAs($actor)->withSession($this->contextSession($organization, $other))
            ->get(route('affiliate.index', [$organization, $other]))->assertForbidden();
        $this->actingAs($actor)->put(route('affiliate.settings.update', [$organization, $other]), [
            'affiliate_enabled' => true, 'customer_referral_enabled' => false,
        ])->assertForbidden();
        try {
            app(AffiliateManagementService::class)
                ->updateSettings($organization, $other, $actor, ['affiliate_enabled' => true, 'customer_referral_enabled' => false]);
            $this->fail('A direct service call bypassed the pilot boundary');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('affiliate_store_settings', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_production_runtime_is_blocked_even_for_the_authorized_test_shop(): void
    {
        [$actor, $organization, $store] = $this->context('super-admin');
        $previous = app()->environment();
        app()->instance('env', 'production');
        try {
            app(AffiliateManagementService::class)
                ->updateSettings($organization, $store, $actor, ['affiliate_enabled' => true, 'customer_referral_enabled' => false]);
            $this->fail('Production was allowed');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        } finally {
            app()->instance('env', $previous);
        }
        $this->assertDatabaseCount('affiliate_store_settings', 0);
    }

    public function test_referral_refresh_uses_only_matching_app_installation_credentials(): void
    {
        [, $organization, $store] = $this->context('store-admin');
        config(['referral.environment' => 'test', 'referral.active.client_id' => 'referral-test-client',
            'referral.active.client_secret' => 'referral-test-secret', 'referral.active.handle' => 'deco-referral-test']);
        $connection = ShopifyConnection::query()->create([
            'store_id' => $store->id, 'shop_domain' => $store->shopify_domain,
            'status' => 'connected', 'access_token_encrypted' => 'commerce-fixture-token', 'scopes' => ['read_orders'], 'api_version' => '2026-07',
        ]);
        $registeredApp = App::query()->create([
            'name' => 'Referral fixture', 'handle' => 'deco-referral-test', 'client_id' => 'referral-test-client',
            'distribution' => 'custom', 'status' => 'active',
            'settings' => ['environment' => 'test', 'managed_by' => 'referral_config'],
        ]);
        $installation = AppInstallation::query()->create([
            'app_id' => $registeredApp->id, 'store_id' => $store->id, 'shopify_connection_id' => $connection->id,
            'status' => 'active', 'token_type' => 'offline', 'settings' => ['environment' => 'test'],
            'access_token_encrypted' => 'old-referral-token', 'access_token_expires_at' => now()->subMinute(),
            'refresh_token_encrypted' => 'referral-refresh', 'refresh_token_expires_at' => now()->addMonth(),
            'granted_scopes' => ['read_orders', 'read_products', 'write_discounts'],
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://macfox-test-app.myshopify.com/admin/oauth/access_token' => Http::response([
                'access_token' => 'new-referral-token', 'refresh_token' => 'new-referral-refresh',
                'expires_in' => 86400, 'refresh_token_expires_in' => 7776000,
                'scope' => 'read_orders,read_products,write_discounts',
            ]),
        ]);
        $service = app(AffiliateAppTokenService::class);
        $this->assertSame('new-referral-token', $service->accessTokenFor($store));
        Http::assertSent(fn ($request) => $request['client_id'] === 'referral-test-client'
            && $request['refresh_token'] === 'referral-refresh');
        $this->assertSame('commerce-fixture-token', $connection->fresh()->access_token_encrypted);
        $this->assertSame('new-referral-refresh', $installation->fresh()->refresh_token_encrypted);
        $installation->update(['settings' => ['environment' => 'production']]);
        $this->expectException(AffiliateException::class);
        $service->accessTokenFor($store);
    }

    /** @return array{User, Organization, Store} */
    private function context(string $role): array
    {
        $this->seed(PermissionSeeder::class);
        $organization = Organization::query()->create(['name' => 'Affiliate Organization', 'code' => 'affiliate-org']);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $organization->users()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $store = $this->store($organization, $user, 'Affiliate Store', 'macfox-test-app.myshopify.com');
        $this->seed(RoleSeeder::class);
        $systemRole = Role::query()->whereBelongsTo($organization)->where('slug', $role)->firstOrFail();
        $user->roles()->attach($systemRole, ['organization_id' => $organization->id, 'store_id' => null]);

        return [$user, $organization, $store];
    }

    private function store(Organization $organization, User $user, string $name, string $domain): Store
    {
        $store = $organization->stores()->create(['name' => $name, 'shopify_domain' => $domain, 'status' => 'active', 'currency' => 'USD']);
        $store->members()->attach($user, ['status' => 'active', 'joined_at' => now()]);

        return $store;
    }

    /** @return array<string, int> */
    private function contextSession(Organization $organization, Store $store): array
    {
        return ['current_organization_id' => $organization->id, 'current_store_id' => $store->id];
    }
}
