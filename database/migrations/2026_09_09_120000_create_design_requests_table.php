<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSIONS = [
        'design_requests.view' => ['设计需求 · 查看', '查看设计需求板块及本人提报的需求。'],
        'design_requests.create' => ['设计需求 · 提报', '在当前店铺提报设计需求。'],
        'design_requests.view_all' => ['设计需求 · 查看全部', '查看当前店铺全部人员提报的设计需求。'],
        'design_requests.manage' => ['设计需求 · 管理', '分配设计师并更新当前店铺的设计需求。'],
    ];

    public function up(): void
    {
        Schema::create('design_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('reference_no', 40)->unique();
            $table->string('request_type', 40);
            $table->string('priority', 20)->default('medium');
            $table->text('description');
            $table->string('requester_department', 120)->nullable();
            $table->foreignId('requester_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('designer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->date('requested_on');
            $table->date('planned_delivery_date');
            $table->date('actual_delivery_date')->nullable();
            $table->string('status', 30)->default('pending');
            $table->unsignedInteger('revision_count')->default(0);
            $table->text('delivery_note')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'store_id', 'requester_id', 'status'], 'design_requests_requester_scope');
            $table->index(['organization_id', 'store_id', 'designer_id', 'status'], 'design_requests_designer_scope');
            $table->index(['store_id', 'planned_delivery_date'], 'design_requests_due_date');
        });

        $this->provisionPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('design_requests');
    }

    private function provisionPermissions(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles') || ! Schema::hasTable('role_permissions')) {
            return;
        }

        $now = now();
        $permissionIds = [];
        foreach (self::PERMISSIONS as $slug => [$name, $description]) {
            $permission = DB::table('permissions')->where('slug', $slug)->first();
            $values = ['name' => $name, 'group' => 'design_requests', 'description' => $description, 'deleted_at' => null, 'updated_at' => $now];
            $permissionIds[$slug] = $permission
                ? (int) $permission->id
                : (int) DB::table('permissions')->insertGetId([...$values, 'slug' => $slug, 'created_at' => $now]);
            if ($permission) {
                DB::table('permissions')->where('id', $permission->id)->update($values);
            }
        }

        DB::table('organizations')->whereNull('deleted_at')->pluck('id')->each(function (int $organizationId) use ($permissionIds, $now): void {
            $designer = DB::table('roles')->where('organization_id', $organizationId)->where('slug', 'designer')->first();
            $designerId = $designer
                ? (int) $designer->id
                : (int) DB::table('roles')->insertGetId([
                    'organization_id' => $organizationId, 'name' => '设计师', 'slug' => 'designer',
                    'description' => '查看并处理当前店铺的全部设计需求。', 'is_system' => true,
                    'created_at' => $now, 'updated_at' => $now,
                ]);

            $roles = DB::table('roles')->where('organization_id', $organizationId)->where('is_system', true)->whereNull('deleted_at')->get(['id', 'slug']);
            foreach ($roles as $role) {
                $slugs = in_array($role->slug, ['super-admin', 'organization-admin', 'store-admin', 'designer'], true)
                    ? array_keys($permissionIds)
                    : ['design_requests.view', 'design_requests.create'];
                foreach ($slugs as $slug) {
                    DB::table('role_permissions')->insertOrIgnore([
                        'role_id' => $role->id, 'permission_id' => $permissionIds[$slug],
                        'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
            }
            foreach (array_keys($permissionIds) as $slug) {
                DB::table('role_permissions')->insertOrIgnore([
                    'role_id' => $designerId, 'permission_id' => $permissionIds[$slug],
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        });
    }
};
