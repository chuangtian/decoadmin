<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_order_snapshots', fn (Blueprint $table) => $table->unsignedBigInteger('contact_id')->nullable()->change());
    }

    public function down(): void
    {
        // Keep nullable links: rollback must not delete financial snapshots without an email.
    }
};
