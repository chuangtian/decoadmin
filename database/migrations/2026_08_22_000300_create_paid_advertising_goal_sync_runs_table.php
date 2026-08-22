<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paid_advertising_goal_sync_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('goal_board_id')->nullable()->constrained('paid_advertising_goal_boards')->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('target_key', 80);
            $table->string('trigger', 40);
            $table->string('status', 20)->default('queued');
            $table->json('result')->nullable();
            $table->string('error_code', 80)->nullable();
            $table->string('message', 500)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(
                ['organization_id', 'store_id', 'target_key', 'status'],
                'paid_advertising_goal_sync_runs_scope_status_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paid_advertising_goal_sync_runs');
    }
};
