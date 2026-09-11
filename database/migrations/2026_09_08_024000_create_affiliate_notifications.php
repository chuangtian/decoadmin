<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('affiliate_message_templates', function (Blueprint $t) {
            $t->unsignedInteger('version')->default(1);
            $t->string('locale', 10)->default('en');
        });
        Schema::create('affiliate_notification_intents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->restrictOnDelete();
            $t->foreignId('store_id')->constrained()->restrictOnDelete();
            $t->foreignId('membership_id')->nullable()->constrained('affiliate_program_memberships')->restrictOnDelete();
            $t->string('event_key', 64);
            $t->string('dedupe_key', 191);
            $t->unsignedInteger('template_version');
            $t->char('recipient_hash', 64);
            $t->text('message_encrypted');
            $t->string('status', 24)->default('queued');
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->timestamp('sent_at')->nullable();
            $t->string('provider_id')->nullable();
            $t->string('error_summary')->nullable();
            $t->timestamps();
            $t->unique(['store_id', 'dedupe_key']);
            $t->index(['store_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_notification_intents');
        Schema::table('affiliate_message_templates', fn (Blueprint $t) => $t->dropColumn(['version', 'locale']));
    }
};
