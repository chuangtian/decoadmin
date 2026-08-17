<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('shopify_customer_id');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('state', 50)->nullable();
            $table->boolean('verified_email')->default(false);
            $table->unsignedBigInteger('orders_count')->default(0);
            $table->decimal('total_spent', 20, 4)->default(0);
            $table->timestamp('created_at_shopify');
            $table->timestamp('updated_at_shopify');
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->unique(['store_id', 'shopify_customer_id'], 'customers_store_shopify_id_unique');
            $table->index(['organization_id', 'store_id', 'state']);
            $table->index(['store_id', 'updated_at_shopify']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
