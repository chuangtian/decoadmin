<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SidebarMenuAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_and_roles_are_grouped_under_user_management(): void
    {
        $menu = file_get_contents(resource_path('js/config/menu.ts'));

        $userManagement = substr(
            $menu,
            strpos($menu, "name: '用户管理'"),
            strpos($menu, "name: '公司财务'") - strpos($menu, "name: '用户管理'"),
        );
        $this->assertStringContainsString("name: '用户', route: '/users'", $userManagement);
        $this->assertStringContainsString("name: '角色', route: '/roles'", $userManagement);
        $this->assertStringContainsString("{ name: '财务', route: '/finance'", $menu);
        $this->assertStringContainsString("{ name: '报销', route: '/finance/reimbursements'", $menu);
        $this->assertStringContainsString("{ name: '续费', route: '/finance/renewals'", $menu);
        $this->assertStringContainsString("{ name: '续费历史记录', route: '/finance/renewal-history'", $menu);
        $this->assertLessThan(
            strpos($userManagement, "name: '角色'"),
            strpos($userManagement, "name: '用户'"),
        );
        $system = substr($menu, strpos($menu, "name: '系统管理'"));
        $this->assertStringNotContainsString("name: '角色权限'", $menu);
        $this->assertStringNotContainsString("route: '/roles'", $system);
    }

    public function test_request_and_expense_modules_are_in_a_personal_section_before_store_operations(): void
    {
        $menu = file_get_contents(resource_path('js/config/menu.ts'));

        $this->assertIsString($menu);
        $personalPosition = strpos($menu, "section: '个人'");
        $storePosition = strpos($menu, "section: '店铺运营'");
        $profilePosition = strpos($menu, "name: '我的资料'");
        $requestCenterStart = strpos($menu, "name: '需求和报销'");
        $workbenchStart = strpos($menu, "name: '工作台'");
        $workbench = substr($menu, $workbenchStart, strpos($menu, "name: '业务中心'") - $workbenchStart);
        $requestCenter = substr($menu, $requestCenterStart, $storePosition - $requestCenterStart);

        $this->assertNotFalse($personalPosition);
        $this->assertNotFalse($storePosition);
        $this->assertNotFalse($profilePosition);
        $this->assertLessThan($storePosition, $personalPosition);
        $this->assertLessThan($requestCenterStart, $profilePosition);
        $this->assertStringContainsString("name: '我的资料',", $menu);
        $this->assertStringContainsString("route: '/profile'", $menu);
        $this->assertStringContainsString("name: '设计需求', route: '/design-requests'", $requestCenter);
        $this->assertStringContainsString("name: '技术需求', route: '/technical-requests'", $requestCenter);
        $this->assertStringContainsString("name: '费用申请', route: '/expense-requests'", $requestCenter);
        $this->assertStringContainsString("name: '需求审批', route: '/request-approvals'", $requestCenter);
        $this->assertStringContainsString("name: '发票报销', route: '/expense-claims'", $requestCenter);
        $this->assertStringNotContainsString("name: '设计需求'", $workbench);
    }

    public function test_referral_navigation_requires_an_installed_app_and_a_granted_permission(): void
    {
        $menu = file_get_contents(resource_path('js/config/menu.ts'));
        $sidebar = file_get_contents(resource_path('js/Components/Layout/Sidebar.vue'));

        $this->assertIsString($menu);
        $this->assertIsString($sidebar);
        $this->assertStringContainsString("name: '推荐与联盟'", $menu);
        $this->assertStringContainsString("requiresInstalledApp: 'referral'", $menu);
        $this->assertStringContainsString('page.props.applicationAvailability[item.requiresInstalledApp]', $sidebar);
        $this->assertStringContainsString('page.props.auth.permissions.includes(child.permission)', $sidebar);
    }

    public function test_sidebar_only_receives_permissions_granted_to_the_current_user(): void
    {
        $this->seed(PermissionSeeder::class);
        $user = User::factory()->create();
        $organization = Organization::query()->create(['name' => 'Macfox', 'code' => 'macfox']);
        $organization->users()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $store = $organization->stores()->create([
            'name' => 'Macfox US',
            'shopify_domain' => 'macfox-us.myshopify.com',
            'status' => 'active',
        ]);
        $store->members()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $this->seed(RoleSeeder::class);
        $viewer = Role::query()->whereBelongsTo($organization)->where('slug', 'viewer')->firstOrFail();
        $user->roles()->attach($viewer, [
            'organization_id' => $organization->id,
            'store_id' => null,
        ]);

        $this->actingAs($user)
            ->withSession([
                'current_organization_id' => $organization->id,
                'current_store_id' => $store->id,
            ])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.permissions', fn (Collection $permissions) => $permissions->contains('store.view')
                    && $permissions->contains('organization.view')
                    && ! $permissions->contains('store.create')
                    && ! $permissions->contains('apps.view')
                    && ! $permissions->contains('apps.install')
                    && ! $permissions->contains('webhooks.view')));
    }
}
