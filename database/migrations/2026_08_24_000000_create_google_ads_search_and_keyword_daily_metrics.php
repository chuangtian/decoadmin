<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_ads_search_term_daily_metrics', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('advertising_channel_account_id');
            $table->string('external_account_id', 128);
            $table->date('metric_date');
            $table->char('dimension_key', 64);
            $table->string('source_type', 40)->default('STANDARD');
            $table->text('search_term');
            $table->text('normalized_search_term');
            $table->string('status', 50)->nullable();
            $table->text('matched_keyword')->nullable();
            $table->string('match_type', 50)->nullable();
            $table->string('campaign_id', 128)->nullable();
            $table->string('campaign_name', 500)->nullable();
            $table->string('ad_group_id', 128)->nullable();
            $table->string('ad_group_name', 500)->nullable();
            $table->string('advertising_channel_type', 100)->nullable();
            $this->metrics($table);

            $table->foreign(['advertising_channel_account_id', 'organization_id', 'store_id'], 'google_search_metrics_account_scope_fk')
                ->references(['id', 'organization_id', 'store_id'])->on('advertising_channel_accounts')->cascadeOnDelete();
            $table->unique(['organization_id', 'store_id', 'external_account_id', 'metric_date', 'dimension_key'], 'google_search_metrics_scope_day_unique');
            $table->index(['organization_id', 'store_id', 'external_account_id', 'metric_date'], 'google_search_metrics_scope_period_index');
        });

        Schema::create('google_ads_keyword_daily_metrics', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('advertising_channel_account_id');
            $table->string('external_account_id', 128);
            $table->date('metric_date');
            $table->char('dimension_key', 64);
            $table->string('criterion_id', 128)->nullable();
            $table->text('keyword');
            $table->text('normalized_keyword');
            $table->string('match_type', 50)->nullable();
            $table->string('status', 50)->nullable();
            $table->string('campaign_id', 128)->nullable();
            $table->string('campaign_name', 500)->nullable();
            $table->string('ad_group_id', 128)->nullable();
            $table->string('ad_group_name', 500)->nullable();
            $this->metrics($table);

            $table->foreign(['advertising_channel_account_id', 'organization_id', 'store_id'], 'google_keyword_metrics_account_scope_fk')
                ->references(['id', 'organization_id', 'store_id'])->on('advertising_channel_accounts')->cascadeOnDelete();
            $table->unique(['organization_id', 'store_id', 'external_account_id', 'metric_date', 'dimension_key'], 'google_keyword_metrics_scope_day_unique');
            $table->index(['organization_id', 'store_id', 'external_account_id', 'metric_date'], 'google_keyword_metrics_scope_period_index');
        });
    }

    private function metrics(Blueprint $table): void
    {
        $table->decimal('spend', 24, 6)->default(0);
        $table->decimal('revenue', 24, 6)->default(0);
        $table->unsignedBigInteger('impressions')->default(0);
        $table->unsignedBigInteger('clicks')->default(0);
        $table->decimal('conversions', 24, 6)->default(0);
        $table->json('raw_payload');
        $table->timestamp('synced_at');
        $table->timestamps();
    }

    public function down(): void
    {
        Schema::dropIfExists('google_ads_keyword_daily_metrics');
        Schema::dropIfExists('google_ads_search_term_daily_metrics');
    }
};
