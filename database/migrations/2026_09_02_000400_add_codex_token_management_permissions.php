<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<string, array{name: string, description: string}> */
    private const PERMISSIONS = [
        'codex.tokens.view' => [
            'name' => 'Codex 插件 · 查看授权',
            'description' => '允许查看当前组织的 Codex 插件授权状态。',
        ],
        'codex.tokens.manage' => [
            'name' => 'Codex 插件 · 管理授权',
            'description' => '允许为当前组织成员签发和撤销 Codex 插件令牌。',
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
        foreach (self::PERMISSIONS as $slug => $definition) {
            $permission = DB::table('permissions')->where('slug', $slug)->first();
            if ($permission) {
                DB::table('permissions')->where('id', $permission->id)->update([
                    ...$definition,
                    'group' => 'codex',
                    'deleted_at' => null,
                    'updated_at' => $now,
                ]);
                $permissionId = (int) $permission->id;
            } else {
                $permissionId = (int) DB::table('permissions')->insertGetId([
                    ...$definition,
                    'slug' => $slug,
                    'group' => 'codex',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            DB::table('roles')
                ->where('is_system', true)
                ->whereIn('slug', ['super-admin', 'organization-admin'])
                ->pluck('id')
                ->each(fn (int $roleId) => DB::table('role_permissions')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));
        }
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')->whereIn('slug', array_keys(self::PERMISSIONS))->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
    }
};
