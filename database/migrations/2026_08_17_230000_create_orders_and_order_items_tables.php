<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('shopify_order_id');
            $table->string('order_number');
            $table->string('email')->nullable();
            $table->string('financial_status', 50)->nullable();
            $table->string('fulfillment_status', 50)->nullable();
            $table->char('currency', 3);
            $table->decimal('total_price', 20, 4);
            $table->decimal('subtotal_price', 20, 4);
            $table->decimal('total_tax', 20, 4);
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('created_at_shopify');
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->unique(['store_id', 'shopify_order_id'], 'orders_store_shopify_id_unique');
            $table->index(['organization_id', 'store_id', 'financial_status']);
            $table->index(['store_id', 'processed_at']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('shopify_line_item_id');
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->unsignedBigInteger('shopify_product_id')->nullable();
            $table->unsignedBigInteger('shopify_variant_id')->nullable();
            $table->string('title');
            $table->unsignedInteger('quantity');
            $table->decimal('price', 20, 4);
            $table->timestamps();

            $table->unique(['order_id', 'shopify_line_item_id'], 'order_items_order_shopify_id_unique');
            $table->index('shopify_product_id');
            $table->index('shopify_variant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
