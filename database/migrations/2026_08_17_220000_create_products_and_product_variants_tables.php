<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('shopify_product_id');
            $table->string('title');
            $table->string('handle');
            $table->string('status', 30);
            $table->string('vendor')->nullable();
            $table->string('product_type')->nullable();
            $table->longText('description')->nullable();
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->unique(['store_id', 'shopify_product_id'], 'products_store_shopify_id_unique');
            $table->index(['organization_id', 'store_id', 'status']);
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('shopify_variant_id');
            $table->string('title');
            $table->string('sku')->nullable();
            $table->decimal('price', 20, 4);
            $table->unsignedBigInteger('inventory_item_id')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'shopify_variant_id'], 'product_variants_product_shopify_id_unique');
            $table->index('inventory_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('products');
    }
};
