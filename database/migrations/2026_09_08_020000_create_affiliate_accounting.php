<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affiliate_conversions', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->string('shopify_order_id', 100);
            $table->string('order_name', 100);
            $table->foreignId('membership_id')->nullable()->constrained('affiliate_program_memberships')->restrictOnDelete();
            $table->string('status', 32)->default('unattributed');
            $table->string('source', 32)->default('none');
            $table->string('reason', 100);
            $table->char('currency', 3);
            $table->boolean('is_test')->default(false);
            $table->unsignedBigInteger('base_minor')->default(0);
            $table->unsignedBigInteger('refunded_base_minor')->default(0);
            $table->unsignedBigInteger('commission_minor')->default(0);
            $table->unsignedBigInteger('reversed_minor')->default(0);
            $table->json('rule_snapshot');
            $table->json('attribution_snapshot');
            $table->json('order_snapshot');
            $table->timestamp('ordered_at');
            $table->timestamp('available_at')->nullable()->index();
            $table->timestamp('shopify_updated_at');
            $table->timestamps();
            $table->unique(['store_id', 'shopify_order_id'], 'affiliate_conversion_order_unique');
            $table->index(['organization_id', 'store_id', 'status'], 'affiliate_conversion_scope_status');
        });
        Schema::create('affiliate_conversion_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('conversion_id')->constrained('affiliate_conversions')->restrictOnDelete();
            $table->string('shopify_line_id', 100);
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('base_minor');
            $table->unsignedBigInteger('commission_minor');
            $table->json('rule_snapshot');
            $table->timestamps();
            $table->unique(['conversion_id', 'shopify_line_id'], 'affiliate_conversion_line_unique');
        });
        Schema::create('affiliate_ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('membership_id')->constrained('affiliate_program_memberships')->restrictOnDelete();
            $table->foreignId('conversion_id')->nullable()->constrained('affiliate_conversions')->restrictOnDelete();
            $table->string('idempotency_key', 191);
            $table->string('type', 32);
            $table->string('status', 24)->default('pending');
            $table->char('currency', 3);
            $table->bigInteger('amount_minor');
            $table->timestamp('available_at')->nullable();
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['store_id', 'idempotency_key'], 'affiliate_ledger_idempotency');
            $table->index(['store_id', 'membership_id', 'currency', 'status'], 'affiliate_ledger_balance');
        });
        Schema::create('affiliate_refund_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('conversion_id')->constrained('affiliate_conversions')->restrictOnDelete();
            $table->string('shopify_refund_id', 100);
            $table->json('line_snapshot');
            $table->unsignedBigInteger('adjustment_minor')->default(0);
            $table->timestamp('refunded_at');
            $table->timestamps();
            $table->unique(['store_id', 'shopify_refund_id'], 'affiliate_refund_unique');
        });
        Schema::create('affiliate_risk_flags', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('conversion_id')->constrained('affiliate_conversions')->restrictOnDelete();
            $table->string('rule', 100);
            $table->string('status', 24)->default('open');
            $table->json('evidence');
            $table->text('review_reason')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['conversion_id', 'rule'], 'affiliate_risk_rule_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_risk_flags');
        Schema::dropIfExists('affiliate_refund_records');
        Schema::dropIfExists('affiliate_ledger_entries');
        Schema::dropIfExists('affiliate_conversion_lines');
        Schema::dropIfExists('affiliate_conversions');
    }
};
