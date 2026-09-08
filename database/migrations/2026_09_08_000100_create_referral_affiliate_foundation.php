<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affiliate_store_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('affiliate_enabled')->default(false)->index();
            $table->boolean('customer_referral_enabled')->default(false)->index();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'store_id'], 'affiliate_settings_org_store_unique');
        });

        Schema::create('affiliate_programs', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('type', 32)->default('affiliate');
            $table->string('status', 24)->default('draft');
            $table->string('attribution_model', 32)->default('coupon_wins');
            $table->unsignedSmallInteger('attribution_window_days')->default(30);
            $table->unsignedSmallInteger('hold_days')->default(30);
            $table->char('currency', 3);
            $table->json('settings')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['organization_id', 'store_id', 'status'], 'affiliate_program_scope_status_index');
        });

        Schema::create('affiliate_program_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('program_id')->constrained('affiliate_programs')->cascadeOnDelete();
            $table->string('scope', 24)->default('program');
            $table->string('scope_reference', 255)->default('*');
            $table->string('commission_type', 24);
            $table->unsignedInteger('rate_basis_points')->nullable();
            $table->unsignedBigInteger('amount_minor')->nullable();
            $table->unsignedSmallInteger('priority')->default(100);
            $table->boolean('enabled')->default(true);
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->index(['program_id', 'enabled', 'priority'], 'affiliate_program_rule_priority_index');
            $table->unique(['program_id', 'scope', 'scope_reference', 'priority'], 'affiliate_program_rule_unique');
        });

        Schema::create('affiliate_promoters', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->text('email_encrypted');
            $table->char('email_hash', 64);
            $table->string('display_name', 120);
            $table->string('type', 32)->default('affiliate');
            $table->string('status', 24)->default('active');
            $table->json('tags')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['organization_id', 'email_hash'], 'affiliate_promoter_org_email_unique');
            $table->index(['organization_id', 'status'], 'affiliate_promoter_org_status_index');
        });

        Schema::create('affiliate_program_memberships', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('program_id')->constrained('affiliate_programs')->cascadeOnDelete();
            $table->foreignId('promoter_id')->constrained('affiliate_promoters')->cascadeOnDelete();
            $table->string('status', 24)->default('pending');
            $table->string('tier_key', 64)->nullable();
            $table->string('shopify_customer_id', 255)->nullable();
            $table->json('commission_override')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamps();
            $table->unique(['program_id', 'promoter_id'], 'affiliate_membership_program_promoter_unique');
            $table->index(['organization_id', 'store_id', 'status'], 'affiliate_membership_scope_status_index');
            $table->index(['store_id', 'shopify_customer_id'], 'affiliate_membership_store_customer_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_program_memberships');
        Schema::dropIfExists('affiliate_promoters');
        Schema::dropIfExists('affiliate_program_rules');
        Schema::dropIfExists('affiliate_programs');
        Schema::dropIfExists('affiliate_store_settings');
    }
};
