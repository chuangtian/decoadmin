<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_discount_evidence_deletions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('claim_id')->nullable()->constrained('student_discount_claims')->nullOnDelete();
            $table->string('disk', 40)->nullable();
            $table->text('path')->nullable();
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('last_error_code', 60)->nullable();
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(
                ['organization_id', 'store_id', 'status', 'created_at'],
                'student_evidence_deletion_scope_status_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_discount_evidence_deletions');
    }
};
