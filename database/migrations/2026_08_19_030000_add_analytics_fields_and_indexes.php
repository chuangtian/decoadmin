<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->unsignedBigInteger('shopify_customer_id')->nullable()->after('shopify_order_id');
            $table->decimal('net_sales', 20, 4)->default(0)->after('subtotal_price');
            $table->decimal('discount_total', 20, 4)->default(0)->after('net_sales');
            $table->decimal('refund_total', 20, 4)->default(0)->after('discount_total');
            $table->decimal('shipping_total', 20, 4)->default(0)->after('refund_total');
            $table->boolean('is_test')->default(false)->after('fulfillment_status');
            $table->timestamp('cancelled_at')->nullable()->after('processed_at');

            $table->index(['store_id', 'is_test', 'cancelled_at', 'created_at_shopify'], 'orders_store_reporting_period_index');
            $table->index(['store_id', 'currency', 'created_at_shopify'], 'orders_store_currency_period_index');
            $table->index(['store_id', 'shopify_customer_id', 'created_at_shopify'], 'orders_store_customer_period_index');
        });

        DB::table('orders')->update([
            'net_sales' => DB::raw('subtotal_price'),
        ]);

        Schema::table('order_items', function (Blueprint $table): void {
            $table->index(['product_id', 'order_id'], 'order_items_product_order_index');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->index(['store_id', 'vendor', 'product_type'], 'products_store_vendor_type_index');
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->index(['store_id', 'created_at_shopify', 'orders_count'], 'customers_store_created_orders_index');
        });

        Schema::table('inventory_levels', function (Blueprint $table): void {
            $table->index(['inventory_item_id', 'available'], 'inventory_levels_item_available_index');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_levels', function (Blueprint $table): void {
            $table->dropIndex('inventory_levels_item_available_index');
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->dropIndex('customers_store_created_orders_index');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex('products_store_vendor_type_index');
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropIndex('order_items_product_order_index');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_store_reporting_period_index');
            $table->dropIndex('orders_store_currency_period_index');
            $table->dropIndex('orders_store_customer_period_index');
            $table->dropColumn([
                'shopify_customer_id', 'net_sales', 'discount_total', 'refund_total',
                'shipping_total', 'is_test', 'cancelled_at',
            ]);
        });
    }
};
