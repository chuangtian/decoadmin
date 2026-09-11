<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('design_requests', function (Blueprint $table): void {
            $table->unsignedBigInteger('store_id')->nullable()->change();
            $table->index(['organization_id', 'requester_id', 'status'], 'design_requests_personal_requester_scope');
            $table->index(['organization_id', 'designer_id', 'status'], 'design_requests_personal_designer_scope');
            $table->index(['organization_id', 'planned_delivery_date'], 'design_requests_personal_due_date');
        });

        DB::table('permissions')->where('slug', 'design_requests.create')->update([
            'description' => '提交个人设计需求。',
        ]);
        DB::table('permissions')->where('slug', 'design_requests.view_all')->update([
            'description' => '查看当前组织全部人员提报的设计需求。',
        ]);
        DB::table('permissions')->where('slug', 'design_requests.manage')->update([
            'description' => '分配设计师并更新当前组织的设计需求。',
        ]);
        DB::table('roles')->where('slug', 'designer')->where('is_system', true)->update([
            'description' => '查看并处理当前组织的全部设计需求。',
        ]);
    }

    public function down(): void
    {
        DB::table('design_requests')
            ->whereNull('store_id')
            ->orderBy('id')
            ->chunkById(100, function ($requests): void {
                foreach ($requests as $request) {
                    $storeId = DB::table('stores')
                        ->where('organization_id', $request->organization_id)
                        ->orderBy('id')
                        ->value('id');
                    if (! $storeId) {
                        throw new RuntimeException('Cannot restore store-scoped design requests without an organization store.');
                    }
                    DB::table('design_requests')->where('id', $request->id)->update(['store_id' => $storeId]);
                }
            });

        Schema::table('design_requests', function (Blueprint $table): void {
            $table->dropIndex('design_requests_personal_requester_scope');
            $table->dropIndex('design_requests_personal_designer_scope');
            $table->dropIndex('design_requests_personal_due_date');
            $table->unsignedBigInteger('store_id')->nullable(false)->change();
        });

        DB::table('permissions')->where('slug', 'design_requests.create')->update([
            'description' => '在当前店铺提报设计需求。',
        ]);
        DB::table('permissions')->where('slug', 'design_requests.view_all')->update([
            'description' => '查看当前店铺全部人员提报的设计需求。',
        ]);
        DB::table('permissions')->where('slug', 'design_requests.manage')->update([
            'description' => '分配设计师并更新当前店铺的设计需求。',
        ]);
        DB::table('roles')->where('slug', 'designer')->where('is_system', true)->update([
            'description' => '查看并处理当前店铺的全部设计需求。',
        ]);
    }
};
