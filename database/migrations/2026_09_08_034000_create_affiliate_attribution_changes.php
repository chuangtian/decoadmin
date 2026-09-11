<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affiliate_attribution_changes', function (Blueprint $t) {
            $t->id();
            $t->char('public_id', 26)->unique();
            $t->foreignId('organization_id')->constrained()->restrictOnDelete();
            $t->foreignId('store_id')->constrained()->restrictOnDelete();
            $t->foreignId('conversion_id')->constrained('affiliate_conversions')->restrictOnDelete();
            $t->uuid('request_id');
            $t->foreignId('from_membership_id')->nullable()->constrained('affiliate_program_memberships')->restrictOnDelete();
            $t->foreignId('to_membership_id')->constrained('affiliate_program_memberships')->restrictOnDelete();
            $t->json('previous_snapshot');
            $t->json('new_snapshot');
            $t->string('reason', 1000);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['store_id', 'request_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_attribution_changes');
    }
};
