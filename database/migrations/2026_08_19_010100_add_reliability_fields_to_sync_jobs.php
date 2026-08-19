<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sync_jobs', function (Blueprint $table) {
            $table->string('mode', 20)->default('full')->after('direction');
            $table->timestamp('since_at')->nullable()->after('cursor');
            $table->timestamp('until_at')->nullable()->after('since_at');
            $table->string('idempotency_key', 191)->nullable()->after('until_at');
            $table->string('error_code', 100)->nullable()->after('last_error');
            $table->uuid('correlation_id')->nullable()->after('error_code');

            $table->unique('idempotency_key', 'sync_jobs_idempotency_unique');
            $table->index(['store_id', 'type', 'mode', 'created_at'], 'sync_jobs_store_type_mode_created_index');
            $table->index('correlation_id', 'sync_jobs_correlation_index');
        });
    }

    public function down(): void
    {
        Schema::table('sync_jobs', function (Blueprint $table) {
            $table->dropUnique('sync_jobs_idempotency_unique');
            $table->dropIndex('sync_jobs_store_type_mode_created_index');
            $table->dropIndex('sync_jobs_correlation_index');
            $table->dropColumn([
                'mode', 'since_at', 'until_at', 'idempotency_key', 'error_code', 'correlation_id',
            ]);
        });
    }
};
