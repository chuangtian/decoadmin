<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deco_review_rewards', function (Blueprint $table) {
            $table->string('redeemed_order_id')->nullable()->after('issued_at');
            $table->timestamp('redeemed_at')->nullable()->after('redeemed_order_id');
            $table->timestamp('refunded_at')->nullable()->after('redeemed_at');
            $table->timestamp('cancelled_order_at')->nullable()->after('refunded_at');
            $table->index(['organization_id', 'store_id', 'redeemed_order_id'], 'deco_reward_redemption_order');
        });

        Schema::create('deco_review_webhook_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('webhook_id', 128);
            $table->string('topic', 64);
            $table->string('payload_hash', 64);
            $table->timestamp('processed_at');
            $table->unique(['store_id', 'webhook_id'], 'deco_review_webhook_store_id');
            $table->index(['organization_id', 'store_id', 'topic', 'processed_at'], 'deco_review_webhook_scope');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deco_review_webhook_receipts');
        Schema::table('deco_review_rewards', function (Blueprint $table) {
            $table->dropIndex('deco_reward_redemption_order');
            $table->dropColumn(['redeemed_order_id', 'redeemed_at', 'refunded_at', 'cancelled_order_at']);
        });
    }
};
