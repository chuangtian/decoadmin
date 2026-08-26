<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 已连接的 Instagram 专业账号，每个店铺一条。
        // provider 区分两条授权路线：instagram_login 直接用 IG 账号授权，
        // facebook_login 通过关联的 Facebook 主页授权。
        Schema::create('instagram_accounts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('provider', 20)->default('instagram_login');
            // connected | needs_page_selection
            $table->string('status', 24)->default('connected');

            $table->string('ig_user_id', 64)->nullable();
            $table->string('username', 120)->nullable();
            $table->string('account_type', 40)->nullable();
            $table->text('profile_picture_url')->nullable();

            // instagram_login：60 天长效 token，可刷新。
            // facebook_login：主页 access token，由长效用户 token 派生且不过期。
            $table->text('access_token_encrypted')->nullable();
            $table->timestamp('token_expires_at')->nullable();

            // facebook_login 专用
            $table->string('fb_user_id', 64)->nullable();
            $table->text('fb_user_token_encrypted')->nullable();
            $table->timestamp('fb_token_expires_at')->nullable();
            $table->string('page_id', 64)->nullable();
            $table->string('page_name', 255)->nullable();

            $table->timestamp('last_refreshed_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('last_published_at')->nullable();
            $table->foreignId('connected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'store_id'], 'instagram_account_org_store_unique');
            $table->index('ig_user_id', 'instagram_account_ig_user_index');
            $table->index('fb_user_id', 'instagram_account_fb_user_index');
        });

        // 从 Instagram 同步下来的单条媒体，以及它转存到 R2 的进度。
        Schema::create('instagram_media', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('instagram_accounts')->cascadeOnDelete();

            $table->string('ig_media_id', 64);
            // IMAGE | VIDEO | CAROUSEL_ALBUM
            $table->string('media_type', 24);
            // FEED | REELS | STORY | AD
            $table->string('media_product_type', 24)->nullable();
            $table->text('caption')->nullable();
            $table->string('permalink', 1024);
            // IG CDN 地址带签名会过期，只用于转存。
            $table->text('ig_media_url')->nullable();
            $table->text('ig_thumbnail_url')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->unsignedInteger('like_count')->nullable();
            $table->unsignedInteger('comments_count')->nullable();

            // pending 未转存 / processing 转存中 / ready 已就绪 / failed 失败
            $table->string('mirror_status', 20)->default('pending');
            $table->string('video_key', 512)->nullable();
            $table->string('poster_key', 512)->nullable();
            $table->string('video_url', 1024)->nullable();
            $table->string('poster_url', 1024)->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('mirror_error')->nullable();
            $table->timestamp('mirrored_at')->nullable();

            // 关联商品的 Shopify GID 列表。
            $table->json('product_ids')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'ig_media_id'], 'instagram_media_store_ig_media_unique');
            $table->index(['store_id', 'mirror_status', 'updated_at'], 'instagram_media_mirror_queue_index');
            $table->index(['store_id', 'media_type', 'posted_at'], 'instagram_media_type_posted_index');
            $table->index('account_id', 'instagram_media_account_index');
        });

        // 展示组：商家命名的一批媒体，对应前台一个 widget 要展示的内容。
        // 一条媒体可以同时属于多个组，且每组维护自己的顺序，所以成员关系单独建表。
        Schema::create('instagram_galleries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            // 主题编辑器里靠 handle 指定要展示哪个组，同店铺内必须唯一。
            $table->string('handle', 32);
            $table->unsignedInteger('position')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['store_id', 'handle'], 'instagram_gallery_store_handle_unique');
            $table->index(['store_id', 'position'], 'instagram_gallery_store_position_index');
        });

        // instagram-feed Shopify App 自己的店铺会话。
        //
        // 前台数据写在 AppInstallation 的 app-data metafield 上，而 Theme App Extension
        // 只能读到「自己所属 App」的 app-data metafield，所以发布必须用本 App 的 token，
        // 不能借用 DecoAdmin 主 App 的 ShopifyConnection。
        Schema::create('instagram_feed_installations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('environment', 20);
            // Shopify AppInstallation 的 GID，metafieldsSet 的 ownerId。
            $table->string('app_installation_id', 255)->nullable();
            $table->text('access_token_encrypted')->nullable();
            $table->json('granted_scopes')->nullable();
            $table->timestamp('installed_at')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamp('last_published_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'store_id'], 'instagram_installation_org_store_unique');
            $table->index(['store_id', 'environment'], 'instagram_installation_store_env_index');
        });

        Schema::create('instagram_gallery_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('gallery_id')->constrained('instagram_galleries')->cascadeOnDelete();
            $table->foreignId('media_id')->constrained('instagram_media')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            // 同一条媒体在一个组里只能出现一次。
            $table->unique(['gallery_id', 'media_id'], 'instagram_gallery_item_unique');
            $table->index(['gallery_id', 'position'], 'instagram_gallery_item_position_index');
            $table->index('media_id', 'instagram_gallery_item_media_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instagram_gallery_items');
        Schema::dropIfExists('instagram_feed_installations');
        Schema::dropIfExists('instagram_galleries');
        Schema::dropIfExists('instagram_media');
        Schema::dropIfExists('instagram_accounts');
    }
};
