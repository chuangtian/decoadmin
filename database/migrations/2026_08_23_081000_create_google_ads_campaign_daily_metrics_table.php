<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_ads_campaign_daily_metrics', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('advertising_channel_account_id');
            $table->string('external_account_id', 128);
            $table->string('campaign_id', 128);
            $table->string('campaign_name', 500)->nullable();
            $table->string('campaign_status', 50)->nullable();
            $table->string('advertising_channel_type', 100)->nullable();
            $table->date('metric_date');
            $table->decimal('spend', 24, 6)->default(0);
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->decimal('conversions', 24, 6)->default(0);
            $table->decimal('conversions_value', 24, 6)->default(0);
            $table->decimal('conversion_value_by_conversion_date', 24, 6)->default(0);
            $table->decimal('all_conversions', 24, 6)->default(0);
            $table->decimal('all_conversions_value', 24, 6)->default(0);
            $table->decimal('all_conversions_value_by_conversion_date', 24, 6)->default(0);
            $table->json('raw_payload');
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->foreign(
                ['advertising_channel_account_id', 'organization_id', 'store_id'],
                'google_campaign_metrics_account_scope_fk',
            )->references(['id', 'organization_id', 'store_id'])
                ->on('advertising_channel_accounts')->cascadeOnDelete();
            $table->unique(
                ['organization_id', 'store_id', 'external_account_id', 'campaign_id', 'metric_date'],
                'google_campaign_metrics_scope_day_unique',
            );
            $table->index(
                ['organization_id', 'store_id', 'external_account_id', 'metric_date'],
                'google_campaign_metrics_scope_period_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_ads_campaign_daily_metrics');
    }
};
