<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSIONS = [
        'discounts.view' => [
            'name' => '折扣管理 · 查看',
            'description' => '允许查看当前店铺的 Shopify 折扣。',
            'roles' => ['super-admin', 'organization-admin', 'store-admin', 'operator', 'marketing', 'viewer'],
        ],
        'discounts.manage' => [
            'name' => '折扣管理 · 管理',
            'description' => '允许创建和修改当前店铺的 Shopify 折扣码。',
            'roles' => ['super-admin', 'organization-admin', 'store-admin', 'operator', 'marketing'],
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles') || ! Schema::hasTable('role_permissions')) {
            return;
        }
        $now = now();
        $ids = [];
        foreach (self::PERMISSIONS as $slug => $definition) {
            $permission = DB::table('permissions')->where('slug', $slug)->first();
            $values = [
                'name' => $definition['name'],
                'group' => 'discounts',
                'description' => $definition['description'],
                'deleted_at' => null,
                'updated_at' => $now,
            ];
            if ($permission) {
                DB::table('permissions')->where('id', $permission->id)->update($values);
                $ids[$slug] = (int) $permission->id;
            } else {
                $ids[$slug] = (int) DB::table('permissions')->insertGetId([...$values, 'slug' => $slug, 'created_at' => $now]);
            }
        }
        DB::table('roles')->where('is_system', true)->get(['id', 'slug'])->each(function (object $role) use ($ids, $now): void {
            foreach (self::PERMISSIONS as $slug => $definition) {
                if (in_array($role->slug, $definition['roles'], true)) {
                    DB::table('role_permissions')->insertOrIgnore([
                        'role_id' => $role->id,
                        'permission_id' => $ids[$slug],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('role_permissions')) {
            return;
        }
        $ids = DB::table('permissions')->whereIn('slug', array_keys(self::PERMISSIONS))->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
