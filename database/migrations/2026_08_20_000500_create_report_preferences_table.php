<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('report_slug', 160);
            $table->timestamp('pinned_at')->nullable();
            $table->timestamp('last_viewed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'store_id', 'report_slug'], 'report_preferences_user_store_report_unique');
            $table->index(['store_id', 'pinned_at'], 'report_preferences_store_pin_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_preferences');
    }
};
