<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paid_advertising_goal_fields', function (Blueprint $table): void {
            $table->id()->comment('本地字段元数据主键');
            $table->foreignId('organization_id')->comment('所属组织')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->comment('所属店铺')->constrained()->cascadeOnDelete();
            $table->foreignId('goal_board_id')->nullable()->comment('自定义目标页签；总目标为空')->constrained('paid_advertising_goal_boards')->cascadeOnDelete();
            $table->string('source_key', 80)->comment('稳定数据源标识：overall 或 board:{id}');
            $table->string('source_field_id', 120)->comment('飞书多维表格字段 ID');
            $table->string('name', 255)->comment('飞书字段名称（页面表头）');
            $table->unsignedSmallInteger('type')->comment('飞书字段类型编号');
            $table->unsignedInteger('field_order')->comment('字段在飞书返回列表中的顺序');
            $table->boolean('is_primary')->default(false)->comment('是否为飞书主字段');
            $table->string('description', 500)->nullable()->comment('飞书字段描述或备注');
            $table->longText('property_encrypted')->nullable()->comment('加密保存的飞书字段配置摘要');
            $table->timestamp('synced_at')->comment('最近一次字段元数据同步时间');
            $table->timestamps();

            $table->unique(
                ['store_id', 'source_key', 'source_field_id'],
                'paid_advertising_goal_fields_source_unique',
            );
            $table->index(
                ['organization_id', 'store_id', 'source_key', 'field_order'],
                'paid_advertising_goal_fields_scope_order_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paid_advertising_goal_fields');
    }
};
