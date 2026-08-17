<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RoleSeeder extends Seeder
{
    /** @var array<string, array{name: string, description: string, permissions: list<string>}> */
    private array $roles = [
        'super-admin' => [
            'name' => '超级管理员',
            'description' => '拥有平台全部管理权限。',
            'permissions' => ['*'],
        ],
        'organization-admin' => [
            'name' => '组织管理员',
            'description' => '管理组织内的用户、角色、店铺及组织资料。',
            'permissions' => ['organization.*', 'store.*', 'users.*', 'roles.*', 'apps.*', '*.view'],
        ],
        'store-admin' => [
            'name' => '店铺管理员',
            'description' => '管理指定店铺及其日常业务运营。',
            'permissions' => ['store.view', 'store.update', 'store.connect', 'store.disconnect', 'orders.*', 'products.*', 'customers.*', 'inventory.*', 'sync.*'],
        ],
        'developer' => [
            'name' => '开发人员',
            'description' => '管理集成、应用、Webhooks、数据同步和日志。',
            'permissions' => ['store.view', 'apps.*', 'shopify.*', 'webhooks.*', 'sync.*', 'audit.view', 'system.health.view'],
        ],
        'operator' => [
            'name' => '运营人员',
            'description' => '负责店铺的日常运营工作。',
            'permissions' => ['orders.view', 'orders.update', 'products.view', 'products.update', 'customers.view', 'inventory.view', 'sync.view'],
        ],
        'marketing' => [
            'name' => '营销人员',
            'description' => '查看商品、客户和审计信息。',
            'permissions' => ['products.view', 'customers.view', 'audit.view'],
        ],
        'customer-service' => [
            'name' => '客户服务',
            'description' => '查看订单和客户信息。',
            'permissions' => ['orders.view', 'customers.view'],
        ],
        'viewer' => [
            'name' => '只读成员',
            'description' => '仅可查看已分配的资源。',
            'permissions' => [
                'organization.view', 'store.view', 'shopify.view', 'orders.view', 'products.view',
                'customers.view', 'inventory.view', 'webhooks.view', 'sync.view', 'users.view',
                'roles.view', 'audit.view', 'system.settings.view', 'system.health.view',
            ],
        ],
    ];

    public function run(): void
    {
        $permissions = Permission::query()->get();

        Organization::query()->each(function (Organization $organization) use ($permissions): void {
            foreach ($this->roles as $slug => $definition) {
                $role = Role::withTrashed()->firstOrNew([
                    'organization_id' => $organization->getKey(),
                    'slug' => $slug,
                ]);
                $role->fill([
                    'name' => $definition['name'],
                    'description' => $definition['description'],
                    'is_system' => true,
                ]);
                $role->deleted_at = null;
                $role->save();

                $permissionIds = $permissions
                    ->filter(fn (Permission $permission) => $this->matches($permission->slug, $definition['permissions']))
                    ->modelKeys();
                $role->permissions()->sync($permissionIds);
            }

            User::query()->get()
                ->filter(fn (User $user) => (bool) data_get($user->metadata, 'is_super_admin')
                    && $user->organizations()->whereKey($organization->getKey())->exists())
                ->each(fn (User $user) => $this->assignSuperAdmin($organization, $user));
        });
    }

    /** @param list<string> $patterns */
    private function matches(string $permission, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($pattern === '*' || Str::is($pattern, $permission)) {
                return true;
            }
        }

        return false;
    }

    private function assignSuperAdmin(Organization $organization, User $user): void
    {
        $role = $organization->roles()->where('slug', 'super-admin')->firstOrFail();
        $assignment = DB::table('user_roles')
            ->where('organization_id', $organization->getKey())
            ->whereNull('store_id')
            ->where('user_id', $user->getKey())
            ->where('role_id', $role->getKey())
            ->first();
        $values = [
            'expires_at' => null,
            'deleted_at' => null,
            'updated_at' => now(),
        ];

        if ($assignment) {
            DB::table('user_roles')->where('id', $assignment->id)->update($values);
        } else {
            DB::table('user_roles')->insert([
                ...$values,
                'organization_id' => $organization->getKey(),
                'store_id' => null,
                'user_id' => $user->getKey(),
                'role_id' => $role->getKey(),
                'granted_by' => $user->getKey(),
                'created_at' => now(),
            ]);
        }
    }
}
