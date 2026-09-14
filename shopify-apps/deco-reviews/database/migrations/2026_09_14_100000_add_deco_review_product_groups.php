<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deco_review_groups', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['store_id', 'name']);
            $table->index(['organization_id', 'store_id', 'active']);
        });

        Schema::create('deco_review_group_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->constrained('deco_review_groups')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique('product_id');
            $table->unique(['group_id', 'product_id']);
            $table->index(['organization_id', 'store_id', 'group_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deco_review_group_products');
        Schema::dropIfExists('deco_review_groups');
    }
};
