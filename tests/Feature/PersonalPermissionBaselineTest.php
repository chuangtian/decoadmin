<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Authorization\PersonalPermissionService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PersonalPermissionBaselineTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_organization_member_without_a_role_receives_personal_workspace_permissions(): void
    {
        $organization = Organization::query()->create(['name' => 'Personal Workspace', 'code' => 'personal-workspace']);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $organization->users()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $session = ['current_organization_id' => $organization->id];

        $this->actingAs($user)->withSession($session)
            ->get(route('design-requests.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.permissions', fn (Collection $permissions) => collect(PersonalPermissionService::BASELINE_PERMISSIONS)
                    ->every(fn (string $permission) => $permissions->contains($permission))));

        $this->get(route('technical-requests.index'))->assertOk();
        $this->get(route('expense-requests.index'))->assertOk();
        $this->get(route('expense-claims.index'))->assertOk();
        $this->get(route('profile.edit'))->assertOk();
        $this->get(route('request-approvals.index'))->assertForbidden();

        $permissions = app(PersonalPermissionService::class);
        $this->assertTrue($permissions->allows($user, $organization, 'expense_requests.create'));
        $this->assertFalse($permissions->allows($user, $organization, 'expense_requests.view_all'));
        $this->assertFalse($permissions->allows($user, $organization, 'request_approvals.manage'));
    }

    public function test_inactive_or_foreign_members_do_not_receive_personal_workspace_permissions(): void
    {
        $organization = Organization::query()->create(['name' => 'Personal Workspace', 'code' => 'personal-workspace']);
        $inactiveUser = User::factory()->create();
        $foreignUser = User::factory()->create();
        $organization->users()->attach($inactiveUser, ['status' => 'inactive']);
        $permissions = app(PersonalPermissionService::class);

        $this->assertSame([], $permissions->personalPermissions($inactiveUser, $organization));
        $this->assertSame([], $permissions->personalPermissions($foreignUser, $organization));
    }

    public function test_role_editor_hides_baseline_permissions_and_preserves_compatibility_assignments(): void
    {
        $organization = Organization::query()->create(['name' => 'Role Workspace', 'code' => 'role-workspace']);
        $admin = User::factory()->create(['metadata' => ['is_super_admin' => true], 'email_verified_at' => now()]);
        $organization->users()->attach($admin, ['status' => 'active', 'joined_at' => now()]);
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $session = ['current_organization_id' => $organization->id];
        $managePermission = Permission::query()->where('slug', 'design_requests.manage')->firstOrFail();
        $technicalManagePermission = Permission::query()->where('slug', 'technical_requests.manage')->firstOrFail();

        $this->actingAs($admin)->withSession($session)
            ->get(route('roles.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('permissions.data', function (Collection $permissions): bool {
                    $slugs = $permissions->pluck('slug');

                    return $slugs->contains('design_requests.manage')
                        && collect(PersonalPermissionService::BASELINE_PERMISSIONS)
                            ->every(fn (string $permission) => ! $slugs->contains($permission));
                }));

        $this->post(route('roles.store'), [
            'name' => '内容协调员',
            'slug' => 'content-coordinator',
            'description' => '测试个人基础权限与角色职责分离。',
            'permission_ids' => [$managePermission->id],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $role = Role::query()->whereBelongsTo($organization)->where('slug', 'content-coordinator')->firstOrFail();
        $assignedSlugs = $role->permissions()->pluck('slug');
        $this->assertTrue($assignedSlugs->contains('design_requests.manage'));
        $this->assertTrue(collect(PersonalPermissionService::BASELINE_PERMISSIONS)
            ->every(fn (string $permission) => $assignedSlugs->contains($permission)));

        $this->get(route('roles.show', $role))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('role.data.permissions', fn (Collection $permissions) => $permissions->pluck('slug')->all() === ['design_requests.manage'])
                ->where('permissions.data', fn (Collection $permissions) => ! $permissions->pluck('slug')->contains('design_requests.view')));

        $this->put(route('roles.permissions.update', $role), ['permission_ids' => [$technicalManagePermission->id]])
            ->assertRedirect()->assertSessionHasNoErrors();

        $role->refresh();
        $assignedSlugs = $role->permissions()->pluck('slug');
        $this->assertFalse($assignedSlugs->contains('design_requests.manage'));
        $this->assertTrue($assignedSlugs->contains('technical_requests.manage'));
        $this->assertTrue(collect(PersonalPermissionService::BASELINE_PERMISSIONS)
            ->every(fn (string $permission) => $assignedSlugs->contains($permission)));
    }
}
