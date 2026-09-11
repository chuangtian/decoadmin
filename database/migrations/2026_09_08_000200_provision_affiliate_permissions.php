<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const PERMISSIONS = [
        'affiliate.dashboard.view', 'affiliate.programs.view', 'affiliate.programs.manage',
        'affiliate.promoters.view', 'affiliate.promoters.manage', 'affiliate.conversions.view',
        'affiliate.conversions.override', 'affiliate.commissions.view', 'affiliate.commissions.adjust',
        'affiliate.commissions.approve', 'affiliate.payouts.view', 'affiliate.payouts.create',
        'affiliate.payouts.confirm', 'affiliate.fraud.view', 'affiliate.fraud.review',
        'affiliate.settings.manage', 'affiliate.reports.export',
    ];

    public function up(): void
    {
        $now = now();
        foreach (self::PERMISSIONS as $slug) {
            DB::table('permissions')->updateOrInsert(['slug' => $slug], [
                'name' => '推荐与联盟 · '.$this->label($slug),
                'group' => 'affiliate',
                'description' => '允许在授权店铺范围内执行'.$this->label($slug).'操作。',
                'deleted_at' => null,
                'updated_at' => $now,
                'created_at' => $now,
            ]);
        }

        $permissionIds = DB::table('permissions')->whereIn('slug', self::PERMISSIONS)->pluck('id', 'slug');
        DB::table('roles')->where('is_system', true)->get()->each(function (object $role) use ($permissionIds, $now): void {
            foreach ($permissionIds as $slug => $permissionId) {
                if (! $this->roleAllows((string) $role->slug, (string) $slug)) {
                    continue;
                }
                DB::table('role_permissions')->updateOrInsert([
                    'role_id' => $role->id,
                    'permission_id' => $permissionId,
                ], ['created_at' => $now, 'updated_at' => $now]);
            }
        });
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('slug', self::PERMISSIONS)->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }

    private function roleAllows(string $role, string $permission): bool
    {
        if (in_array($role, ['super-admin', 'organization-admin', 'store-admin'], true)) {
            return true;
        }
        if ($role === 'operator') {
            return Str::endsWith($permission, '.view')
                || in_array($permission, ['affiliate.promoters.manage', 'affiliate.fraud.review'], true);
        }

        return $role === 'viewer' && Str::endsWith($permission, '.view');
    }

    private function label(string $slug): string
    {
        return str_replace('.', ' ', Str::after($slug, 'affiliate.'));
    }
};
