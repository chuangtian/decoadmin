<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reputation_mentions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('source', 32);
            $table->char('canonical_key', 64);
            $table->json('source_sheets')->nullable();
            $table->longText('source_payloads_encrypted')->nullable();
            $table->text('url')->nullable();
            $table->char('url_hash', 64)->nullable();
            $table->text('title')->nullable();
            $table->longText('content')->nullable();
            $table->decimal('rating', 3, 1)->nullable();
            $table->unsignedSmallInteger('week_number')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->string('model_name', 120)->nullable();
            $table->longText('order_reference_encrypted')->nullable();
            $table->string('processing_status', 48)->nullable();
            $table->json('metrics')->nullable();
            $table->boolean('is_negative')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->unique(['store_id', 'source', 'canonical_key'], 'rep_mentions_store_source_key_unique');
            $table->index(['organization_id', 'store_id', 'published_at'], 'rep_mentions_scope_date_index');
            $table->index(['store_id', 'source', 'is_active'], 'rep_mentions_store_source_active_index');
            $table->index(['store_id', 'is_negative', 'is_active'], 'rep_mentions_store_negative_index');
        });

        Schema::create('reputation_goals', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->date('month');
            $table->string('metric', 40);
            $table->decimal('target_value', 18, 4);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['store_id', 'month', 'metric'], 'rep_goals_store_month_metric_unique');
            $table->index(['organization_id', 'store_id', 'month'], 'rep_goals_scope_month_index');
        });

        Schema::create('reputation_risks', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reputation_mention_id')->nullable()->constrained('reputation_mentions')->nullOnDelete();
            $table->string('origin', 24)->default('manual');
            $table->char('source_key', 64)->nullable();
            $table->longText('description');
            $table->string('severity', 24)->default('medium');
            $table->string('source', 32)->default('multiple');
            $table->longText('recommended_action')->nullable();
            $table->string('status', 24)->default('pending');
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['store_id', 'source_key'], 'rep_risks_store_source_key_unique');
            $table->index(['organization_id', 'store_id', 'status'], 'rep_risks_scope_status_index');
            $table->index(['store_id', 'severity', 'occurred_at'], 'rep_risks_store_severity_date_index');
        });

        Schema::create('reputation_resource_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->longText('description');
            $table->string('request_type', 32);
            $table->string('priority', 24)->default('normal');
            $table->string('owner_name', 120)->nullable();
            $table->string('status', 24)->default('pending');
            $table->date('due_date')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'store_id', 'status'], 'rep_resources_scope_status_index');
            $table->index(['store_id', 'priority', 'created_at'], 'rep_resources_store_priority_index');
        });

        Schema::create('reputation_sync_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 24)->default('scheduled');
            $table->string('status', 24)->default('queued');
            $table->unsignedSmallInteger('progress_percent')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->json('result')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'store_id', 'created_at'], 'rep_sync_scope_created_index');
            $table->index(['store_id', 'status'], 'rep_sync_store_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reputation_sync_runs');
        Schema::dropIfExists('reputation_resource_requests');
        Schema::dropIfExists('reputation_risks');
        Schema::dropIfExists('reputation_goals');
        Schema::dropIfExists('reputation_mentions');
    }
};
