<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mf_daily_report_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('source_table_id', 120);
            $table->string('source_record_id', 120);
            $table->date('report_date');
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('message_hash', 64)->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['store_id', 'source_table_id', 'source_record_id'],
                'mf_daily_report_delivery_source_unique',
            );
            $table->index(
                ['organization_id', 'store_id', 'report_date', 'status'],
                'mf_daily_report_delivery_scope_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mf_daily_report_deliveries');
    }
};
