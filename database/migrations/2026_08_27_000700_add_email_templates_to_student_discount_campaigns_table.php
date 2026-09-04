<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSION = 'student_discount.email_template.manage';

    public function up(): void
    {
        Schema::table('student_discount_campaigns', function (Blueprint $table): void {
            $table->json('email_templates')->nullable()->after('education_email_domains');
        });

        if (! DB::table('permissions')->exists()) {
            return;
        }

        $now = now();
        $permission = DB::table('permissions')->where('slug', self::PERMISSION)->first();
        if ($permission) {
            DB::table('permissions')->where('id', $permission->id)->update([
                'name' => '学生优惠 · 管理邮件内容',
                'group' => 'student_discount',
                'description' => '允许管理当前店铺的学生优惠批准与拒绝邮件内容。',
                'deleted_at' => null,
                'updated_at' => $now,
            ]);
            $permissionId = (int) $permission->id;
        } else {
            $permissionId = (int) DB::table('permissions')->insertGetId([
                'name' => '学生优惠 · 管理邮件内容',
                'slug' => self::PERMISSION,
                'group' => 'student_discount',
                'description' => '允许管理当前店铺的学生优惠批准与拒绝邮件内容。',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('roles')
            ->where('is_system', true)
            ->whereIn('slug', ['super-admin', 'organization-admin', 'store-admin'])
            ->pluck('id')
            ->each(function (int $roleId) use ($permissionId, $now): void {
                DB::table('role_permissions')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('slug', self::PERMISSION)->value('id');
        if ($permissionId) {
            DB::table('role_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }

        Schema::table('student_discount_campaigns', function (Blueprint $table): void {
            $table->dropColumn('email_templates');
        });
    }
};
