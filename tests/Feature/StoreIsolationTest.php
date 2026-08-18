<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_member_can_access_assigned_store_but_not_another_store(): void
    {
        $this->seed(PermissionSeeder::class);
        $organization = Organization::query()->create([
            'name' => 'Retail Group',
            'code' => 'retail-group',
        ]);
        $user = User::factory()->create();
        $organization->users()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $storeA = $this->store($organization, 'Store A', 'store-a.myshopify.com');
        $storeB = $this->store($organization, 'Store B', 'store-b.myshopify.com');
        $storeA->members()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $this->seed(RoleSeeder::class);
        $viewer = Role::query()->whereBelongsTo($organization)->where('slug', 'viewer')->firstOrFail();
        $user->roles()->attach($viewer, [
            'organization_id' => $organization->id,
            'store_id' => $storeA->id,
        ]);

        $this->actingAs($user)
            ->getJson(route('stores.access-check', $storeA))
            ->assertOk()
            ->assertJsonPath('data.id', $storeA->id);

        $this->actingAs($user)
            ->getJson(route('stores.access-check', $storeB))
            ->assertForbidden();
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
