<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('affiliate_clicks', fn (Blueprint $t) => $t->index(['store_id', 'ip_hash', 'occurred_at'], 'affiliate_click_source_time'));
        Schema::table('affiliate_conversions', fn (Blueprint $t) => $t->index(['store_id', 'membership_id', 'ordered_at'], 'affiliate_member_order_time'));
    }

    public function down(): void
    {
        Schema::table('affiliate_clicks', fn (Blueprint $t) => $t->dropIndex('affiliate_click_source_time'));
        Schema::table('affiliate_conversions', fn (Blueprint $t) => $t->dropIndex('affiliate_member_order_time'));
    }
};
