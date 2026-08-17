<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sync_jobs', function (Blueprint $table) {
            $table->foreignId('app_installation_id')
                ->nullable()
                ->after('app_id')
                ->constrained()
                ->nullOnDelete();
            $table->json('logs')->nullable()->after('result');
            $table->timestamp('finished_at')->nullable()->after('started_at');
        });
    }

    public function down(): void
    {
        Schema::table('sync_jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('app_installation_id');
            $table->dropColumn(['logs', 'finished_at']);
        });
    }
};
