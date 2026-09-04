<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personalization_event_sources', function (Blueprint $table): void {
            $table->timestamp('purge_after')->nullable()->after('last_event_at');
            $table->index(['status', 'purge_after'], 'personalization_event_source_purge_index');
        });

        Schema::create('personalization_daily_metrics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->date('metric_date');
            $table->string('placement', 24);
            $table->string('component_key', 64)->default('');
            $table->string('strategy_key', 64)->default('');
            $table->char('currency', 3);
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->unsignedBigInteger('add_to_carts')->default(0);
            $table->unsignedBigInteger('orders')->default(0);
            $table->decimal('attributed_revenue', 20, 4)->default(0);
            $table->timestamps();

            $table->unique(
                ['store_id', 'metric_date', 'placement', 'component_key', 'strategy_key', 'currency'],
                'personalization_daily_metric_dimension_unique',
            );
            $table->index(
                ['organization_id', 'store_id', 'metric_date'],
                'personalization_daily_metric_scope_date_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personalization_daily_metrics');
        Schema::table('personalization_event_sources', function (Blueprint $table): void {
            $table->dropIndex('personalization_event_source_purge_index');
            $table->dropColumn('purge_after');
        });
    }
};
