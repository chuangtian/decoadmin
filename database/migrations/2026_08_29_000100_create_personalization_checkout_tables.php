<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personalization_checkout_settings', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('component_id')->nullable()
                ->constrained('personalization_recommendation_components')
                ->nullOnDelete();
            $table->foreignId('collection_id')->nullable()
                ->constrained('product_collections')
                ->nullOnDelete();
            $table->string('shopify_collection_id', 255)->nullable();
            $table->unsignedSmallInteger('maximum_recommendations')->default(3);
            $table->boolean('enabled')->default(false);
            $table->json('trust_items')->nullable();
            $table->json('settings')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'store_id'], 'personalization_checkout_org_store_unique');
            $table->index(['store_id', 'enabled'], 'personalization_checkout_store_enabled_index');
            $table->index(['store_id', 'shopify_collection_id'], 'personalization_checkout_store_collection_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personalization_checkout_settings');
    }
};
