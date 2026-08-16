<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('shopify_domain', 255)->unique();
            $table->unsignedBigInteger('shopify_shop_id')->nullable()->unique();
            $table->string('status', 30)->default('pending')->index();
            $table->string('timezone', 100)->default('UTC');
            $table->char('currency', 3)->default('USD');
            $table->char('country_code', 2)->nullable();
            $table->string('plan_name', 100)->nullable();
            $table->json('settings')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['id', 'organization_id'], 'stores_id_org_unique');
            $table->index(['organization_id', 'status'], 'stores_org_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stores');
    }
};
