<?php

namespace Tests\Feature;

use App\Domain\ReferralAffiliate\Models\AffiliateProgram;
use App\Domain\ReferralAffiliate\Models\AffiliateProgramMembership;
use App\Domain\ReferralAffiliate\Models\AffiliatePromoter;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
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
        $this->actingAs($actor)->withSession($session)
            ->post(route('affiliate.memberships.transition', [$organization, $store, $membership->public_id]), ['action' => 'suspend'])
            ->assertRedirect();
        $this->assertSame('suspended', $membership->fresh()->status->value);
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

    /** @return array{User, Organization, Store} */
    private function context(string $role): array
    {
        $this->seed(PermissionSeeder::class);
        $organization = Organization::query()->create(['name' => 'Affiliate Organization', 'code' => 'affiliate-org']);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $organization->users()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $store = $this->store($organization, $user, 'Affiliate Store', 'affiliate-store.myshopify.com');
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
