<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personalization_checkout_settings', function (Blueprint $table): void {
            $table->foreignId('thank_you_component_id')
                ->nullable()
                ->after('component_id')
                ->constrained('personalization_recommendation_components')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('personalization_checkout_settings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('thank_you_component_id');
        });
    }
};
