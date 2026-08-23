<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_sync_states', function (Blueprint $table): void {
            $table->date('last_metric_date')->nullable()->after('next_sync_at');
            $table->timestamp('data_synced_at')->nullable()->after('last_metric_date');
        });

        Schema::table('meta_ad_insights', function (Blueprint $table): void {
            $table->index(
                ['organization_id', 'store_id', 'level', 'granularity', 'date_stop', 'synced_at'],
                'meta_insights_scope_latest_daily_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('meta_ad_insights', function (Blueprint $table): void {
            $table->dropIndex('meta_insights_scope_latest_daily_index');
        });

        Schema::table('store_sync_states', function (Blueprint $table): void {
            $table->dropColumn(['last_metric_date', 'data_synced_at']);
        });
    }
};
