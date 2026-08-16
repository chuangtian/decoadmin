<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopify_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('shopify_shop_id')->nullable()->unique();
            $table->string('shop_domain', 255)->unique();
            $table->text('access_token_encrypted');
            $table->text('refresh_token_encrypted')->nullable();
            $table->string('token_type', 30)->default('offline');
            $table->timestamp('access_token_expires_at')->nullable()->index();
            $table->json('scopes');
            $table->string('api_version', 20);
            $table->string('status', 30)->default('active')->index();
            $table->timestamp('installed_at')->nullable();
            $table->timestamp('uninstalled_at')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['id', 'store_id'], 'shopify_connections_id_store_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopify_connections');
    }
};
