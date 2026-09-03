<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('codex_action_confirmations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('codex_api_token_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('action', 80);
            $table->string('idempotency_key', 120);
            $table->char('payload_hash', 64);
            $table->json('payload');
            $table->string('summary', 500);
            $table->string('status', 30)->default('pending')->index();
            $table->json('result')->nullable();
            $table->string('error_code', 80)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['codex_api_token_id', 'action', 'idempotency_key'],
                'codex_confirmations_token_action_idempotency_unique',
            );
            $table->index(
                ['organization_id', 'store_id', 'status', 'expires_at'],
                'codex_confirmations_scope_status_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('codex_action_confirmations');
    }
};
