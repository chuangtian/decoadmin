<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 30);
            $table->string('reference_no', 40)->unique();
            $table->foreignId('submitter_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 180);
            $table->text('description');
            $table->string('category', 60);
            $table->string('priority', 20)->nullable();
            $table->date('desired_date')->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->date('expense_date')->nullable();
            $table->string('status', 30)->default('pending_approval');
            $table->text('review_note')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'kind', 'status'], 'personal_requests_scope_status');
            $table->index(['organization_id', 'submitter_id', 'created_at'], 'personal_requests_submitter');
        });

        Schema::create('personal_request_attachments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('personal_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('disk', 40)->default('local');
            $table->string('path', 500);
            $table->string('original_name', 255);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('personal_request_progress_logs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('personal_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 30);
            $table->text('content')->nullable();
            $table->timestamps();

            $table->index(['personal_request_id', 'created_at'], 'personal_request_progress_timeline');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_request_progress_logs');
        Schema::dropIfExists('personal_request_attachments');
        Schema::dropIfExists('personal_requests');
    }
};
