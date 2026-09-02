<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reputation_mentions', function (Blueprint $table): void {
            $table->string('reviewer_name', 255)->nullable()->after('title');
            $table->index(['store_id', 'source', 'reviewer_name'], 'rep_mentions_store_source_reviewer_index');
        });

        Schema::create('reputation_mention_product_matches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reputation_mention_id')->constrained('reputation_mentions')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('match_role', 16)->default('mentioned');
            $table->string('matched_alias', 120);
            $table->decimal('confidence', 5, 4);
            $table->timestamps();

            $table->unique(
                ['reputation_mention_id', 'product_id'],
                'rep_mention_product_matches_unique',
            );
            $table->index(
                ['organization_id', 'store_id', 'match_role'],
                'rep_mention_product_matches_scope_role_index',
            );
            $table->index(
                ['organization_id', 'store_id', 'product_id'],
                'rep_mention_product_matches_scope_product_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reputation_mention_product_matches');

        Schema::table('reputation_mentions', function (Blueprint $table): void {
            $table->dropIndex('rep_mentions_store_source_reviewer_index');
            $table->dropColumn('reviewer_name');
        });
    }
};
