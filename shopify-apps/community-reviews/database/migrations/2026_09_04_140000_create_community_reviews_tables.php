<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('community_review_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->string('heading')->default('Hear from our community');
            $table->string('read_more_url', 2048)->nullable();
            $table->unsignedTinyInteger('card_count')->default(12);
            $table->timestamps();
        });
        Schema::create('community_review_models', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('folder_id')->unique()->constrained('model_asset_folders')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('label', 120);
            $table->string('series', 120)->nullable();
            $table->json('aliases')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->index(['organization_id', 'store_id']);
        });
        Schema::create('community_review_installations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('environment', 16);
            $table->string('app_installation_id')->nullable();
            $table->text('access_token_encrypted')->nullable();
            $table->json('granted_scopes')->nullable();
            $table->timestamp('installed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_review_installations');
        Schema::dropIfExists('community_review_models');
        Schema::dropIfExists('community_review_settings');
    }
};
