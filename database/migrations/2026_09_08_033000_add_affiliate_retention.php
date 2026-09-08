<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affiliate_click_totals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->restrictOnDelete();
            $t->foreignId('store_id')->constrained()->restrictOnDelete();
            $t->foreignId('membership_id')->constrained('affiliate_program_memberships')->restrictOnDelete();
            $t->date('day');
            $t->unsignedBigInteger('clicks');
            $t->unique(['store_id', 'membership_id', 'day']);
        });
        Schema::table('affiliate_notification_intents', fn (Blueprint $t) => $t->timestamp('redacted_at')->nullable()->index());
    }

    public function down(): void
    {
        Schema::table('affiliate_notification_intents', fn (Blueprint $t) => $t->dropColumn('redacted_at'));
        Schema::dropIfExists('affiliate_click_totals');
    }
};
