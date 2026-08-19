<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_alerts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('fingerprint', 64)->unique();
            $table->string('type', 50);
            $table->string('severity', 20)->default('error');
            $table->string('source_type', 80)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('code', 100);
            $table->string('title');
            $table->text('message');
            $table->json('context')->nullable();
            $table->string('status', 30)->default('open');
            $table->string('delivery_status', 30)->default('pending');
            $table->text('delivery_error')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'store_id', 'status'], 'store_alerts_scope_status_index');
            $table->index(['store_id', 'type', 'occurred_at'], 'store_alerts_store_type_time_index');
            $table->index(['delivery_status', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_alerts');
    }
};
