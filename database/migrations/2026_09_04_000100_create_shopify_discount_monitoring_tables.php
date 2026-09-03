<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopify_discount_monitors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('shopify_discount_id', 100);
            $table->boolean('is_enabled')->default(true);
            $table->boolean('baseline_pending')->default(true);
            $table->json('countdown_state')->nullable();
            $table->string('last_snapshot_hash', 64)->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['store_id', 'shopify_discount_id'], 'discount_monitors_store_shopify_unique');
            $table->index(['is_enabled', 'store_id'], 'discount_monitors_enabled_store_index');
            $table->index(['organization_id', 'store_id'], 'discount_monitors_scope_index');
        });

        Schema::create('shopify_discount_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('monitor_id')->constrained('shopify_discount_monitors')->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('shopify_discount_id', 100);
            $table->string('content_hash', 64);
            $table->json('snapshot');
            $table->timestamp('captured_at');
            $table->timestamps();

            $table->unique(['monitor_id', 'content_hash'], 'discount_snapshots_monitor_hash_unique');
            $table->index(['store_id', 'captured_at'], 'discount_snapshots_store_time_index');
        });

        Schema::table('store_notification_settings', function (Blueprint $table): void {
            $table->boolean('notify_discount_monitor')->default(true)->after('notify_connection_unhealthy');
        });
    }

    public function down(): void
    {
        Schema::table('store_notification_settings', function (Blueprint $table): void {
            $table->dropColumn('notify_discount_monitor');
        });
        Schema::dropIfExists('shopify_discount_snapshots');
        Schema::dropIfExists('shopify_discount_monitors');
    }
};
