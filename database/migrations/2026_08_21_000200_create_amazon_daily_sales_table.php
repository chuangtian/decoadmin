<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('amazon_daily_sales', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('source_record_id', 80);
            $table->date('sale_date');
            $table->string('weekday', 16)->nullable();

            $table->unsignedInteger('fba_order_count')->nullable();
            $table->decimal('fba_sales', 18, 4)->nullable();
            $table->unsignedInteger('fbm_order_count')->nullable();
            $table->decimal('fbm_sales', 18, 4)->nullable();
            $table->decimal('total_sales', 18, 4)->nullable();
            $table->decimal('accessory_refunds', 18, 4)->nullable();
            $table->decimal('refunds', 18, 4)->nullable();
            $table->decimal('total_refunds', 18, 4)->nullable();
            $table->decimal('net_sales', 18, 4)->nullable();
            $table->decimal('refund_rate', 12, 6)->nullable();
            $table->decimal('ad_spend', 18, 4)->nullable();
            $table->decimal('ad_spend_rate', 12, 6)->nullable();
            $table->decimal('roi', 14, 6)->nullable();

            foreach (['sp', 'sb', 'sd'] as $prefix) {
                $table->unsignedBigInteger("{$prefix}_impressions")->nullable();
                $table->unsignedBigInteger("{$prefix}_clicks")->nullable();
                $table->decimal("{$prefix}_click_through_rate", 12, 6)->nullable();
                $table->decimal("{$prefix}_spend", 18, 4)->nullable();
                $table->decimal("{$prefix}_ad_sales", 18, 4)->nullable();
                $table->unsignedInteger("{$prefix}_order_count")->nullable();
                $table->decimal("{$prefix}_acos", 12, 6)->nullable();
                $table->decimal("{$prefix}_conversion_rate", 12, 6)->nullable();
            }

            $table->json('remarks')->nullable();
            $table->timestamp('source_created_at')->nullable();
            $table->timestamp('source_updated_at')->nullable();
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->unique(['organization_id', 'store_id', 'sale_date'], 'amazon_daily_sales_scope_date_unique');
            $table->index(['store_id', 'source_record_id'], 'amazon_daily_sales_store_record_index');
            $table->index(['store_id', 'sale_date'], 'amazon_daily_sales_store_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('amazon_daily_sales');
    }
};
