<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_settings', function (Blueprint $t) {
            $t->json('sync_state')->nullable();
        });
        Schema::table('marketing_events', function (Blueprint $t) {
            $t->unsignedInteger('attempts')->default(0);
            $t->timestamp('retry_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('marketing_settings', fn (Blueprint $t) => $t->dropColumn('sync_state'));
        Schema::table('marketing_events', fn (Blueprint $t) => $t->dropColumn(['attempts', 'retry_at']));
    }
};
