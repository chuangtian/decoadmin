<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_notification_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('mail_enabled')->default(false);
            $table->string('mail_host')->nullable();
            $table->unsignedSmallInteger('mail_port')->default(587);
            $table->string('mail_encryption', 20)->default('tls');
            $table->string('mail_username')->nullable();
            $table->text('mail_password')->nullable();
            $table->string('mail_from_address')->nullable();
            $table->string('mail_from_name')->nullable();
            $table->json('mail_recipients')->nullable();
            $table->boolean('feishu_enabled')->default(false);
            $table->text('feishu_webhook_url')->nullable();
            $table->text('feishu_secret')->nullable();
            $table->boolean('notify_sync_failed')->default(true);
            $table->boolean('notify_webhook_failed')->default(true);
            $table->boolean('notify_connection_unhealthy')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'store_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_notification_settings');
    }
};
