<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_only_shares_stores_the_user_can_access(): void
    {
        $user = User::factory()->create();
        $organization = $this->organization('Macfox', 'macfox', $user);
        $authorized = $this->store($organization, 'Macfox US', 'macfox-us.myshopify.com');
        $unauthorized = $this->store($organization, 'Macfox EU', 'macfox-eu.myshopify.com');
        $authorized->members()->attach($user, ['status' => 'active', 'joined_at' => now()]);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Index')
                ->where('currentStore.id', $authorized->id)
                ->has('availableOrganizations', 1)
                ->has('availableOrganizations.0.stores', 1)
                ->where('availableOrganizations.0.stores.0.id', $authorized->id)
                ->missing('availableOrganizations.0.stores.1'));

        $this->assertNotEquals($unauthorized->id, session('current_store_id'));
    }

    public function test_store_switcher_rejects_an_unauthorized_store(): void
    {
        $user = User::factory()->create();
        $organization = $this->organization('Macfox', 'macfox', $user);
        $unauthorized = $this->store($organization, 'Macfox EU', 'macfox-eu.myshopify.com');

        $this->actingAs($user)
            ->put(route('context.store.update'), ['store_id' => $unauthorized->id])
            ->assertForbidden();

        $this->assertNull(session('current_store_id'));
    }

    public function test_store_switcher_persists_store_and_organization_context(): void
    {
        $user = User::factory()->create();
        $macfox = $this->organization('Macfox', 'macfox', $user);
        $asiwo = $this->organization('Asiwo', 'asiwo', $user);
        $macfoxStore = $this->store($macfox, 'Macfox US', 'macfox-us.myshopify.com');
        $asiwoStore = $this->store($asiwo, 'Asiwo US', 'asiwo-us.myshopify.com');
        $macfoxStore->members()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $asiwoStore->members()->attach($user, ['status' => 'active', 'joined_at' => now()]);

        $this->actingAs($user)
            ->from(route('dashboard'))
            ->put(route('context.store.update'), ['store_id' => $asiwoStore->id])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('current_organization_id', $asiwo->id)
            ->assertSessionHas('current_store_id', $asiwoStore->id);

        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('currentOrganization.id', $asiwo->id)
            ->where('currentStore.id', $asiwoStore->id));
    }

    public function test_organization_switcher_persists_organization_and_selects_an_authorized_store(): void
    {
        $user = User::factory()->create();
        $macfox = $this->organization('Macfox', 'macfox', $user);
        $asiwo = $this->organization('Asiwo', 'asiwo', $user);
        $macfoxStore = $this->store($macfox, 'Macfox US', 'macfox-us.myshopify.com');
        $asiwoStore = $this->store($asiwo, 'Asiwo US', 'asiwo-us.myshopify.com');
        $macfoxStore->members()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $asiwoStore->members()->attach($user, ['status' => 'active', 'joined_at' => now()]);

        $this->actingAs($user)
            ->withSession([
                'current_organization_id' => $macfox->id,
                'current_store_id' => $macfoxStore->id,
            ])
            ->from(route('dashboard'))
            ->put(route('context.organization.update'), ['organization_id' => $asiwo->id])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('current_organization_id', $asiwo->id)
            ->assertSessionHas('current_store_id', $asiwoStore->id);
    }

    public function test_organization_switcher_rejects_an_unauthorized_organization(): void
    {
        $user = User::factory()->create();
        $authorized = $this->organization('Macfox', 'macfox', $user);
        $unauthorized = Organization::query()->create(['name' => 'Other', 'code' => 'other']);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $authorized->id])
            ->put(route('context.organization.update'), ['organization_id' => $unauthorized->id])
            ->assertForbidden();

        $this->assertSame($authorized->id, session('current_organization_id'));
    }

    private function organization(string $name, string $code, User $user): Organization
    {
        $organization = Organization::query()->create(compact('name', 'code'));
        $organization->users()->attach($user, ['status' => 'active', 'joined_at' => now()]);

        return $organization;
    }

    private function store(Organization $organization, string $name, string $domain): Store
    {
        return $organization->stores()->create([
            'name' => $name,
            'shopify_domain' => $domain,
            'status' => 'active',
        ]);
    }
}
