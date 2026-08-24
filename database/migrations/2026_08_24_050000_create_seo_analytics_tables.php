<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_ga4_channel_daily_metrics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->date('metric_date');
            $table->string('channel_group', 120);
            $table->decimal('total_revenue', 20, 6)->default(0);
            $table->unsignedBigInteger('sessions')->default(0);
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->unique(['store_id', 'metric_date', 'channel_group'], 'seo_ga4_channel_store_date_group_unique');
            $table->index(['organization_id', 'store_id', 'metric_date'], 'seo_ga4_channel_scope_date_index');
        });

        Schema::create('seo_ga4_landing_page_daily_metrics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->date('metric_date');
            $table->char('landing_page_hash', 64);
            $table->text('landing_page');
            $table->string('channel_group', 120);
            $table->unsignedBigInteger('sessions')->default(0);
            $table->unsignedBigInteger('active_users')->default(0);
            $table->unsignedBigInteger('new_users')->default(0);
            $table->decimal('engagement_duration', 20, 6)->default(0);
            $table->decimal('key_events', 20, 6)->default(0);
            $table->decimal('total_revenue', 20, 6)->default(0);
            $table->decimal('bounce_rate', 12, 8)->default(0);
            $table->decimal('session_key_event_rate', 12, 8)->default(0);
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->unique(['store_id', 'metric_date', 'landing_page_hash', 'channel_group'], 'seo_ga4_landing_store_date_page_group_unique');
            $table->index(['organization_id', 'store_id', 'metric_date'], 'seo_ga4_landing_scope_date_index');
        });

        Schema::create('seo_gsc_daily_metrics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->date('metric_date');
            $table->string('segment', 24);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->unsignedBigInteger('impressions')->default(0);
            $table->decimal('average_position', 12, 6)->default(0);
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->unique(['store_id', 'metric_date', 'segment'], 'seo_gsc_daily_store_date_segment_unique');
            $table->index(['organization_id', 'store_id', 'metric_date'], 'seo_gsc_daily_scope_date_index');
        });

        Schema::create('seo_gsc_query_daily_metrics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->date('metric_date');
            $table->string('segment', 24);
            $table->char('query_hash', 64);
            $table->text('query');
            $table->unsignedBigInteger('clicks')->default(0);
            $table->unsignedBigInteger('impressions')->default(0);
            $table->decimal('average_position', 12, 6)->default(0);
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->unique(['store_id', 'metric_date', 'segment', 'query_hash'], 'seo_gsc_query_store_date_segment_hash_unique');
            $table->index(['organization_id', 'store_id', 'metric_date'], 'seo_gsc_query_scope_date_index');
            $table->index(['store_id', 'segment', 'metric_date'], 'seo_gsc_query_segment_date_index');
        });

        Schema::create('seo_gsc_page_daily_metrics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->date('metric_date');
            $table->string('segment', 24);
            $table->char('page_hash', 64);
            $table->text('page');
            $table->unsignedBigInteger('clicks')->default(0);
            $table->unsignedBigInteger('impressions')->default(0);
            $table->decimal('average_position', 12, 6)->default(0);
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->unique(['store_id', 'metric_date', 'segment', 'page_hash'], 'seo_gsc_page_store_date_segment_hash_unique');
            $table->index(['organization_id', 'store_id', 'metric_date'], 'seo_gsc_page_scope_date_index');
            $table->index(['store_id', 'segment', 'metric_date'], 'seo_gsc_page_segment_date_index');
        });

        Schema::create('seo_analytics_sync_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 24)->default('scheduled');
            $table->string('mode', 24)->default('incremental');
            $table->string('status', 24)->default('queued');
            $table->date('date_from')->nullable();
            $table->date('date_to')->nullable();
            $table->unsignedSmallInteger('progress_percent')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->json('result')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'store_id', 'created_at'], 'seo_sync_run_scope_created_index');
            $table->index(['store_id', 'status'], 'seo_sync_run_store_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_analytics_sync_runs');
        Schema::dropIfExists('seo_gsc_page_daily_metrics');
        Schema::dropIfExists('seo_gsc_query_daily_metrics');
        Schema::dropIfExists('seo_gsc_daily_metrics');
        Schema::dropIfExists('seo_ga4_landing_page_daily_metrics');
        Schema::dropIfExists('seo_ga4_channel_daily_metrics');
    }
};
