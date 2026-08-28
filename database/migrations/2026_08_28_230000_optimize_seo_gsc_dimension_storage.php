<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_gsc_pages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->char('page_hash', 64);
            $table->text('page');
            $table->boolean('is_blog')->default(false);
            $table->timestamps();

            $table->unique(['store_id', 'page_hash'], 'seo_gsc_pages_store_hash_unique');
            $table->index(['organization_id', 'store_id', 'is_blog'], 'seo_gsc_pages_scope_blog_index');
        });

        Schema::create('seo_gsc_queries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->char('query_hash', 64);
            $table->text('query');
            $table->timestamps();

            $table->unique(['store_id', 'query_hash'], 'seo_gsc_queries_store_hash_unique');
            $table->index(['organization_id', 'store_id'], 'seo_gsc_queries_scope_index');
        });

        Schema::table('seo_gsc_page_daily_metrics', function (Blueprint $table): void {
            $table->unsignedBigInteger('page_id')->nullable()->after('segment');
            $table->index(['store_id', 'page_id'], 'seo_gsc_page_store_dimension_index');
        });

        Schema::table('seo_gsc_query_daily_metrics', function (Blueprint $table): void {
            $table->unsignedBigInteger('query_id')->nullable()->after('segment');
            $table->index(['store_id', 'query_id'], 'seo_gsc_query_store_dimension_index');
        });
    }

    public function down(): void
    {
        Schema::table('seo_gsc_query_daily_metrics', function (Blueprint $table): void {
            $table->dropIndex('seo_gsc_query_store_dimension_index');
            $table->dropColumn('query_id');
        });

        Schema::table('seo_gsc_page_daily_metrics', function (Blueprint $table): void {
            $table->dropIndex('seo_gsc_page_store_dimension_index');
            $table->dropColumn('page_id');
        });

        Schema::dropIfExists('seo_gsc_queries');
        Schema::dropIfExists('seo_gsc_pages');
    }
};
