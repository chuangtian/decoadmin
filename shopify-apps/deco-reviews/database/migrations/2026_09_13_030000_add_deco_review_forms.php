<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deco_review_forms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->unique()->constrained()->cascadeOnDelete();
            $table->uuid('version');
            $table->json('configuration');
            $table->timestamps();
        });
        // Encrypted UTF-8 answers can exceed TEXT after ciphertext/base64 expansion.
        Schema::table('deco_reviews', fn (Blueprint $table) => $table->mediumText('form_answers')->nullable());
    }

    public function down(): void
    {
        Schema::table('deco_reviews', fn (Blueprint $table) => $table->dropColumn('form_answers'));
        Schema::dropIfExists('deco_review_forms');
    }
};
