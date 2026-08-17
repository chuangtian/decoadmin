<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_events', function (Blueprint $table) {
            $table->longText('payload_encrypted')->nullable()->after('payload');
            $table->string('payload_sha256', 64)->nullable()->after('payload_encrypted');
        });
    }

    public function down(): void
    {
        Schema::table('webhook_events', function (Blueprint $table) {
            $table->dropColumn(['payload_encrypted', 'payload_sha256']);
        });
    }
};
