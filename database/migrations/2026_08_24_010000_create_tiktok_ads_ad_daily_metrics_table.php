<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tiktok_ads_ad_daily_metrics', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('advertising_channel_account_id');
            $table->string('external_account_id', 128);
            $table->string('campaign_id', 128)->nullable();
            $table->string('campaign_name', 500)->nullable();
            $table->string('adgroup_id', 128)->nullable();
            $table->string('ad_id', 128);
            $table->string('ad_name', 500)->nullable();
            $table->text('ad_text')->nullable();
            $table->json('ad_texts')->nullable();
            $table->string('ad_format', 100)->nullable();
            $table->string('video_id', 128)->nullable();
            $table->date('metric_date');
            $table->decimal('spend', 24, 6)->default(0);
            $table->decimal('attributed_sales', 24, 6)->default(0);
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->decimal('conversions', 24, 6)->default(0);
            $table->unsignedBigInteger('video_play_actions')->default(0);
            $table->unsignedBigInteger('video_watched_2s')->default(0);
            $table->decimal('average_video_play', 18, 6)->default(0);
            $table->json('raw_payload')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['organization_id', 'store_id', 'external_account_id', 'ad_id', 'metric_date'],
                'tiktok_ad_daily_store_unique',
            );
            $table->index(
                ['organization_id', 'store_id', 'external_account_id', 'metric_date'],
                'tiktok_ad_daily_scope_account_date',
            );
            $table->foreign('advertising_channel_account_id', 'tiktok_ad_daily_account_fk')
                ->references('id')->on('advertising_channel_accounts')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tiktok_ads_ad_daily_metrics');
    }
};
