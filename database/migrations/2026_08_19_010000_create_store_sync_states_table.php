<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_sync_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('sync_type', 100);
            $table->string('status', 30)->default('idle');
            $table->timestamp('watermark_at')->nullable();
            $table->timestamp('last_full_sync_at')->nullable();
            $table->timestamp('last_incremental_sync_at')->nullable();
            $table->timestamp('last_reconciled_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failed_at')->nullable();
            $table->foreignId('last_job_id')->nullable()->constrained('sync_jobs')->nullOnDelete();
            $table->unsignedSmallInteger('consecutive_failures')->default(0);
            $table->timestamp('next_sync_at')->nullable();
            $table->string('last_error_code', 100)->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'sync_type'], 'store_sync_states_store_type_unique');
            $table->index(['status', 'next_sync_at'], 'store_sync_states_status_next_index');
            $table->index(['organization_id', 'last_success_at'], 'store_sync_states_org_success_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_sync_states');
    }
};
