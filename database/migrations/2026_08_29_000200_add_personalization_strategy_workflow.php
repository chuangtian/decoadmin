<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personalization_recommendation_strategies', function (Blueprint $table): void {
            $table->string('status', 32)->default('draft')->after('enabled');
            $table->timestamp('archived_at')->nullable()->after('settings');
            $table->timestamp('purge_after')->nullable()->after('archived_at');
            $table->index(['store_id', 'status', 'updated_at'], 'personalization_strategy_store_status_updated_index');
            $table->index(['status', 'purge_after'], 'personalization_strategy_recycle_index');
        });

        Schema::create('personalization_strategy_versions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained(indexName: 'pers_strategy_versions_org_fk')->cascadeOnDelete();
            $table->foreignId('store_id')->constrained(indexName: 'pers_strategy_versions_store_fk')->cascadeOnDelete();
            $table->foreignId('strategy_id')->constrained('personalization_recommendation_strategies', indexName: 'pers_strategy_versions_strategy_fk')->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('status', 20)->default('draft');
            $table->string('name', 80);
            $table->string('algorithm', 40);
            $table->unsignedTinyInteger('item_limit')->default(8);
            $table->json('configuration');
            $table->char('checksum', 64);
            $table->unsignedInteger('lock_version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users', indexName: 'pers_strategy_versions_created_by_fk')->nullOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users', indexName: 'pers_strategy_versions_published_by_fk')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['strategy_id', 'version_number'], 'personalization_strategy_version_number_unique');
            $table->index(['store_id', 'status', 'updated_at'], 'personalization_strategy_version_store_status_index');
        });

        Schema::table('personalization_recommendation_strategies', function (Blueprint $table): void {
            $table->foreignId('published_version_id')->nullable()->after('status')
                ->constrained('personalization_strategy_versions', indexName: 'pers_strategies_published_version_fk')
                ->nullOnDelete();
        });

        Schema::table('personalization_recommendation_components', function (Blueprint $table): void {
            $table->foreignId('strategy_version_id')->nullable()->after('strategy_id')
                ->constrained('personalization_strategy_versions', indexName: 'pers_components_strategy_version_fk')
                ->nullOnDelete();
            $table->string('configuration_status', 24)->default('valid')->after('status');
            $table->index(['store_id', 'placement', 'status', 'configuration_status'], 'personalization_component_binding_state_index');
        });

        Schema::table('personalization_events', function (Blueprint $table): void {
            $table->foreignId('strategy_version_id')->nullable()->after('strategy_id')
                ->constrained('personalization_strategy_versions', indexName: 'pers_events_strategy_version_fk')
                ->nullOnDelete();
            $table->index(['strategy_version_id', 'event_name', 'occurred_at'], 'personalization_event_version_type_time_index');
        });

        Schema::table('personalization_attributions', function (Blueprint $table): void {
            $table->foreignId('strategy_version_id')->nullable()->after('strategy_id')
                ->constrained('personalization_strategy_versions', indexName: 'pers_attributions_strategy_version_fk')
                ->nullOnDelete();
            $table->index(['strategy_version_id', 'ordered_at'], 'personalization_attribution_version_time_index');
        });

        Schema::table('personalization_daily_metrics', function (Blueprint $table): void {
            $table->dropUnique('personalization_daily_metric_dimension_unique');
            $table->string('strategy_version_key', 64)->default('')->after('strategy_key');
            $table->unique(
                ['store_id', 'metric_date', 'placement', 'component_key', 'strategy_key', 'strategy_version_key', 'currency'],
                'personalization_daily_metric_version_dimension_unique',
            );
            $table->index(['store_id', 'strategy_version_key', 'metric_date'], 'personalization_daily_metric_version_date_index');
        });

        Schema::create('personalization_strategy_idempotencies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('operation', 40);
            $table->uuid('idempotency_key');
            $table->char('payload_hash', 64);
            $table->foreignId('strategy_id')->nullable()->constrained('personalization_recommendation_strategies')->cascadeOnDelete();
            $table->foreignId('strategy_version_id')->nullable()->constrained('personalization_strategy_versions')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['store_id', 'user_id', 'operation', 'idempotency_key'], 'personalization_strategy_idempotency_unique');
        });

        Schema::create('personalization_global_settings', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('default_locale', 16)->default('zh-CN');
            $table->json('copy')->nullable();
            $table->json('attribution');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'store_id'], 'personalization_global_setting_scope_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personalization_global_settings');
        Schema::dropIfExists('personalization_strategy_idempotencies');

        Schema::table('personalization_daily_metrics', function (Blueprint $table): void {
            $table->dropUnique('personalization_daily_metric_version_dimension_unique');
            $table->dropIndex('personalization_daily_metric_version_date_index');
            $table->dropColumn('strategy_version_key');
            $table->unique(
                ['store_id', 'metric_date', 'placement', 'component_key', 'strategy_key', 'currency'],
                'personalization_daily_metric_dimension_unique',
            );
        });
        Schema::table('personalization_attributions', function (Blueprint $table): void {
            $table->dropIndex('personalization_attribution_version_time_index');
            $table->dropConstrainedForeignId('strategy_version_id');
        });
        Schema::table('personalization_events', function (Blueprint $table): void {
            $table->dropIndex('personalization_event_version_type_time_index');
            $table->dropConstrainedForeignId('strategy_version_id');
        });
        Schema::table('personalization_recommendation_components', function (Blueprint $table): void {
            $table->dropIndex('personalization_component_binding_state_index');
            $table->dropConstrainedForeignId('strategy_version_id');
            $table->dropColumn('configuration_status');
        });
        Schema::table('personalization_recommendation_strategies', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('published_version_id');
        });
        Schema::dropIfExists('personalization_strategy_versions');
        Schema::table('personalization_recommendation_strategies', function (Blueprint $table): void {
            $table->dropIndex('personalization_strategy_store_status_updated_index');
            $table->dropIndex('personalization_strategy_recycle_index');
            $table->dropColumn(['status', 'archived_at', 'purge_after']);
        });
    }
};
