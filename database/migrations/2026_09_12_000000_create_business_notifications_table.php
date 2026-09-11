<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_notifications', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 80);
            $table->string('title', 180);
            $table->string('message', 500);
            $table->string('action_url', 500);
            $table->nullableMorphs('subject');
            $table->char('dedupe_key', 64)->unique();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'user_id', 'read_at', 'created_at'], 'business_notifications_inbox_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_notifications');
    }
};
