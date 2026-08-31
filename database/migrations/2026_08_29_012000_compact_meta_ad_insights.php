<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('meta_ad_insight_entities')) {
            Schema::create('meta_ad_insight_entities', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
                $table->foreignId('store_id')->constrained()->cascadeOnDelete();
                $table->string('level', 16);
                $table->string('entity_id', 64);
                $table->string('account_name', 255)->nullable();
                $table->string('meta_campaign_id', 64)->nullable();
                $table->string('campaign_name', 500)->nullable();
                $table->string('meta_ad_set_id', 64)->nullable();
                $table->string('ad_set_name', 500)->nullable();
                $table->string('meta_ad_id', 64)->nullable();
                $table->string('ad_name', 500)->nullable();
                $table->timestamps();

                $table->unique(
                    ['organization_id', 'store_id', 'level', 'entity_id'],
                    'meta_insight_entities_scope_entity_unique',
                );
                $table->index(
                    ['organization_id', 'store_id', 'meta_ad_id'],
                    'meta_insight_entities_scope_ad_index',
                );
            });
        }

        $this->backfillEntities();
        DB::table('meta_ad_insights')->where('granularity', 'hour')->delete();

        $hasOldWindowUnique = Schema::hasIndex('meta_ad_insights', 'meta_insights_scope_entity_window_unique');
        $hasOldHourIndex = Schema::hasIndex('meta_ad_insights', 'meta_insights_scope_hour_level_index');
        $hasNewGranularityUnique = Schema::hasIndex('meta_ad_insights', 'meta_insights_scope_entity_granularity_unique');
        if ($hasOldWindowUnique || $hasOldHourIndex || ! $hasNewGranularityUnique) {
            Schema::table('meta_ad_insights', function (Blueprint $table) use ($hasNewGranularityUnique, $hasOldHourIndex, $hasOldWindowUnique): void {
                if ($hasOldHourIndex) {
                    $table->dropIndex('meta_insights_scope_hour_level_index');
                }
                if ($hasOldWindowUnique) {
                    $table->dropUnique('meta_insights_scope_entity_window_unique');
                }
                if (! $hasNewGranularityUnique) {
                    $table->unique(
                        ['organization_id', 'store_id', 'level', 'entity_id', 'date_start', 'date_stop', 'granularity'],
                        'meta_insights_scope_entity_granularity_unique',
                    );
                }
            });
        }

        $obsoleteColumns = collect([
            'account_name', 'campaign_name', 'ad_set_name', 'ad_name',
            'hourly_range', 'hour_start_at', 'hour_end_at',
            'unique_clicks', 'ctr', 'unique_ctr', 'cpc', 'cpm', 'cpp',
            'leads', 'landing_page_views', 'cost_per_purchase', 'purchase_roas',
            'outbound_clicks', 'actions', 'action_values', 'cost_per_action_type',
            'purchase_roas_breakdown', 'website_purchase_roas', 'raw_payload',
        ])->filter(fn (string $column): bool => Schema::hasColumn('meta_ad_insights', $column))->all();
        if ($obsoleteColumns !== []) {
            Schema::table('meta_ad_insights', function (Blueprint $table) use ($obsoleteColumns): void {
                $table->dropColumn($obsoleteColumns);
            });
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE meta_ad_insights FORCE');
        }
    }

    public function down(): void
    {
        Schema::table('meta_ad_insights', function (Blueprint $table): void {
            $table->string('account_name', 255)->nullable();
            $table->string('campaign_name', 500)->nullable();
            $table->string('ad_set_name', 500)->nullable();
            $table->string('ad_name', 500)->nullable();
            $table->string('hourly_range', 32)->default('');
            $table->timestamp('hour_start_at')->nullable();
            $table->timestamp('hour_end_at')->nullable();
            $table->unsignedBigInteger('unique_clicks')->default(0);
            $table->decimal('ctr', 16, 6)->nullable();
            $table->decimal('unique_ctr', 16, 6)->nullable();
            $table->decimal('cpc', 24, 6)->nullable();
            $table->decimal('cpm', 24, 6)->nullable();
            $table->decimal('cpp', 24, 6)->nullable();
            $table->decimal('leads', 24, 6)->default(0);
            $table->decimal('landing_page_views', 24, 6)->default(0);
            $table->decimal('cost_per_purchase', 24, 6)->nullable();
            $table->decimal('purchase_roas', 24, 6)->nullable();
            $table->json('outbound_clicks')->nullable();
            $table->json('actions')->nullable();
            $table->json('action_values')->nullable();
            $table->json('cost_per_action_type')->nullable();
            $table->json('purchase_roas_breakdown')->nullable();
            $table->json('website_purchase_roas')->nullable();
            $table->json('raw_payload')->nullable();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement(<<<'SQL'
                UPDATE meta_ad_insights insight
                INNER JOIN meta_ad_insight_entities entity
                    ON entity.organization_id = insight.organization_id
                   AND entity.store_id = insight.store_id
                   AND entity.level = insight.level
                   AND entity.entity_id = insight.entity_id
                SET insight.account_name = entity.account_name,
                    insight.campaign_name = entity.campaign_name,
                    insight.ad_set_name = entity.ad_set_name,
                    insight.ad_name = entity.ad_name,
                    insight.raw_payload = JSON_OBJECT()
                SQL);
        }

        Schema::table('meta_ad_insights', function (Blueprint $table): void {
            $table->dropUnique('meta_insights_scope_entity_granularity_unique');
            $table->unique(
                [
                    'organization_id', 'store_id', 'level', 'entity_id', 'date_start', 'date_stop',
                    'granularity', 'hourly_range',
                ],
                'meta_insights_scope_entity_window_unique',
            );
            $table->index(
                ['organization_id', 'store_id', 'hour_start_at', 'level'],
                'meta_insights_scope_hour_level_index',
            );
        });

        Schema::dropIfExists('meta_ad_insight_entities');
    }

    private function backfillEntities(): void
    {
        if (! Schema::hasColumn('meta_ad_insights', 'account_name')) {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement(<<<'SQL'
                INSERT INTO meta_ad_insight_entities
                    (organization_id, store_id, level, entity_id, account_name,
                     meta_campaign_id, campaign_name, meta_ad_set_id, ad_set_name,
                     meta_ad_id, ad_name, created_at, updated_at)
                SELECT organization_id, store_id, level, entity_id,
                       MAX(account_name), MAX(meta_campaign_id), MAX(campaign_name),
                       MAX(meta_ad_set_id), MAX(ad_set_name), MAX(meta_ad_id), MAX(ad_name),
                       CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                FROM meta_ad_insights
                GROUP BY organization_id, store_id, level, entity_id
                ON DUPLICATE KEY UPDATE
                    account_name = VALUES(account_name),
                    meta_campaign_id = VALUES(meta_campaign_id),
                    campaign_name = VALUES(campaign_name),
                    meta_ad_set_id = VALUES(meta_ad_set_id),
                    ad_set_name = VALUES(ad_set_name),
                    meta_ad_id = VALUES(meta_ad_id),
                    ad_name = VALUES(ad_name),
                    updated_at = VALUES(updated_at)
                SQL);

            return;
        }

        DB::table('meta_ad_insights')->orderBy('id')->chunkById(1000, function ($rows): void {
            foreach ($rows as $row) {
                DB::table('meta_ad_insight_entities')->updateOrInsert(
                    [
                        'organization_id' => $row->organization_id,
                        'store_id' => $row->store_id,
                        'level' => $row->level,
                        'entity_id' => $row->entity_id,
                    ],
                    [
                        'account_name' => $row->account_name,
                        'meta_campaign_id' => $row->meta_campaign_id,
                        'campaign_name' => $row->campaign_name,
                        'meta_ad_set_id' => $row->meta_ad_set_id,
                        'ad_set_name' => $row->ad_set_name,
                        'meta_ad_id' => $row->meta_ad_id,
                        'ad_name' => $row->ad_name,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );
            }
        });
    }
};
