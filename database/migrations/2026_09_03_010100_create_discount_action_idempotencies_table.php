<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discount_action_idempotencies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('operation', 40);
            $table->uuid('idempotency_key');
            $table->char('payload_hash', 64);
            $table->string('status', 20)->default('processing');
            $table->string('shopify_discount_id', 191)->nullable();
            $table->json('response')->nullable();
            $table->timestamps();
            $table->unique(['store_id', 'user_id', 'operation', 'idempotency_key'], 'discount_action_store_user_operation_key_unique');
            $table->index(['organization_id', 'store_id', 'created_at'], 'discount_action_scope_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discount_action_idempotencies');
    }
};
