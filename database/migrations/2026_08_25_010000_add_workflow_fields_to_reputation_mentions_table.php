<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reputation_mentions', function (Blueprint $table): void {
            $table->string('origin', 24)->default('source')->after('store_id');
            $table->longText('response_note')->nullable()->after('processing_status');
            $table->index(['store_id', 'origin', 'is_active'], 'rep_mentions_store_origin_active_index');
        });
    }

    public function down(): void
    {
        Schema::table('reputation_mentions', function (Blueprint $table): void {
            $table->dropIndex('rep_mentions_store_origin_active_index');
            $table->dropColumn(['origin', 'response_note']);
        });
    }
};
