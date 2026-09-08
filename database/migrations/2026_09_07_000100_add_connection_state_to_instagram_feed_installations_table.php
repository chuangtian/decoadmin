<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Instagram Feed 的安装记录补上连接状态字段。
 *
 * 原来这张表只能表达「有没有 token」，卸载时还是硬删，后台看不到安装历史，
 * 授权失败也没有任何可见原因。这里对齐 shopify_connections 的字段集合，
 * 让「记录安装的店铺 + 概览」有数据可展示。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instagram_feed_installations', function (Blueprint $table): void {
            // connected | warning | invalid | disconnected
            $table->string('status', 20)->default('connected')->after('environment');
            $table->foreignId('installed_by')->nullable()->after('granted_scopes')->constrained('users')->nullOnDelete();
            $table->timestamp('uninstalled_at')->nullable()->after('installed_at');
            $table->timestamp('last_api_check')->nullable()->after('last_verified_at');
            $table->text('last_error')->nullable()->after('last_published_at');
            $table->timestamp('last_error_at')->nullable()->after('last_error');

            $table->index('status', 'instagram_installation_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('instagram_feed_installations', function (Blueprint $table): void {
            $table->dropIndex('instagram_installation_status_index');
            $table->dropConstrainedForeignId('installed_by');
            $table->dropColumn(['status', 'uninstalled_at', 'last_api_check', 'last_error', 'last_error_at']);
        });
    }
};
