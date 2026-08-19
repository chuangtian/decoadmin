<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            $table->uuid('analytics_ingest_key')->nullable()->after('settings');
            $table->unique('analytics_ingest_key', 'stores_analytics_ingest_key_unique');
        });

        DB::table('stores')->orderBy('id')->eachById(function (object $store): void {
            DB::table('stores')->where('id', $store->id)->update([
                'analytics_ingest_key' => (string) Str::uuid(),
            ]);
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->string('sales_channel', 80)->nullable()->after('fulfillment_status');
            $table->unsignedBigInteger('shopify_order_app_id')->nullable()->after('sales_channel');
            $table->string('sales_channel_name', 160)->nullable()->after('shopify_order_app_id');
            $table->unsignedBigInteger('pos_location_id')->nullable()->after('sales_channel_name');
            $table->string('pos_location_name', 160)->nullable()->after('pos_location_id');
            $table->unsignedBigInteger('pos_staff_id')->nullable()->after('pos_location_name');
            $table->string('pos_staff_name', 160)->nullable()->after('pos_staff_id');

            $table->index(['store_id', 'sales_channel', 'created_at_shopify'], 'orders_store_channel_period_index');
            $table->index(['store_id', 'pos_location_id', 'created_at_shopify'], 'orders_store_pos_location_period_index');
            $table->index(['store_id', 'pos_staff_id', 'created_at_shopify'], 'orders_store_pos_staff_period_index');
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->unsignedInteger('current_quantity')->default(0)->after('quantity');
            $table->decimal('attributed_sales', 20, 4)->default(0)->after('price');
            $table->unsignedBigInteger('shopify_staff_id')->nullable()->after('attributed_sales');
            $table->string('staff_name', 160)->nullable()->after('shopify_staff_id');

            $table->index(['shopify_staff_id', 'order_id'], 'order_items_staff_order_index');
        });

        DB::table('order_items')->update([
            'current_quantity' => DB::raw('quantity'),
            'attributed_sales' => DB::raw('quantity * price'),
        ]);

        Schema::create('storefront_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('event_id', 191);
            $table->string('event_name', 80);
            $table->char('client_id_hash', 64);
            $table->char('session_id_hash', 64);
            $table->timestamp('occurred_at');
            $table->string('path', 512)->nullable();
            $table->string('referrer_host', 255)->nullable();
            $table->string('search_query', 160)->nullable();
            $table->timestamp('received_at');
            $table->timestamps();

            $table->unique(['store_id', 'event_id'], 'storefront_events_store_event_unique');
            $table->index(['store_id', 'occurred_at', 'event_name'], 'storefront_events_store_period_event_index');
            $table->index(['store_id', 'session_id_hash', 'occurred_at'], 'storefront_events_store_session_period_index');
            $table->index(['store_id', 'search_query', 'occurred_at'], 'storefront_events_store_search_period_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_events');

        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropIndex('order_items_staff_order_index');
            $table->dropColumn(['current_quantity', 'attributed_sales', 'shopify_staff_id', 'staff_name']);
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_store_channel_period_index');
            $table->dropIndex('orders_store_pos_location_period_index');
            $table->dropIndex('orders_store_pos_staff_period_index');
            $table->dropColumn([
                'sales_channel', 'shopify_order_app_id', 'sales_channel_name',
                'pos_location_id', 'pos_location_name', 'pos_staff_id', 'pos_staff_name',
            ]);
        });

        Schema::table('stores', function (Blueprint $table): void {
            $table->dropUnique('stores_analytics_ingest_key_unique');
            $table->dropColumn('analytics_ingest_key');
        });
    }
};
