<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasIndex('seo_gsc_page_daily_metrics', 'seo_gsc_page_detail_covering_index')) {
            Schema::table('seo_gsc_page_daily_metrics', function (Blueprint $table): void {
                $table->index(
                    ['organization_id', 'store_id', 'segment', 'metric_date', 'page_id', 'clicks', 'impressions', 'average_position'],
                    'seo_gsc_page_detail_covering_index',
                );
            });
        }

        if (! Schema::hasIndex('seo_gsc_query_daily_metrics', 'seo_gsc_query_detail_covering_index')) {
            Schema::table('seo_gsc_query_daily_metrics', function (Blueprint $table): void {
                $table->index(
                    ['organization_id', 'store_id', 'segment', 'metric_date', 'query_id', 'clicks', 'impressions', 'average_position'],
                    'seo_gsc_query_detail_covering_index',
                );
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('seo_gsc_query_daily_metrics', 'seo_gsc_query_detail_covering_index')) {
            Schema::table('seo_gsc_query_daily_metrics', function (Blueprint $table): void {
                $table->dropIndex('seo_gsc_query_detail_covering_index');
            });
        }

        if (Schema::hasIndex('seo_gsc_page_daily_metrics', 'seo_gsc_page_detail_covering_index')) {
            Schema::table('seo_gsc_page_daily_metrics', function (Blueprint $table): void {
                $table->dropIndex('seo_gsc_page_detail_covering_index');
            });
        }
    }
};
