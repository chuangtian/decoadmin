<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_jobs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('app_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 100);
            $table->string('direction', 20)->default('pull');
            $table->string('status', 30)->default('pending');
            $table->string('cursor', 2048)->nullable();
            $table->json('payload')->nullable();
            $table->json('result')->nullable();
            $table->unsignedInteger('total_items')->default(0);
            $table->unsignedInteger('processed_items')->default(0);
            $table->unsignedInteger('failed_items')->default(0);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('max_attempts')->default(3);
            $table->timestamp('available_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['store_id', 'type', 'created_at'], 'sync_jobs_store_type_created_index');
            $table->index(['status', 'available_at'], 'sync_jobs_status_available_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_jobs');
    }
};
