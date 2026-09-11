<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marketing_checkout_snapshots')) {
            Schema::create('marketing_checkout_snapshots', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('organization_id')->constrained();
                $table->foreignId('store_id')->constrained();
                $table->string('checkout_id');
                $table->timestamp('occurred_at');
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
                $table->unique(['store_id', 'checkout_id']);
            });
        }
        if (! Schema::hasIndex('marketing_checkout_snapshots', 'marketing_checkout_scope_date')) {
            Schema::table('marketing_checkout_snapshots', fn (Blueprint $table) => $table->index(['organization_id', 'store_id', 'occurred_at'], 'marketing_checkout_scope_date'));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_checkout_snapshots');
    }
};
