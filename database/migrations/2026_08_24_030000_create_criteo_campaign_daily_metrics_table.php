<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('criteo_campaign_daily_metrics', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('advertising_channel_account_id');
            $table->string('external_account_id', 128);
            $table->string('campaign_id', 128);
            $table->string('campaign_name', 500)->nullable();
            $table->string('campaign_status', 50)->nullable();
            $table->date('metric_date');
            $table->decimal('spend', 24, 6)->default(0);
            $table->decimal('attributed_sales', 24, 6)->default(0);
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->decimal('conversions', 24, 6)->default(0);
            $table->json('raw_payload')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['organization_id', 'store_id', 'external_account_id', 'campaign_id', 'metric_date'],
                'criteo_campaign_daily_store_unique',
            );
            $table->index(['organization_id', 'store_id', 'metric_date'], 'criteo_campaign_daily_scope_date');
            $table->foreign('advertising_channel_account_id', 'criteo_campaign_daily_account_fk')
                ->references('id')->on('advertising_channel_accounts')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('criteo_campaign_daily_metrics');
    }
};
