<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affiliate_rule_versions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('organization_id')->constrained()->restrictOnDelete();
            $t->foreignId('store_id')->constrained()->restrictOnDelete();
            $t->foreignId('program_id')->constrained('affiliate_programs')->restrictOnDelete();
            $t->timestamp('effective_at')->index();
            $t->json('snapshot');
            $t->timestamps();
            $t->index(['program_id', 'effective_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_rule_versions');
    }
};
