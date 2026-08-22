<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_planning_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->comment('组织 ID')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->comment('店铺 ID')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_activity_id')
                ->unique()
                ->comment('活动主题 ID')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('source_type', 20)->nullable()->comment('飞书来源类型：docx 或 wiki');
            $table->string('source_node_token', 160)->nullable()->comment('飞书知识库节点 Token');
            $table->string('source_document_token', 160)->nullable()->comment('飞书文档 Token');
            $table->text('source_url')->comment('飞书策划书原文链接');
            $table->string('title', 500)->nullable()->comment('策划书标题');
            $table->string('source_revision_id', 80)->nullable()->comment('飞书文档版本 ID');
            $table->json('content_blocks')->nullable()->comment('飞书原始文档块结构');
            $table->longText('rendered_html')->nullable()->comment('网站展示用安全 HTML');
            $table->longText('plain_text')->nullable()->comment('搜索与摘要用纯文本');
            $table->string('local_pdf_path', 1024)->nullable()->comment('服务器 PDF 归档路径');
            $table->char('content_hash', 64)->nullable()->comment('文档内容 SHA-256');
            $table->string('sync_status', 32)->default('pending')->comment('同步状态');
            $table->timestamp('synced_at')->nullable()->comment('最近成功手动同步时间');
            $table->string('last_error', 500)->nullable()->comment('最近一次脱敏错误摘要');
            $table->timestamps();

            $table->index(
                ['organization_id', 'store_id', 'sync_status'],
                'campaign_planning_documents_scope_status_index',
            );
        });

        Schema::create('campaign_planning_assets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campaign_planning_document_id')
                ->comment('策划书快照 ID')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('organization_id')->comment('组织 ID')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->comment('店铺 ID')->constrained()->cascadeOnDelete();
            $table->char('source_file_token_hash', 64)->comment('飞书文件 Token 的 SHA-256');
            $table->string('asset_type', 20)->comment('资源类型：image 或 file');
            $table->string('original_name', 500)->nullable()->comment('飞书原始文件名');
            $table->string('local_disk', 30)->default('local')->comment('服务器存储磁盘');
            $table->string('local_path', 1024)->comment('服务器私有文件路径');
            $table->string('local_url', 1024)->comment('网站鉴权访问链接');
            $table->string('mime_type', 120)->nullable()->comment('文件 MIME 类型');
            $table->unsignedBigInteger('file_size')->nullable()->comment('服务器文件大小（字节）');
            $table->unsignedInteger('width')->nullable()->comment('图片宽度（像素）');
            $table->unsignedInteger('height')->nullable()->comment('图片高度（像素）');
            $table->char('content_hash', 64)->comment('服务器文件 SHA-256');
            $table->timestamps();

            $table->unique(
                ['campaign_planning_document_id', 'source_file_token_hash'],
                'campaign_planning_assets_document_token_unique',
            );
            $table->index(
                ['organization_id', 'store_id'],
                'campaign_planning_assets_scope_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_planning_assets');
        Schema::dropIfExists('campaign_planning_documents');
    }
};
