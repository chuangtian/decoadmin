<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('shopify_location_id');
            $table->string('name');
            $table->json('address')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->unique(['store_id', 'shopify_location_id'], 'locations_store_shopify_id_unique');
            $table->index(['organization_id', 'store_id', 'active']);
        });

        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('shopify_inventory_item_id');
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->unsignedBigInteger('shopify_variant_id')->nullable();
            $table->string('sku')->nullable();
            $table->boolean('tracked')->default(false);
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->unique(['store_id', 'shopify_inventory_item_id'], 'inventory_items_store_shopify_id_unique');
            $table->index(['organization_id', 'store_id', 'tracked']);
            $table->index('shopify_variant_id');
        });

        Schema::create('inventory_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('shopify_location_id');
            $table->bigInteger('available');
            $table->timestamp('updated_at_shopify')->nullable();
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->unique(['inventory_item_id', 'location_id'], 'inventory_levels_item_location_unique');
            $table->index('shopify_location_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_levels');
        Schema::dropIfExists('inventory_items');
        Schema::dropIfExists('locations');
    }
};
