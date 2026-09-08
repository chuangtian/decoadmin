<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affiliate_rewards', function (Blueprint $t) {
            $t->id();
            $t->char('public_id', 26)->unique();
            $t->foreignId('organization_id')->constrained()->restrictOnDelete();
            $t->foreignId('store_id')->constrained()->restrictOnDelete();
            $t->foreignId('membership_id')->constrained('affiliate_program_memberships')->restrictOnDelete();
            $t->foreignId('conversion_id')->nullable()->constrained('affiliate_conversions')->restrictOnDelete();
            $t->string('dedupe_key', 191);
            $t->unsignedInteger('threshold')->nullable();
            $t->string('customer_id');
            $t->string('code', 64);
            $t->json('rule_snapshot');
            $t->string('status', 24)->default('pending');
            $t->string('shopify_discount_id')->nullable();
            $t->timestamp('available_at');
            $t->timestamp('issued_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('last_synced_at')->nullable();
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->string('last_error')->nullable();
            $t->timestamps();
            $t->unique(['store_id', 'dedupe_key']);
            $t->unique(['store_id', 'code']);
            $t->index(['store_id', 'status', 'available_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_rewards');
    }
};
