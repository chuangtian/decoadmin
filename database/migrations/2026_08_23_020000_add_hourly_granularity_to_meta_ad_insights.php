<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meta_ad_insights', function (Blueprint $table): void {
            $table->dropUnique('meta_insights_scope_entity_period_unique');
            $table->string('granularity', 8)->default('day')->after('date_stop');
            $table->string('hourly_range', 32)->default('')->after('granularity');
            $table->timestamp('hour_start_at')->nullable()->after('hourly_range');
            $table->timestamp('hour_end_at')->nullable()->after('hour_start_at');
            $table->unique(
                [
                    'organization_id', 'store_id', 'level', 'entity_id', 'date_start', 'date_stop',
                    'granularity', 'hourly_range',
                ],
                'meta_insights_scope_entity_window_unique',
            );
            $table->index(
                ['organization_id', 'store_id', 'hour_start_at', 'level'],
                'meta_insights_scope_hour_level_index',
            );
        });
    }

    public function down(): void
    {
        DB::table('meta_ad_insights')->where('granularity', 'hour')->delete();

        Schema::table('meta_ad_insights', function (Blueprint $table): void {
            $table->dropIndex('meta_insights_scope_hour_level_index');
            $table->dropUnique('meta_insights_scope_entity_window_unique');
            $table->dropColumn(['granularity', 'hourly_range', 'hour_start_at', 'hour_end_at']);
            $table->unique(
                ['organization_id', 'store_id', 'level', 'entity_id', 'date_start', 'date_stop'],
                'meta_insights_scope_entity_period_unique',
            );
        });
    }
};
