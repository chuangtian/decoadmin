<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deco_review_rewards', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('review_id')->unique()->constrained('deco_reviews')->cascadeOnDelete();
            $table->string('media_kind', 16);
            $table->string('discount_kind', 24);
            $table->decimal('value', 12, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->unsignedSmallInteger('expiration_days');
            $table->text('code')->nullable();
            $table->string('code_hash', 64)->nullable()->unique();
            $table->string('shopify_discount_id')->nullable();
            $table->string('status', 24)->default('scheduled');
            $table->timestamp('due_at')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('error_code', 80)->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'store_id', 'status', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deco_review_rewards');
    }
};
