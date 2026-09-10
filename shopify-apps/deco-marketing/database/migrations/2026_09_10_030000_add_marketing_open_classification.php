<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_deliveries', function (Blueprint $table) {
            $table->timestamp('human_opened_at')->nullable();
            $table->timestamp('machine_opened_at')->nullable();
            $table->string('open_classifier', 40)->nullable();
        });
        Schema::table('marketing_settings', function (Blueprint $table) {
            $table->boolean('warmup_enabled')->default(false);
            $table->json('warmup_steps')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('marketing_deliveries', fn (Blueprint $table) => $table->dropColumn(['human_opened_at', 'machine_opened_at', 'open_classifier']));
        Schema::table('marketing_settings', fn (Blueprint $table) => $table->dropColumn(['warmup_enabled', 'warmup_steps']));
    }
};
