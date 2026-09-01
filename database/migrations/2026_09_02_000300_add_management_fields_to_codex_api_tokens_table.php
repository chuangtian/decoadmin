<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('codex_api_tokens', function (Blueprint $table): void {
            $table->foreignId('issued_by')->nullable()->after('organization_id')->constrained('users')->nullOnDelete();
            $table->string('idempotency_key', 120)->nullable()->after('name');
            $table->char('idempotency_hash', 64)->nullable()->after('idempotency_key');
            $table->unique(
                ['organization_id', 'issued_by', 'idempotency_key'],
                'codex_tokens_org_issuer_idempotency_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('codex_api_tokens', function (Blueprint $table): void {
            $table->dropUnique('codex_tokens_org_issuer_idempotency_unique');
            $table->dropConstrainedForeignId('issued_by');
            $table->dropColumn(['idempotency_key', 'idempotency_hash']);
        });
    }
};
