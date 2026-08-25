<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('student_discount_claim_idempotencies')) {
            return;
        }

        Schema::create('student_discount_claim_idempotencies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('claim_id')->constrained('student_discount_claims')->cascadeOnDelete();
            $table->string('idempotency_key', 120);
            $table->char('request_fingerprint', 64);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['store_id', 'idempotency_key'], 'student_claim_request_idempotency_unique');
            $table->index(['claim_id', 'created_at'], 'student_claim_idempotency_claim_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_discount_claim_idempotencies');
    }
};
