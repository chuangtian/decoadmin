<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSION = 'reports.refresh';

    public function up(): void
    {
        if (! Schema::hasTable('permissions')
            || ! Schema::hasTable('roles')
            || ! Schema::hasTable('role_permissions')
            || ! DB::table('permissions')->exists()) {
            return;
        }

        $now = now();
        $permission = DB::table('permissions')->where('slug', self::PERMISSION)->first();
        if ($permission) {
            DB::table('permissions')->where('id', $permission->id)->update([
                'name' => '报表 · 刷新',
                'group' => 'reports',
                'description' => '允许刷新当前店铺的经营分析缓存。',
                'deleted_at' => null,
                'updated_at' => $now,
            ]);
            $permissionId = (int) $permission->id;
        } else {
            $permissionId = (int) DB::table('permissions')->insertGetId([
                'name' => '报表 · 刷新',
                'slug' => self::PERMISSION,
                'group' => 'reports',
                'description' => '允许刷新当前店铺的经营分析缓存。',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('roles')
            ->where('is_system', true)
            ->whereIn('slug', ['super-admin', 'organization-admin', 'store-admin', 'developer'])
            ->pluck('id')
            ->each(fn (int $roleId) => DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $roleId,
                'permission_id' => $permissionId,
                'created_at' => $now,
                'updated_at' => $now,
            ]));
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('slug', self::PERMISSION)->value('id');
        if (! $permissionId) {
            return;
        }

        DB::table('role_permissions')->where('permission_id', $permissionId)->delete();
        DB::table('permissions')->where('id', $permissionId)->delete();
    }
};
