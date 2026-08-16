<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('webhook_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shopify_connection_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('app_id')->nullable()->constrained()->nullOnDelete();
            $table->string('topic', 160);
            $table->string('api_version', 20)->nullable();
            $table->json('headers')->nullable();
            $table->json('payload');
            $table->string('status', 30)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('received_at')->useCurrent();
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'topic', 'received_at'], 'webhooks_store_topic_received_index');
            $table->index(['status', 'next_retry_at'], 'webhooks_status_retry_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
