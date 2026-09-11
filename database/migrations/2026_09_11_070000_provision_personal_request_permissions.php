<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<string, array{name: string, group: string, description: string, roles: list<string>}> */
    private const PERMISSIONS = [
        'design_requests.view' => ['name' => '设计需求 · 查看', 'group' => 'design_requests', 'description' => '查看本人提交的设计需求。', 'roles' => ['super-admin', 'organization-admin', 'store-admin', 'designer', 'developer', 'operator', 'marketing', 'customer-service', 'viewer']],
        'design_requests.create' => ['name' => '设计需求 · 创建', 'group' => 'design_requests', 'description' => '提交个人设计需求。', 'roles' => ['super-admin', 'organization-admin', 'store-admin', 'designer', 'developer', 'operator', 'marketing', 'customer-service', 'viewer']],
        'design_requests.view_all' => ['name' => '设计需求 · 查看全部', 'group' => 'design_requests', 'description' => '查看当前组织全部人员提报的设计需求。', 'roles' => ['super-admin', 'designer', 'developer']],
        'design_requests.manage' => ['name' => '设计需求 · 管理', 'group' => 'design_requests', 'description' => '分配设计师并更新当前组织的设计需求。', 'roles' => ['super-admin', 'designer', 'developer']],
        'technical_requests.view' => ['name' => '技术需求 · 查看', 'group' => 'technical_requests', 'description' => '查看本人提交的技术需求。', 'roles' => ['super-admin', 'organization-admin', 'store-admin', 'designer', 'developer', 'operator', 'marketing', 'customer-service', 'viewer']],
        'technical_requests.create' => ['name' => '技术需求 · 创建', 'group' => 'technical_requests', 'description' => '提交个人技术需求。', 'roles' => ['super-admin', 'organization-admin', 'store-admin', 'designer', 'developer', 'operator', 'marketing', 'customer-service', 'viewer']],
        'technical_requests.view_all' => ['name' => '技术需求 · 查看全部', 'group' => 'technical_requests', 'description' => '查看当前组织全部人员提报的技术需求。', 'roles' => ['super-admin', 'developer']],
        'technical_requests.manage' => ['name' => '技术需求 · 管理', 'group' => 'technical_requests', 'description' => '接受并处理当前组织的技术需求。', 'roles' => ['super-admin', 'developer']],
        'request_approvals.view' => ['name' => '需求审批 · 查看', 'group' => 'request_approvals', 'description' => '查看当前组织待审批的需求和费用。', 'roles' => ['super-admin']],
        'request_approvals.manage' => ['name' => '需求审批 · 管理', 'group' => 'request_approvals', 'description' => '审批当前组织的需求和费用。', 'roles' => ['super-admin']],
        'expense_requests.view' => ['name' => '费用申请 · 查看', 'group' => 'expense_requests', 'description' => '查看本人提交的费用申请。', 'roles' => ['super-admin', 'organization-admin', 'store-admin', 'designer', 'developer', 'operator', 'marketing', 'customer-service', 'viewer']],
        'expense_requests.create' => ['name' => '费用申请 · 创建', 'group' => 'expense_requests', 'description' => '提交个人费用申请。', 'roles' => ['super-admin', 'organization-admin', 'store-admin', 'designer', 'developer', 'operator', 'marketing', 'customer-service', 'viewer']],
        'expense_requests.view_all' => ['name' => '费用申请 · 查看全部', 'group' => 'expense_requests', 'description' => '查看当前组织所有人员的费用申请。', 'roles' => ['super-admin']],
        'expense_requests.manage' => ['name' => '费用申请 · 管理', 'group' => 'expense_requests', 'description' => '管理当前组织所有人员的费用申请。', 'roles' => ['super-admin']],
        'expense_claims.view' => ['name' => '发票报销 · 查看', 'group' => 'expense_claims', 'description' => '查看本人提交的发票报销。', 'roles' => ['super-admin', 'organization-admin', 'store-admin', 'designer', 'developer', 'operator', 'marketing', 'customer-service', 'viewer']],
        'expense_claims.create' => ['name' => '发票报销 · 创建', 'group' => 'expense_claims', 'description' => '提交个人发票报销。', 'roles' => ['super-admin', 'organization-admin', 'store-admin', 'designer', 'developer', 'operator', 'marketing', 'customer-service', 'viewer']],
        'expense_claims.view_all' => ['name' => '发票报销 · 查看全部', 'group' => 'expense_claims', 'description' => '查看当前组织所有人员的发票报销。', 'roles' => ['super-admin', 'organization-admin']],
        'expense_claims.manage' => ['name' => '发票报销 · 管理', 'group' => 'expense_claims', 'description' => '管理当前组织所有人员的发票报销。', 'roles' => ['super-admin', 'organization-admin']],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles') || ! Schema::hasTable('role_permissions')) {
            return;
        }
        $now = now();
        $permissionIds = [];
        foreach (self::PERMISSIONS as $slug => $definition) {
            $permission = DB::table('permissions')->where('slug', $slug)->first();
            $values = ['name' => $definition['name'], 'group' => $definition['group'], 'description' => $definition['description'], 'deleted_at' => null, 'updated_at' => $now];
            if ($permission) {
                DB::table('permissions')->where('id', $permission->id)->update($values);
                $permissionIds[$slug] = (int) $permission->id;
            } else {
                $permissionIds[$slug] = (int) DB::table('permissions')->insertGetId([...$values, 'slug' => $slug, 'created_at' => $now]);
            }
        }
        $systemRoles = DB::table('roles')->where('is_system', true)->get(['id', 'slug']);
        foreach (self::PERMISSIONS as $slug => $definition) {
            $permissionId = $permissionIds[$slug];
            $allowedRoleIds = $systemRoles->whereIn('slug', $definition['roles'])->pluck('id');
            DB::table('role_permissions')->where('permission_id', $permissionId)->whereIn('role_id', $systemRoles->pluck('id'))->whereNotIn('role_id', $allowedRoleIds)->delete();
            foreach ($allowedRoleIds as $roleId) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
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
