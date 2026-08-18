<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_events', function (Blueprint $table) {
            $table->string('processing_result', 30)->nullable()->after('status');
            $table->string('handler', 255)->nullable()->after('processing_result');
            $table->text('unsupported_reason')->nullable()->after('handler');
            $table->timestamp('processing_started_at')->nullable()->after('received_at');
            $table->unsignedInteger('processing_duration_ms')->nullable()->after('processed_at');
        });

        DB::table('webhook_events')->where('status', 'pending')->update(['status' => 'queued']);
    }

    public function down(): void
    {
        DB::table('webhook_events')->where('status', 'queued')->update(['status' => 'pending']);

        Schema::table('webhook_events', function (Blueprint $table) {
            $table->dropColumn([
                'processing_result',
                'handler',
                'unsupported_reason',
                'processing_started_at',
                'processing_duration_ms',
            ]);
        });
    }
};
