<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MANAGE_PERMISSION = 'reports.manage';

    public function up(): void
    {
        Schema::create('brand_social_post_states', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('source_section', 80);
            $table->string('source_table_key', 191);
            $table->string('source_record_id', 191);
            $table->boolean('is_hidden')->default(false);
            $table->foreignId('hidden_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('hidden_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['store_id', 'source_section', 'source_table_key', 'source_record_id'],
                'brand_social_post_state_source_unique',
            );
            $table->index(
                ['organization_id', 'store_id', 'is_hidden'],
                'brand_social_post_state_scope_index',
            );
        });

        Schema::create('brand_social_daily_reviews', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->date('review_date');
            $table->string('status', 20)->default('draft');
            $table->text('core_data')->nullable();
            $table->text('top_content')->nullable();
            $table->text('low_content')->nullable();
            $table->text('recommendations')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['store_id', 'review_date'], 'brand_social_daily_review_store_date_unique');
            $table->index(
                ['organization_id', 'store_id', 'status', 'review_date'],
                'brand_social_daily_review_scope_index',
            );
        });

        Schema::create('brand_social_weekly_reports', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->date('week_start');
            $table->date('week_end');
            $table->string('title', 160);
            $table->string('status', 20)->default('draft');
            $table->text('summary')->nullable();
            $table->json('metrics_snapshot')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['store_id', 'week_start'], 'brand_social_weekly_report_store_week_unique');
            $table->index(
                ['organization_id', 'store_id', 'status', 'week_start'],
                'brand_social_weekly_report_scope_index',
            );
        });

        if (! Schema::hasTable('permissions')
            || ! Schema::hasTable('roles')
            || ! Schema::hasTable('role_permissions')
            || ! DB::table('permissions')->exists()) {
            return;
        }

        $now = now();
        $permission = DB::table('permissions')->where('slug', self::MANAGE_PERMISSION)->first();
        $permissionValues = [
            'name' => '报表 · 管理',
            'group' => 'reports',
            'description' => '允许管理当前店铺的运营复盘、周报及报表数据状态。',
            'deleted_at' => null,
            'updated_at' => $now,
        ];
        $permissionId = $permission
            ? (int) $permission->id
            : (int) DB::table('permissions')->insertGetId([
                ...$permissionValues,
                'slug' => self::MANAGE_PERMISSION,
                'created_at' => $now,
            ]);
        if ($permission) {
            DB::table('permissions')->where('id', $permissionId)->update($permissionValues);
        }

        DB::table('roles')
            ->where('is_system', true)
            ->whereIn('slug', ['super-admin', 'organization-admin', 'store-admin', 'operator'])
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
        if (Schema::hasTable('permissions') && Schema::hasTable('role_permissions')) {
            $permissionId = DB::table('permissions')->where('slug', self::MANAGE_PERMISSION)->value('id');
            if ($permissionId) {
                DB::table('role_permissions')->where('permission_id', $permissionId)->delete();
                DB::table('permissions')->where('id', $permissionId)->delete();
            }
        }

        Schema::dropIfExists('brand_social_weekly_reports');
        Schema::dropIfExists('brand_social_daily_reviews');
        Schema::dropIfExists('brand_social_post_states');
    }
};
