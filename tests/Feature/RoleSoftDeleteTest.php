<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleSoftDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_soft_delete_a_custom_role_but_not_a_system_role(): void
    {
        $organization = Organization::query()->create(['name' => 'Macfox', 'code' => 'macfox-role-delete']);
        $user = User::factory()->create(['metadata' => ['is_super_admin' => true]]);
        $organization->users()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $custom = $organization->roles()->create([
            'name' => '临时角色',
            'slug' => 'temporary-role',
            'is_system' => false,
        ]);
        $system = $organization->roles()->create([
            'name' => '系统角色',
            'slug' => 'system-role',
            'is_system' => true,
        ]);
        $session = ['current_organization_id' => $organization->id];

        $this->actingAs($user)->withSession($session)
            ->delete(route('roles.destroy', $custom))
            ->assertRedirect(route('roles.index'));

        $this->assertSoftDeleted('roles', ['id' => $custom->id]);

        $this->actingAs($user)->withSession($session)
            ->delete(route('roles.destroy', $system))
            ->assertForbidden();

        $this->assertDatabaseHas('roles', ['id' => $system->id, 'deleted_at' => null]);
    }
}
