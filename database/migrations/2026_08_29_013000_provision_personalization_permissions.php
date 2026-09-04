<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var array<string, array{name: string, description: string, roles: list<string>}>
     */
    private const PERMISSIONS = [
        'personalization.view' => [
            'name' => '个性化推荐 · 查看',
            'description' => '允许查看当前店铺的个性化推荐工作台与配置。',
            'roles' => ['super-admin', 'organization-admin', 'store-admin', 'operator', 'marketing'],
        ],
        'personalization.manage' => [
            'name' => '个性化推荐 · 管理',
            'description' => '允许管理当前店铺的个性化推荐策略与组件。',
            'roles' => ['super-admin', 'organization-admin', 'store-admin', 'operator', 'marketing'],
        ],
        'personalization.analytics.read' => [
            'name' => '个性化推荐 · 查看分析',
            'description' => '允许查看当前店铺的个性化推荐分析数据。',
            'roles' => ['super-admin', 'organization-admin', 'store-admin', 'operator', 'marketing'],
        ],
        'personalization.smart_cart.manage' => [
            'name' => '个性化推荐 · 管理 Smart Cart',
            'description' => '允许管理当前店铺的 Smart Cart 草稿、检查与发布。',
            'roles' => ['super-admin', 'organization-admin', 'store-admin'],
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('permissions')
            || ! Schema::hasTable('roles')
            || ! Schema::hasTable('role_permissions')
            || ! DB::table('permissions')->exists()) {
            return;
        }

        $now = now();
        $permissionIds = [];

        foreach (self::PERMISSIONS as $slug => $definition) {
            $permission = DB::table('permissions')->where('slug', $slug)->first();
            $values = [
                'name' => $definition['name'],
                'group' => 'personalization',
                'description' => $definition['description'],
                'deleted_at' => null,
                'updated_at' => $now,
            ];

            if ($permission) {
                DB::table('permissions')->where('id', $permission->id)->update($values);
                $permissionIds[$slug] = (int) $permission->id;
            } else {
                $permissionIds[$slug] = (int) DB::table('permissions')->insertGetId([
                    ...$values,
                    'slug' => $slug,
                    'created_at' => $now,
                ]);
            }
        }

        DB::table('roles')
            ->where('is_system', true)
            ->whereIn('slug', collect(self::PERMISSIONS)->flatMap(fn (array $permission): array => $permission['roles'])->unique())
            ->get(['id', 'slug'])
            ->each(function (object $role) use ($permissionIds, $now): void {
                foreach (self::PERMISSIONS as $slug => $definition) {
                    if (! in_array($role->slug, $definition['roles'], true)) {
                        continue;
                    }

                    DB::table('role_permissions')->insertOrIgnore([
                        'role_id' => $role->id,
                        'permission_id' => $permissionIds[$slug],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            });
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('role_permissions')) {
            return;
        }

        $permissionIds = DB::table('permissions')
            ->whereIn('slug', array_keys(self::PERMISSIONS))
            ->pluck('id');

        DB::table('role_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
    }
};
