<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopify_product_monitors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('shopify_product_id', 80);
            $table->boolean('is_enabled')->default(false);
            $table->unsignedInteger('low_stock_threshold')->default(10);
            $table->unsignedInteger('generation')->default(0);
            $table->unsignedBigInteger('revision')->default(0);
            $table->json('snapshot')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamps();
            $table->unique(['store_id', 'shopify_product_id']);
            $table->index(['is_enabled', 'store_id']);
        });
        Schema::table('store_notification_settings', fn (Blueprint $table) => $table->boolean('notify_product_monitor')->default(true));
    }

    public function down(): void
    {
        Schema::table('store_notification_settings', fn (Blueprint $table) => $table->dropColumn('notify_product_monitor'));
        Schema::dropIfExists('shopify_product_monitors');
    }
};
