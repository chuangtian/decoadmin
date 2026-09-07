<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('codex_oauth_clients', function (Blueprint $table): void {
            $table->id();
            $table->string('client_id', 120)->unique();
            $table->string('client_name', 160);
            $table->json('redirect_uris');
            $table->json('grant_types');
            $table->json('response_types');
            $table->string('token_endpoint_auth_method', 32)->default('none');
            $table->char('registration_fingerprint', 64)->unique();
            $table->timestamps();
        });

        Schema::create('codex_oauth_authorization_codes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('codex_oauth_client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->char('code_hash', 64)->unique();
            $table->string('redirect_uri', 1024);
            $table->json('abilities');
            $table->string('code_challenge', 128);
            $table->string('resource', 1024);
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['codex_oauth_client_id', 'expires_at'], 'codex_oauth_codes_client_expiry_index');
        });

        Schema::create('codex_oauth_refresh_tokens', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('codex_oauth_client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64);
            $table->json('abilities');
            $table->string('resource', 1024);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'organization_id', 'revoked_at'], 'codex_oauth_refresh_user_org_index');
        });

        Schema::table('codex_api_tokens', function (Blueprint $table): void {
            $table->string('source', 32)->default('manual')->after('name');
            $table->foreignId('codex_oauth_refresh_token_id')
                ->nullable()
                ->after('source')
                ->constrained()
                ->nullOnDelete();
            $table->index(['codex_oauth_refresh_token_id', 'revoked_at'], 'codex_tokens_oauth_refresh_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('codex_api_tokens', function (Blueprint $table): void {
            $table->dropIndex('codex_tokens_oauth_refresh_status_index');
            $table->dropConstrainedForeignId('codex_oauth_refresh_token_id');
            $table->dropColumn('source');
        });

        Schema::dropIfExists('codex_oauth_refresh_tokens');
        Schema::dropIfExists('codex_oauth_authorization_codes');
        Schema::dropIfExists('codex_oauth_clients');
    }
};
