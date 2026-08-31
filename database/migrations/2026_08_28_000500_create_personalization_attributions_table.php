<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personalization_attributions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('checkout_event_id')->nullable()->constrained('personalization_events')->nullOnDelete();
            $table->foreignId('click_event_id')->nullable()->constrained('personalization_events')->nullOnDelete();
            $table->foreignId('component_id')->nullable()->constrained('personalization_recommendation_components')->nullOnDelete();
            $table->foreignId('strategy_id')->nullable()->constrained('personalization_recommendation_strategies')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('shopify_product_id', 64)->nullable();
            $table->string('placement', 24)->nullable();
            $table->string('model', 40)->default('last_recommendation_click');
            $table->unsignedTinyInteger('window_days')->default(7);
            $table->string('status', 24)->default('attributed');
            $table->char('currency', 3);
            $table->decimal('gross_revenue', 20, 4)->default(0);
            $table->decimal('refund_amount', 20, 4)->default(0);
            $table->decimal('attributed_revenue', 20, 4)->default(0);
            $table->timestamp('clicked_at');
            $table->timestamp('ordered_at');
            $table->timestamp('reconciled_at');
            $table->timestamps();

            $table->index(['store_id', 'status', 'ordered_at'], 'personalization_attribution_store_status_time_index');
            $table->index(['component_id', 'ordered_at'], 'personalization_attribution_component_time_index');
            $table->index(['strategy_id', 'ordered_at'], 'personalization_attribution_strategy_time_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personalization_attributions');
    }
};
