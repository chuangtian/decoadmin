<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_gsc_search_type_daily_metrics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->date('metric_date');
            $table->string('search_type', 24);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->unsignedBigInteger('impressions')->default(0);
            $table->decimal('average_position', 12, 6)->default(0);
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->unique(['store_id', 'metric_date', 'search_type'], 'seo_gsc_type_store_date_unique');
            $table->index(['organization_id', 'store_id', 'metric_date'], 'seo_gsc_type_scope_date_index');
        });

        Schema::create('seo_gsc_breakdown_daily_metrics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->date('metric_date');
            $table->string('search_type', 24)->default('web');
            $table->string('dimension', 32);
            $table->char('value_hash', 64);
            $table->text('value');
            $table->unsignedBigInteger('clicks')->default(0);
            $table->unsignedBigInteger('impressions')->default(0);
            $table->decimal('average_position', 12, 6)->default(0);
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->unique(['store_id', 'metric_date', 'search_type', 'dimension', 'value_hash'], 'seo_gsc_breakdown_store_date_dimension_unique');
            $table->index(['organization_id', 'store_id', 'metric_date'], 'seo_gsc_breakdown_scope_date_index');
            $table->index(['store_id', 'search_type', 'dimension', 'metric_date'], 'seo_gsc_breakdown_filter_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_gsc_breakdown_daily_metrics');
        Schema::dropIfExists('seo_gsc_search_type_daily_metrics');
    }
};
