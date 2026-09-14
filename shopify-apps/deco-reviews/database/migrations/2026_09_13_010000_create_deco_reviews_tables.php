<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deco_review_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('values');
            $table->timestamps();
        });
        Schema::create('deco_review_imports', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('digest', 64);
            $table->string('status', 24)->default('completed');
            $table->unsignedInteger('imported')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->json('errors')->nullable();
            $table->timestamp('undone_at')->nullable();
            $table->timestamps();
            $table->unique(['store_id', 'digest']);
        });
        Schema::create('deco_reviews', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('import_id')->nullable()->constrained('deco_review_imports')->nullOnDelete();
            $table->string('kind', 16)->default('product');
            $table->string('author_name', 120);
            $table->text('author_email')->nullable();
            $table->string('email_hash', 64)->nullable();
            $table->unsignedTinyInteger('rating');
            $table->string('title', 200)->nullable();
            $table->text('body');
            $table->string('status', 24)->default('pending');
            $table->string('source', 32)->default('merchant');
            $table->string('verified_source', 24)->default('none');
            $table->boolean('featured')->default(false);
            $table->boolean('incentivized')->default(false);
            $table->text('reply')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('publish_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('reviewed_at');
            $table->string('fingerprint', 64);
            $table->timestamps();
            $table->unique(['store_id', 'fingerprint']);
            $table->index(['organization_id', 'store_id', 'status', 'product_id']);
            $table->index(['status', 'publish_at']);
        });
        Schema::create('deco_review_media', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('review_id')->constrained('deco_reviews')->cascadeOnDelete();
            $table->string('type', 16);
            $table->string('mime', 100);
            $table->string('path');
            $table->unsignedBigInteger('size');
            $table->timestamps();
        });
        Schema::create('deco_review_invitations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('review_id')->nullable()->constrained('deco_reviews')->nullOnDelete();
            $table->text('email');
            $table->string('email_hash', 64);
            $table->string('status', 32)->default('scheduled');
            $table->timestamp('due_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('error_code', 64)->nullable();
            $table->timestamps();
            $table->unique(['store_id', 'order_id', 'product_id'], 'deco_invitation_order_product');
            $table->index(['status', 'due_at']);
        });
        Schema::create('deco_review_suppressions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('email_hash', 64);
            $table->timestamps();
            $table->unique(['store_id', 'email_hash']);
        });
    }

    public function down(): void
    {
        foreach (['deco_review_suppressions', 'deco_review_invitations', 'deco_review_media', 'deco_reviews', 'deco_review_imports', 'deco_review_settings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
