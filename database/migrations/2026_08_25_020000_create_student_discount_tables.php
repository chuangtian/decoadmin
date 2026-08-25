<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_discount_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(false)->index();
            $table->string('code_prefix', 24)->default('STUDENT');
            $table->string('discount_type', 20)->default('percentage');
            $table->decimal('discount_value', 12, 2)->default(10);
            $table->string('applies_to', 20)->default('all');
            $table->json('target_ids')->nullable();
            $table->boolean('combines_with_order_discounts')->default(false);
            $table->boolean('combines_with_product_discounts')->default(false);
            $table->boolean('combines_with_shipping_discounts')->default(false);
            $table->unsignedInteger('usage_limit')->default(1);
            $table->unsignedSmallInteger('validity_days')->default(7);
            $table->json('education_email_domains')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'store_id'], 'student_discount_campaign_org_store_unique');
        });

        Schema::create('student_discount_claims', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('email', 320);
            $table->string('normalized_email', 320);
            $table->string('source', 20);
            $table->string('status', 20)->default('pending');
            $table->string('review_method', 20)->nullable();
            $table->string('evidence_disk', 40)->nullable();
            $table->text('evidence_path')->nullable();
            $table->string('evidence_mime', 100)->nullable();
            $table->unsignedBigInteger('evidence_size')->nullable();
            $table->timestamp('evidence_deleted_at')->nullable();
            $table->longText('recognition_result')->nullable();
            $table->decimal('confidence', 5, 2)->nullable();
            $table->string('model_name', 120)->nullable();
            $table->timestamp('recognized_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable();
            $table->string('idempotency_key', 120)->nullable();
            $table->char('request_fingerprint', 64)->nullable();
            $table->char('claim_token_hash', 64);
            $table->text('claim_token_encrypted');
            $table->unsignedInteger('submission_count')->default(1);
            $table->timestamps();

            $table->index(['organization_id', 'store_id', 'status', 'created_at'], 'student_claim_scope_status_index');
            $table->index(['store_id', 'normalized_email', 'status'], 'student_claim_email_status_index');
            $table->unique(['store_id', 'idempotency_key'], 'student_claim_idempotency_unique');
        });

        Schema::create('student_discount_codes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('claim_id')->constrained('student_discount_claims')->cascadeOnDelete();
            $table->string('normalized_email', 320);
            $table->string('shopify_discount_id', 255)->nullable();
            $table->string('code', 255);
            $table->string('status', 20)->default('unused');
            $table->unsignedInteger('usage_count')->default(0);
            $table->unsignedInteger('usage_limit')->default(1);
            $table->string('idempotency_key', 120);
            $table->timestamp('generated_at');
            $table->timestamp('expires_at');
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('email_sent_at')->nullable();
            $table->timestamp('email_failed_at')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'code'], 'student_discount_code_store_code_unique');
            $table->unique(['store_id', 'idempotency_key'], 'student_discount_code_idempotency_unique');
            $table->index(['store_id', 'normalized_email', 'expires_at'], 'student_discount_code_email_expiry_index');
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('student_discount_codes');
        Schema::dropIfExists('student_discount_claims');
        Schema::dropIfExists('student_discount_campaigns');
    }
};
