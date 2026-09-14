<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deco_review_email_deliveries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('review_id')->constrained('deco_reviews')->cascadeOnDelete();
            $table->string('type', 40);
            $table->text('recipient');
            $table->string('recipient_hash', 64);
            $table->string('dedupe_key', 64)->unique();
            $table->string('status', 24)->default('scheduled');
            $table->timestamp('due_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('error_code', 80)->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'store_id', 'status', 'due_at'], 'deco_review_email_scope_status_due');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deco_review_email_deliveries');
    }
};
