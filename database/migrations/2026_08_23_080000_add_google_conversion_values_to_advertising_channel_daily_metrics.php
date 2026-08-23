<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('advertising_channel_daily_metrics', function (Blueprint $table): void {
            $table->decimal('conversion_value_by_conversion_date', 24, 6)->default(0)->after('attributed_sales');
            $table->decimal('all_conversions', 24, 6)->default(0)->after('conversions');
            $table->decimal('all_conversions_value', 24, 6)->default(0)->after('all_conversions');
            $table->decimal('all_conversions_value_by_conversion_date', 24, 6)->default(0)->after('all_conversions_value');
        });
    }

    public function down(): void
    {
        Schema::table('advertising_channel_daily_metrics', function (Blueprint $table): void {
            $table->dropColumn([
                'conversion_value_by_conversion_date',
                'all_conversions',
                'all_conversions_value',
                'all_conversions_value_by_conversion_date',
            ]);
        });
    }
};
