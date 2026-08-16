<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oauth_states', function (Blueprint $table) {
            $table->id();
            $table->char('state_hash', 64)->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('app_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('shop_domain', 255);
            $table->string('redirect_uri', 2048);
            $table->string('intended_url', 2048)->nullable();
            $table->json('scopes')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable()->index();
            $table->timestamps();

            $table->index(['shop_domain', 'expires_at'], 'oauth_states_shop_expiry_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_states');
    }
};
