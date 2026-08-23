<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_ad_accounts', function (Blueprint $table): void {
            $table->id();
            $this->tenantColumns($table);
            $table->string('meta_account_id', 64);
            $table->string('name', 255)->nullable();
            $table->unsignedSmallInteger('account_status')->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('timezone_name', 100)->nullable();
            $table->decimal('timezone_offset_hours_utc', 6, 2)->nullable();
            $table->string('business_name', 255)->nullable();
            $table->unsignedSmallInteger('disable_reason')->nullable();
            $table->decimal('spend_cap', 24, 4)->nullable();
            $table->decimal('amount_spent', 24, 4)->nullable();
            $table->decimal('balance', 24, 4)->nullable();
            $table->json('raw_payload');
            $table->timestamp('last_seen_at');
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->unique(
                ['organization_id', 'store_id', 'meta_account_id'],
                'meta_accounts_scope_external_unique',
            );
            $table->unique(
                ['id', 'organization_id', 'store_id'],
                'meta_accounts_id_scope_unique',
            );
            $table->index(
                ['organization_id', 'store_id', 'account_status'],
                'meta_accounts_scope_status_index',
            );
        });

        Schema::create('meta_ad_campaigns', function (Blueprint $table): void {
            $table->id();
            $this->tenantAccountColumns($table);
            $table->string('meta_campaign_id', 64);
            $table->string('name', 500)->nullable();
            $table->string('status', 64)->nullable();
            $table->string('effective_status', 64)->nullable();
            $table->string('objective', 100)->nullable();
            $table->string('buying_type', 64)->nullable();
            $table->decimal('daily_budget', 24, 4)->nullable();
            $table->decimal('lifetime_budget', 24, 4)->nullable();
            $table->decimal('budget_remaining', 24, 4)->nullable();
            $table->timestamp('start_time')->nullable();
            $table->timestamp('stop_time')->nullable();
            $table->timestamp('source_created_at')->nullable();
            $table->timestamp('source_updated_at')->nullable();
            $table->json('special_ad_categories')->nullable();
            $table->json('raw_payload');
            $table->timestamp('last_seen_at');
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->unique(
                ['organization_id', 'store_id', 'meta_campaign_id'],
                'meta_campaigns_scope_external_unique',
            );
            $table->index(
                ['organization_id', 'store_id', 'effective_status'],
                'meta_campaigns_scope_status_index',
            );
        });

        Schema::create('meta_ad_sets', function (Blueprint $table): void {
            $table->id();
            $this->tenantAccountColumns($table);
            $table->string('meta_ad_set_id', 64);
            $table->string('meta_campaign_id', 64)->nullable();
            $table->string('name', 500)->nullable();
            $table->string('status', 64)->nullable();
            $table->string('effective_status', 64)->nullable();
            $table->string('optimization_goal', 100)->nullable();
            $table->string('billing_event', 100)->nullable();
            $table->string('bid_strategy', 100)->nullable();
            $table->decimal('daily_budget', 24, 4)->nullable();
            $table->decimal('lifetime_budget', 24, 4)->nullable();
            $table->decimal('budget_remaining', 24, 4)->nullable();
            $table->timestamp('start_time')->nullable();
            $table->timestamp('end_time')->nullable();
            $table->timestamp('source_created_at')->nullable();
            $table->timestamp('source_updated_at')->nullable();
            $table->json('targeting')->nullable();
            $table->json('promoted_object')->nullable();
            $table->json('raw_payload');
            $table->timestamp('last_seen_at');
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->unique(
                ['organization_id', 'store_id', 'meta_ad_set_id'],
                'meta_ad_sets_scope_external_unique',
            );
            $table->index(
                ['organization_id', 'store_id', 'meta_campaign_id'],
                'meta_ad_sets_scope_campaign_index',
            );
        });

        Schema::create('meta_ad_creatives', function (Blueprint $table): void {
            $table->id();
            $this->tenantAccountColumns($table);
            $table->string('meta_creative_id', 64);
            $table->string('name', 500)->nullable();
            $table->text('title')->nullable();
            $table->longText('body')->nullable();
            $table->string('call_to_action_type', 100)->nullable();
            $table->string('object_story_id', 128)->nullable();
            $table->text('image_url')->nullable();
            $table->text('thumbnail_url')->nullable();
            $table->text('url_tags')->nullable();
            $table->json('asset_feed_spec')->nullable();
            $table->json('object_story_spec')->nullable();
            $table->json('raw_payload');
            $table->timestamp('last_seen_at');
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->unique(
                ['organization_id', 'store_id', 'meta_creative_id'],
                'meta_creatives_scope_external_unique',
            );
        });

        Schema::create('meta_ads', function (Blueprint $table): void {
            $table->id();
            $this->tenantAccountColumns($table);
            $table->string('meta_ad_id', 64);
            $table->string('meta_campaign_id', 64)->nullable();
            $table->string('meta_ad_set_id', 64)->nullable();
            $table->string('meta_creative_id', 64)->nullable();
            $table->string('name', 500)->nullable();
            $table->string('status', 64)->nullable();
            $table->string('effective_status', 64)->nullable();
            $table->json('tracking_specs')->nullable();
            $table->json('conversion_specs')->nullable();
            $table->timestamp('source_created_at')->nullable();
            $table->timestamp('source_updated_at')->nullable();
            $table->json('raw_payload');
            $table->timestamp('last_seen_at');
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->unique(
                ['organization_id', 'store_id', 'meta_ad_id'],
                'meta_ads_scope_external_unique',
            );
            $table->index(
                ['organization_id', 'store_id', 'meta_campaign_id', 'meta_ad_set_id'],
                'meta_ads_scope_hierarchy_index',
            );
        });

        Schema::create('meta_ad_insights', function (Blueprint $table): void {
            $table->id();
            $this->tenantAccountColumns($table);
            $table->string('level', 16);
            $table->string('entity_id', 64);
            $table->string('account_external_id', 64);
            $table->string('account_name', 255)->nullable();
            $table->string('meta_campaign_id', 64)->nullable();
            $table->string('campaign_name', 500)->nullable();
            $table->string('meta_ad_set_id', 64)->nullable();
            $table->string('ad_set_name', 500)->nullable();
            $table->string('meta_ad_id', 64)->nullable();
            $table->string('ad_name', 500)->nullable();
            $table->date('date_start');
            $table->date('date_stop');
            $table->decimal('spend', 24, 6)->default(0);
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('reach')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->unsignedBigInteger('unique_clicks')->default(0);
            $table->unsignedBigInteger('inline_link_clicks')->default(0);
            $table->decimal('ctr', 16, 6)->nullable();
            $table->decimal('unique_ctr', 16, 6)->nullable();
            $table->decimal('cpc', 24, 6)->nullable();
            $table->decimal('cpm', 24, 6)->nullable();
            $table->decimal('cpp', 24, 6)->nullable();
            $table->decimal('frequency', 16, 6)->nullable();
            $table->decimal('purchases', 24, 6)->default(0);
            $table->decimal('purchase_value', 24, 6)->default(0);
            $table->decimal('add_to_cart', 24, 6)->default(0);
            $table->decimal('initiate_checkout', 24, 6)->default(0);
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
            $table->json('raw_payload');
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->unique(
                ['organization_id', 'store_id', 'level', 'entity_id', 'date_start', 'date_stop'],
                'meta_insights_scope_entity_period_unique',
            );
            $table->index(
                ['organization_id', 'store_id', 'date_start', 'level'],
                'meta_insights_scope_period_level_index',
            );
            $table->index(
                ['organization_id', 'store_id', 'meta_campaign_id', 'date_start'],
                'meta_insights_scope_campaign_period_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_ad_insights');
        Schema::dropIfExists('meta_ads');
        Schema::dropIfExists('meta_ad_creatives');
        Schema::dropIfExists('meta_ad_sets');
        Schema::dropIfExists('meta_ad_campaigns');
        Schema::dropIfExists('meta_ad_accounts');
    }

    private function tenantColumns(Blueprint $table): void
    {
        $table->unsignedBigInteger('organization_id');
        $table->unsignedBigInteger('store_id');

        $table->foreign('organization_id')
            ->references('id')
            ->on('organizations')
            ->cascadeOnDelete();
        $table->foreign(['store_id', 'organization_id'], 'meta_tenant_store_org_fk_'.substr($table->getTable(), -12))
            ->references(['id', 'organization_id'])
            ->on('stores')
            ->cascadeOnDelete();
    }

    private function tenantAccountColumns(Blueprint $table): void
    {
        $table->unsignedBigInteger('organization_id');
        $table->unsignedBigInteger('store_id');
        $table->unsignedBigInteger('meta_ad_account_id');

        $table->foreign(['meta_ad_account_id', 'organization_id', 'store_id'], 'meta_account_scope_fk_'.substr($table->getTable(), -12))
            ->references(['id', 'organization_id', 'store_id'])
            ->on('meta_ad_accounts')
            ->cascadeOnDelete();
    }
};
