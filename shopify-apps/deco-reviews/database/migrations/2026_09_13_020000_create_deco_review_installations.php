<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deco_review_installations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('environment', 16);
            $table->string('client_id', 80);
            $table->text('access_token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->unique(['store_id', 'environment']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deco_review_installations');
    }
};
