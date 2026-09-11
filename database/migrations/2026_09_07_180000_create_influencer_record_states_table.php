<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('influencer_record_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('source_table_key', 191);
            $table->string('source_record_id', 191);
            $table->string('status', 16)->default('visible');
            $table->timestamps();
            $table->unique(['store_id', 'source_table_key', 'source_record_id'], 'influencer_record_state_identity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('influencer_record_states');
    }
};
