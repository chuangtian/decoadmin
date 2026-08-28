<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personalization_event_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->unique()->constrained()->cascadeOnDelete();
            $table->uuid('ingest_key')->unique();
            $table->string('web_pixel_id', 255)->nullable();
            $table->string('status', 16)->default('inactive');
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('last_event_at')->nullable();
            $table->timestamps();

            $table->index(
                ['organization_id', 'store_id', 'status'],
                'personalization_event_source_scope_index',
            );
        });

        Schema::create('personalization_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_source_id')->constrained('personalization_event_sources')->cascadeOnDelete();
            $table->string('event_id', 191);
            $table->string('event_name', 64);
            $table->char('client_id_hash', 64);
            $table->char('session_id_hash', 64);
            $table->char('payload_hash', 64);
            $table->foreignId('component_id')->nullable()->constrained('personalization_recommendation_components')->nullOnDelete();
            $table->foreignId('strategy_id')->nullable()->constrained('personalization_recommendation_strategies')->nullOnDelete();
            $table->string('placement', 24)->nullable();
            $table->string('shopify_order_id', 64)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('received_at');
            $table->timestamps();

            $table->unique(['store_id', 'event_id'], 'personalization_event_store_event_unique');
            $table->index(['store_id', 'event_name', 'occurred_at'], 'personalization_event_store_type_time_index');
            $table->index(['store_id', 'client_id_hash', 'occurred_at'], 'personalization_event_client_time_index');
            $table->index(['component_id', 'event_name', 'occurred_at'], 'personalization_event_component_time_index');
            $table->index(['store_id', 'shopify_order_id'], 'personalization_event_order_index');
        });

        Schema::create('personalization_event_products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained('personalization_events')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('shopify_product_id', 64);
            $table->string('shopify_variant_id', 64)->nullable();
            $table->unsignedSmallInteger('rank')->nullable();
            $table->timestamps();

            $table->unique(['event_id', 'shopify_product_id'], 'personalization_event_product_unique');
            $table->index(['product_id', 'event_id'], 'personalization_event_product_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personalization_event_products');
        Schema::dropIfExists('personalization_events');
        Schema::dropIfExists('personalization_event_sources');
    }
};
