<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('storefront_events', function (Blueprint $table): void {
            $table->char('country_code', 2)->nullable()->after('referrer_host');
            $table->string('region_code', 64)->nullable()->after('country_code');
            $table->string('city', 120)->nullable()->after('region_code');
            $table->index(['store_id', 'country_code', 'region_code', 'occurred_at'], 'storefront_events_store_location_period_index');
        });
    }

    public function down(): void
    {
        Schema::table('storefront_events', function (Blueprint $table): void {
            $table->dropIndex('storefront_events_store_location_period_index');
            $table->dropColumn(['country_code', 'region_code', 'city']);
        });
    }
};
