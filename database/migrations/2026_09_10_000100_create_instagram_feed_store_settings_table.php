<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /**
         * 店铺自己的 Meta 应用凭证与 Cloudflare R2 存储凭证。
         *
         * 商家在 Shopify App 内嵌页的「应用配置」页签里维护这些值，所以必须按店铺隔离：
         * 一个店铺改自己的凭证不能影响别的店铺。留空的项回退到 system_settings 里的
         * 平台级配置，再回退 .env，保证已有部署不中断。
         *
         * 密钥列走 Eloquent 的 encrypted cast，明文不落库。
         */
        Schema::create('instagram_feed_store_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->unique()->constrained()->cascadeOnDelete();

            $table->string('instagram_app_id', 120)->nullable();
            $table->text('instagram_app_secret')->nullable();
            $table->string('facebook_app_id', 120)->nullable();
            $table->text('facebook_app_secret')->nullable();
            $table->string('facebook_login_config_id', 120)->nullable();

            $table->string('r2_account_id', 120)->nullable();
            $table->string('r2_access_key_id', 255)->nullable();
            $table->text('r2_secret_access_key')->nullable();
            $table->string('r2_bucket', 120)->nullable();
            $table->text('r2_public_base_url')->nullable();

            // 内嵌页的操作者不是 DecoAdmin 用户，此时 updated_by 留空、
            // updated_from 记 shopify_app_session，审计里再附 shop_domain。
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('updated_from', 32)->default('admin');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instagram_feed_store_settings');
    }
};
