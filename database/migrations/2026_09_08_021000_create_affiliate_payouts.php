<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affiliate_payout_batches', function (Blueprint $t): void {
            $t->id();
            $t->char('public_id', 26)->unique();
            $t->foreignId('organization_id')->constrained()->restrictOnDelete();
            $t->foreignId('store_id')->constrained()->restrictOnDelete();
            $t->string('status', 24)->default('draft');
            $t->char('currency', 3);
            $t->bigInteger('total_minor');
            $t->timestamp('cutoff_at');
            $t->string('external_reference', 255)->nullable();
            $t->string('proof_path', 500)->nullable();
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('paid_at')->nullable();
            $t->timestamps();
            $t->index(['organization_id', 'store_id', 'status'], 'affiliate_payout_scope_status');
        });
        Schema::create('affiliate_payout_items', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('organization_id')->constrained()->restrictOnDelete();
            $t->foreignId('store_id')->constrained()->restrictOnDelete();
            $t->foreignId('batch_id')->constrained('affiliate_payout_batches')->restrictOnDelete();
            $t->foreignId('membership_id')->constrained('affiliate_program_memberships')->restrictOnDelete();
            $t->bigInteger('amount_minor');
            $t->string('status', 24)->default('reserved');
            $t->timestamps();
            $t->unique(['batch_id', 'membership_id'], 'affiliate_payout_member_unique');
        });
        Schema::create('affiliate_payout_allocations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('item_id')->constrained('affiliate_payout_items')->restrictOnDelete();
            $t->foreignId('ledger_entry_id')->constrained('affiliate_ledger_entries')->restrictOnDelete();
            $t->foreignId('active_entry_id')->nullable()->unique()->constrained('affiliate_ledger_entries')->restrictOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_payout_allocations');
        Schema::dropIfExists('affiliate_payout_items');
        Schema::dropIfExists('affiliate_payout_batches');
    }
};
