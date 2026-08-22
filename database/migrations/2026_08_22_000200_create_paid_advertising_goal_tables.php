<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paid_advertising_goal_boards', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('type', 40);
            $table->text('feishu_app_token');
            $table->text('feishu_table_id');
            $table->text('feishu_view_id');
            $table->string('sync_status', 30)->default('pending');
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['store_id', 'name'], 'paid_advertising_goal_boards_store_name_unique');
            $table->index(['organization_id', 'store_id', 'type'], 'paid_advertising_goal_boards_scope_index');
        });

        Schema::create('paid_advertising_goal_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('goal_board_id')->nullable()->constrained('paid_advertising_goal_boards')->cascadeOnDelete();
            $table->string('source_key', 80);
            $table->string('source_record_id', 120);
            $table->longText('fields_encrypted');
            $table->timestamp('source_created_at')->nullable();
            $table->timestamp('source_updated_at')->nullable();
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->unique(
                ['store_id', 'source_key', 'source_record_id'],
                'paid_advertising_goal_records_source_unique',
            );
            $table->index(
                ['organization_id', 'store_id', 'source_key'],
                'paid_advertising_goal_records_scope_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paid_advertising_goal_records');
        Schema::dropIfExists('paid_advertising_goal_boards');
    }
};
