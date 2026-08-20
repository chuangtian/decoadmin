<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('report_key', 80);
            $table->date('period_from');
            $table->date('period_to');
            $table->string('timezone', 64)->default('UTC');
            $table->string('source', 40)->default('shopifyql');
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->json('payload');
            $table->timestamp('fetched_at');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique(
                ['organization_id', 'store_id', 'report_key', 'period_from', 'period_to', 'schema_version'],
                'analytics_snapshots_scope_period_unique',
            );
            $table->index(['store_id', 'report_key', 'expires_at'], 'analytics_snapshots_store_expiry_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_snapshots');
    }
};
