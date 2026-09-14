<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('deco_review_groups')
            && ! Schema::hasIndex('deco_review_groups', ['organization_id', 'store_id', 'active'])) {
            Schema::table('deco_review_groups', function (Blueprint $table) {
                $table->index(['organization_id', 'store_id', 'active'], 'dr_groups_scope_active_idx');
            });
        }

        if (Schema::hasTable('deco_review_group_products')
            && ! Schema::hasIndex('deco_review_group_products', ['organization_id', 'store_id', 'group_id'])) {
            Schema::table('deco_review_group_products', function (Blueprint $table) {
                $table->index(['organization_id', 'store_id', 'group_id'], 'dr_group_products_scope_idx');
            });
        }
    }

    public function down(): void
    {
        // Corrective migration: the indexes are part of the base table schema.
    }
};
