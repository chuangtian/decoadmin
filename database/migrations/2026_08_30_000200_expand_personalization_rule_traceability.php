<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personalization_strategy_product_overrides', function (Blueprint $table): void {
            $table->string('shopify_product_gid', 255)->nullable()->after('shopify_product_id');
            $table->string('shopify_variant_gid', 255)->nullable()->after('shopify_product_gid');
            $table->timestamp('selected_at')->nullable()->after('minimum_quantity');
        });

        Schema::table('personalization_event_products', function (Blueprint $table): void {
            $table->uuid('rule_id')->nullable()->after('rank');
            $table->index(['event_id', 'rule_id'], 'personalization_event_product_rule_index');
        });
    }

    public function down(): void
    {
        Schema::table('personalization_event_products', function (Blueprint $table): void {
            $table->dropIndex('personalization_event_product_rule_index');
            $table->dropColumn('rule_id');
        });

        Schema::table('personalization_strategy_product_overrides', function (Blueprint $table): void {
            $table->dropColumn(['shopify_product_gid', 'shopify_variant_gid', 'selected_at']);
        });
    }
};
