<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('model_asset_folders', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 80);
            $table->timestamps();

            $table->unique(['store_id', 'name'], 'model_asset_folders_store_name_unique');
            $table->index(['organization_id', 'store_id', 'created_at'], 'model_asset_folders_scope_created_index');
        });

        Schema::create('model_asset_images', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('folder_id')->constrained('model_asset_folders')->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('disk', 40)->default('local');
            $table->string('path', 512)->unique();
            $table->string('original_name', 255)->nullable();
            $table->string('mime_type', 80);
            $table->unsignedBigInteger('byte_size');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->timestamps();

            $table->index(['organization_id', 'store_id', 'folder_id', 'created_at'], 'model_asset_images_scope_folder_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('model_asset_images');
        Schema::dropIfExists('model_asset_folders');
    }
};
