<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_goal_work_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('source_key', 40);
            $table->unsignedInteger('source_row');
            $table->date('actual_date')->nullable();
            $table->char('cooperation_month', 7)->nullable();
            $table->unsignedTinyInteger('cooperation_month_number')->nullable();
            $table->string('name')->nullable();
            $table->boolean('url_present')->default(false);
            $table->boolean('included')->default(false);
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->unique(['store_id', 'source_key', 'source_row'], 'seo_work_store_source_row_unique');
            $table->index(['organization_id', 'store_id', 'source_key'], 'seo_work_scope_source_index');
            $table->index(['store_id', 'source_key', 'actual_date'], 'seo_work_source_date_index');
            $table->index(['store_id', 'source_key', 'cooperation_month'], 'seo_work_source_month_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_goal_work_records');
    }
};
