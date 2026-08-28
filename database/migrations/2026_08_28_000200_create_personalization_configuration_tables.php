<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personalization_recommendation_strategies', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('algorithm', 40);
            $table->boolean('enabled')->default(false);
            $table->unsignedTinyInteger('item_limit')->default(8);
            $table->json('settings')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'store_id', 'enabled'], 'personalization_strategy_store_enabled_index');
            $table->index(['store_id', 'algorithm'], 'personalization_strategy_store_algorithm_index');
        });

        Schema::create('personalization_strategy_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('strategy_id')->constrained('personalization_recommendation_strategies')->cascadeOnDelete();
            $table->string('type', 32);
            $table->json('value');
            $table->boolean('enabled')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['strategy_id', 'enabled', 'position'], 'personalization_rule_strategy_position_index');
            $table->index(['store_id', 'type'], 'personalization_rule_store_type_index');
        });

        Schema::create('personalization_strategy_product_overrides', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('strategy_id')->constrained('personalization_recommendation_strategies')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('shopify_product_id', 255);
            $table->string('type', 16);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(
                ['strategy_id', 'shopify_product_id', 'type'],
                'personalization_strategy_product_override_unique',
            );
            $table->index(['strategy_id', 'type', 'position'], 'personalization_override_strategy_position_index');
            $table->index(['store_id', 'shopify_product_id'], 'personalization_override_store_product_index');
        });

        Schema::create('personalization_recommendation_components', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('strategy_id')->constrained('personalization_recommendation_strategies')->restrictOnDelete();
            $table->string('name', 80);
            $table->string('placement', 24);
            $table->string('status', 16)->default('draft');
            $table->string('heading', 120)->nullable();
            $table->string('button_label', 60)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->json('settings')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'store_id', 'placement', 'status'], 'personalization_component_store_status_index');
            $table->index(['strategy_id', 'position'], 'personalization_component_strategy_position_index');
        });

        Schema::create('personalization_component_styles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('component_id')->unique()->constrained('personalization_recommendation_components')->cascadeOnDelete();
            $table->string('layout', 16)->default('carousel');
            $table->unsignedTinyInteger('desktop_columns')->default(4);
            $table->unsignedTinyInteger('mobile_columns')->default(2);
            $table->boolean('show_image')->default(true);
            $table->boolean('show_vendor')->default(false);
            $table->boolean('show_price')->default(true);
            $table->boolean('show_compare_at_price')->default(true);
            $table->boolean('show_add_to_cart')->default(true);
            $table->json('tokens')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'layout'], 'personalization_style_store_layout_index');
        });

        Schema::create('personalization_smart_cart_settings', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('strategy_id')->nullable()->constrained('personalization_recommendation_strategies')->nullOnDelete();
            $table->boolean('enabled')->default(false);
            $table->string('compatibility_status', 16)->default('unchecked');
            $table->json('compatibility_details')->nullable();
            $table->timestamp('compatibility_checked_at')->nullable();
            $table->string('fallback_mode', 24)->default('shopify_default');
            $table->json('settings')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'store_id'], 'personalization_smart_cart_org_store_unique');
            $table->index(['store_id', 'enabled', 'compatibility_status'], 'personalization_smart_cart_state_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personalization_smart_cart_settings');
        Schema::dropIfExists('personalization_component_styles');
        Schema::dropIfExists('personalization_recommendation_components');
        Schema::dropIfExists('personalization_strategy_product_overrides');
        Schema::dropIfExists('personalization_strategy_rules');
        Schema::dropIfExists('personalization_recommendation_strategies');
    }
};
