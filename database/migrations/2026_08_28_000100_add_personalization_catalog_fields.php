<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->json('tags')->nullable()->after('description');
            $table->timestamp('created_at_shopify')->nullable()->after('tags');
            $table->timestamp('published_at_shopify')->nullable()->after('created_at_shopify');
            $table->text('online_store_url')->nullable()->after('published_at_shopify');
            $table->text('featured_image_url')->nullable()->after('online_store_url');
            $table->string('featured_image_alt', 512)->nullable()->after('featured_image_url');
            $table->unsignedInteger('featured_image_width')->nullable()->after('featured_image_alt');
            $table->unsignedInteger('featured_image_height')->nullable()->after('featured_image_width');

            $table->index(
                ['organization_id', 'store_id', 'status', 'published_at_shopify'],
                'products_store_status_published_index',
            );
        });

        Schema::table('product_variants', function (Blueprint $table): void {
            $table->decimal('compare_at_price', 20, 4)->nullable()->after('price');
            $table->boolean('available_for_sale')->nullable()->after('compare_at_price');
            $table->json('selected_options')->nullable()->after('available_for_sale');
            $table->text('image_url')->nullable()->after('selected_options');
            $table->string('image_alt', 512)->nullable()->after('image_url');
            $table->unsignedInteger('image_width')->nullable()->after('image_alt');
            $table->unsignedInteger('image_height')->nullable()->after('image_width');

            $table->index(['product_id', 'available_for_sale'], 'product_variants_product_available_index');
        });

        Schema::create('product_collections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('shopify_collection_id');
            $table->string('title');
            $table->string('handle');
            $table->string('sort_order', 40)->nullable();
            $table->timestamp('updated_at_shopify')->nullable();
            $table->uuid('sync_batch')->nullable();
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->unique(['store_id', 'shopify_collection_id'], 'product_collections_store_shopify_unique');
            $table->index(['organization_id', 'store_id', 'handle'], 'product_collections_store_handle_index');
        });

        Schema::create('product_collection_memberships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_collection_id')->constrained('product_collections')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('shopify_product_id');
            $table->uuid('sync_batch');
            $table->timestamps();

            $table->unique(
                ['product_collection_id', 'shopify_product_id'],
                'product_collection_memberships_collection_product_unique',
            );
            $table->index(
                ['organization_id', 'store_id', 'shopify_product_id'],
                'product_collection_memberships_store_product_index',
            );
            $table->index(['product_id', 'product_collection_id'], 'product_collection_memberships_product_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_collection_memberships');
        Schema::dropIfExists('product_collections');

        Schema::table('product_variants', function (Blueprint $table): void {
            $table->dropIndex('product_variants_product_available_index');
            $table->dropColumn([
                'compare_at_price',
                'available_for_sale',
                'selected_options',
                'image_url',
                'image_alt',
                'image_width',
                'image_height',
            ]);
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex('products_store_status_published_index');
            $table->dropColumn([
                'tags',
                'created_at_shopify',
                'published_at_shopify',
                'online_store_url',
                'featured_image_url',
                'featured_image_alt',
                'featured_image_width',
                'featured_image_height',
            ]);
        });
    }
};
