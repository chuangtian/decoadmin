<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('design_request_progress_logs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('design_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 30);
            $table->text('content')->nullable();
            $table->timestamps();

            $table->index(['design_request_id', 'created_at'], 'design_request_progress_timeline');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('design_request_progress_logs');
    }
};
