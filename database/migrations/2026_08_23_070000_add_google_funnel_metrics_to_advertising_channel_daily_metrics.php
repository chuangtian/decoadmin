<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('advertising_channel_daily_metrics', function (Blueprint $table): void {
            $table->decimal('add_to_cart', 24, 6)->default(0)->after('conversions');
            $table->decimal('initiate_checkout', 24, 6)->default(0)->after('add_to_cart');
        });
    }

    public function down(): void
    {
        Schema::table('advertising_channel_daily_metrics', function (Blueprint $table): void {
            $table->dropColumn(['add_to_cart', 'initiate_checkout']);
        });
    }
};
