<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('source_record_id', 80)->comment('飞书记录 ID');

            $table->string('campaign_id', 120)->nullable()->comment('活动ID');
            $table->date('starts_on')->nullable()->comment('活动开始日期');
            $table->date('ends_on')->nullable()->comment('活动结束日期');
            $table->string('campaign_name')->nullable()->comment('活动名称');
            $table->text('main_title')->nullable()->comment('主标题');
            $table->text('subtitle')->nullable()->comment('副标题');
            $table->text('planning_document')->nullable()->comment('策划书');
            $table->text('core_offer')->nullable()->comment('核心优惠');
            $table->json('campaign_images')->nullable()->comment('活动图片');
            $table->json('email_content')->nullable()->comment('邮件内容');
            $table->decimal('sales_amount', 18, 4)->nullable()->comment('销售额');
            $table->decimal('ad_spend', 18, 4)->nullable()->comment('广告花费');
            $table->decimal('roi', 18, 6)->nullable()->comment('ROI');
            $table->unsignedBigInteger('order_count')->nullable()->comment('订单数');
            $table->unsignedBigInteger('store_visits')->nullable()->comment('店铺访问');
            $table->decimal('conversion_rate', 14, 10)->nullable()->comment('转化率');
            $table->decimal('daily_average_sales', 18, 4)->nullable()->comment('日均销售额');
            $table->decimal('daily_average_store_visits', 18, 4)->nullable()->comment('日均店铺访问');
            $table->decimal('daily_average_order_count', 18, 4)->nullable()->comment('日均订单数');
            $table->decimal('daily_average_ad_spend', 18, 4)->nullable()->comment('日均广告花费');
            $table->text('campaign_summary')->nullable()->comment('活动总结');
            $table->text('problem_diagnosis')->nullable()->comment('问题诊断');
            $table->text('optimization_analysis')->nullable()->comment('优化分析');
            $table->string('single_select')->nullable()->comment('单选');
            $table->json('parent_records')->nullable()->comment('父记录');

            $table->json('unmapped_fields')->nullable()->comment('未映射的飞书字段，键名保留原表头');
            $table->timestamp('source_created_at')->nullable()->comment('飞书记录创建时间');
            $table->timestamp('source_updated_at')->nullable()->comment('飞书记录更新时间');
            $table->timestamp('synced_at')->comment('最近手动同步时间');
            $table->timestamps();

            $table->unique(
                ['organization_id', 'store_id', 'source_record_id'],
                'campaign_activities_scope_record_unique',
            );
            $table->index(['store_id', 'campaign_id'], 'campaign_activities_store_campaign_index');
            $table->index(['store_id', 'starts_on'], 'campaign_activities_store_start_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_activities');
    }
};
