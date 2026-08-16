<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_installations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('shopify_connection_id');
            $table->foreignId('installed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('external_installation_id', 255)->nullable()->unique();
            $table->string('status', 30)->default('active')->index();
            $table->json('granted_scopes');
            $table->json('settings')->nullable();
            $table->timestamp('installed_at')->nullable();
            $table->timestamp('uninstalled_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['app_id', 'store_id'], 'app_installations_app_store_unique');
            $table->foreign(['shopify_connection_id', 'store_id'], 'app_installs_connection_store_fk')
                ->references(['id', 'store_id'])
                ->on('shopify_connections')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_installations');
    }
};
