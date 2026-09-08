<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affiliate_links', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('membership_id')->unique()->constrained('affiliate_program_memberships')->cascadeOnDelete();
            $table->string('referral_code', 64);
            $table->string('normalized_referral_code', 64);
            $table->string('target_path', 1024)->default('/');
            $table->string('status', 24)->default('active');
            $table->json('utm')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['store_id', 'normalized_referral_code'], 'affiliate_link_store_code_unique');
            $table->index(['store_id', 'membership_id', 'status'], 'affiliate_link_membership_status_index');
        });

        Schema::create('affiliate_coupons', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('membership_id')->unique()->constrained('affiliate_program_memberships')->cascadeOnDelete();
            $table->string('code', 64);
            $table->string('normalized_code', 64);
            $table->string('shopify_discount_id', 255)->nullable();
            $table->string('shopify_code_id', 255)->nullable();
            $table->string('status', 24)->default('provisioning');
            $table->text('last_error')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['store_id', 'normalized_code'], 'affiliate_coupon_store_code_unique');
            $table->index(['store_id', 'membership_id', 'status'], 'affiliate_coupon_membership_status_index');
        });

        Schema::create('affiliate_clicks', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('link_id')->constrained('affiliate_links')->cascadeOnDelete();
            $table->foreignId('membership_id')->constrained('affiliate_program_memberships')->cascadeOnDelete();
            $table->char('visitor_token', 64);
            $table->string('referrer_host', 255)->nullable();
            $table->char('ip_hash', 64)->nullable();
            $table->char('ua_hash', 64)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['store_id', 'visitor_token', 'occurred_at'], 'affiliate_click_visitor_time_index');
            $table->index(['link_id', 'occurred_at'], 'affiliate_click_link_time_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_clicks');
        Schema::dropIfExists('affiliate_coupons');
        Schema::dropIfExists('affiliate_links');
    }
};
