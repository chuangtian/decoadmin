<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('advertising_channel_accounts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('store_id');
            $table->string('provider', 32);
            $table->string('external_account_id', 128);
            $table->string('name')->nullable();
            $table->string('status', 50)->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('timezone', 100)->nullable();
            $table->json('raw_payload');
            $table->timestamp('last_seen_at');
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign(['store_id', 'organization_id'], 'advertising_accounts_store_org_fk')
                ->references(['id', 'organization_id'])->on('stores')->cascadeOnDelete();
            $table->unique(
                ['organization_id', 'store_id', 'provider', 'external_account_id'],
                'advertising_accounts_scope_external_unique',
            );
            $table->unique(
                ['id', 'organization_id', 'store_id'],
                'advertising_accounts_id_scope_unique',
            );
            $table->index(['organization_id', 'store_id', 'provider'], 'advertising_accounts_scope_provider_index');
        });

        Schema::create('advertising_channel_daily_metrics', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('advertising_channel_account_id');
            $table->string('provider', 32);
            $table->string('external_account_id', 128);
            $table->date('metric_date');
            $table->decimal('spend', 24, 6)->default(0);
            $table->decimal('attributed_sales', 24, 6)->default(0);
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->decimal('conversions', 24, 6)->default(0);
            $table->json('raw_payload');
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->foreign(
                ['advertising_channel_account_id', 'organization_id', 'store_id'],
                'advertising_metrics_account_scope_fk',
            )->references(['id', 'organization_id', 'store_id'])
                ->on('advertising_channel_accounts')->cascadeOnDelete();
            $table->unique(
                ['organization_id', 'store_id', 'provider', 'external_account_id', 'metric_date'],
                'advertising_metrics_scope_day_unique',
            );
            $table->index(
                ['organization_id', 'store_id', 'provider', 'metric_date'],
                'advertising_metrics_scope_period_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('advertising_channel_daily_metrics');
        Schema::dropIfExists('advertising_channel_accounts');
    }
};
