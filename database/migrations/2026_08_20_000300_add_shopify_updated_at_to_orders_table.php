<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('updated_at_shopify')->nullable()->after('created_at_shopify');
            $table->index(['store_id', 'updated_at_shopify'], 'orders_store_shopify_updated_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_store_shopify_updated_index');
            $table->dropColumn('updated_at_shopify');
        });
    }
};
