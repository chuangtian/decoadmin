<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('affiliate_promoters', function (Blueprint $t): void {
            $t->text('profile_encrypted')->nullable();
        });
        Schema::table('affiliate_program_memberships', function (Blueprint $t): void {
            $t->text('application_encrypted')->nullable();
            $t->text('rejection_reason')->nullable();
            $t->text('admin_notes')->nullable();
            $t->json('labels')->nullable();
        });
        Schema::create('affiliate_portal_tokens', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignId('store_id')->constrained()->cascadeOnDelete();
            $t->foreignId('membership_id')->constrained('affiliate_program_memberships')->cascadeOnDelete();
            $t->char('token_hash', 64)->unique();
            $t->timestamp('expires_at')->index();
            $t->timestamp('used_at')->nullable();
            $t->timestamps();
        });
        Schema::create('affiliate_assets', function (Blueprint $t): void {
            $t->id();
            $t->char('public_id', 26)->unique();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignId('store_id')->constrained()->cascadeOnDelete();
            $t->string('title', 150);
            $t->string('path', 500);
            $t->string('mime', 100);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
        Schema::create('affiliate_message_templates', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignId('store_id')->constrained()->cascadeOnDelete();
            $t->string('key', 64);
            $t->string('subject', 200);
            $t->text('body');
            $t->boolean('enabled')->default(true);
            $t->timestamps();
            $t->unique(['store_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_message_templates');
        Schema::dropIfExists('affiliate_assets');
        Schema::dropIfExists('affiliate_portal_tokens');
        Schema::table('affiliate_program_memberships', fn (Blueprint $t) => $t->dropColumn(['application_encrypted', 'rejection_reason', 'admin_notes', 'labels']));
        Schema::table('affiliate_promoters', fn (Blueprint $t) => $t->dropColumn('profile_encrypted'));
    }
};
