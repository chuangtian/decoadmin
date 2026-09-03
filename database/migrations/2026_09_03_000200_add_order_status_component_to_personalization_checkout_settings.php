<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personalization_checkout_settings', function (Blueprint $table): void {
            $table->foreignId('order_status_component_id')
                ->nullable()
                ->after('thank_you_component_id')
                ->constrained('personalization_recommendation_components', indexName: 'pers_checkout_order_status_component_fk')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('personalization_checkout_settings', function (Blueprint $table): void {
            $table->dropForeign('pers_checkout_order_status_component_fk');
            $table->dropColumn('order_status_component_id');
        });
    }
};
