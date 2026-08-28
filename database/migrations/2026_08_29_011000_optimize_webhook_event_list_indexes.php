<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_events', function (Blueprint $table): void {
            $table->index(['store_id', 'received_at'], 'webhooks_store_received_index');
            $table->index(
                ['organization_id', 'store_id', 'received_at'],
                'webhooks_scope_received_index',
            );
            $table->index(
                ['organization_id', 'store_id', 'status', 'received_at'],
                'webhooks_scope_status_received_index',
            );
        });

        if (Schema::hasIndex('webhook_events', 'webhooks_store_topic_received_index')) {
            Schema::table('webhook_events', function (Blueprint $table): void {
                $table->dropIndex('webhooks_store_topic_received_index');
            });
        }
    }

    public function down(): void
    {
        Schema::table('webhook_events', function (Blueprint $table): void {
            $table->index(['store_id', 'topic', 'received_at'], 'webhooks_store_topic_received_index');
        });
        Schema::table('webhook_events', function (Blueprint $table): void {
            $table->dropIndex('webhooks_scope_status_received_index');
            $table->dropIndex('webhooks_scope_received_index');
            $table->dropIndex('webhooks_store_received_index');
        });
    }
};
