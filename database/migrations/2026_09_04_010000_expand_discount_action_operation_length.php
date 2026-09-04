<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Update operations include the complete Shopify GID, not only "update".
        Schema::table('discount_action_idempotencies', function (Blueprint $table): void {
            $table->string('operation', 191)->change();
        });
    }

    public function down(): void
    {
        if (DB::table('discount_action_idempotencies')->whereRaw('LENGTH(operation) > 40')->exists()) {
            throw new RuntimeException('Cannot shorten discount operation storage while long operation keys exist.');
        }

        Schema::table('discount_action_idempotencies', function (Blueprint $table): void {
            $table->string('operation', 40)->change();
        });
    }
};
