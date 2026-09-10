<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_contacts', fn (Blueprint $t) => $t->timestamp('first_subscribed_at')->nullable());
        Schema::create('marketing_order_snapshots', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('organization_id');
            $t->unsignedBigInteger('store_id');
            $t->unsignedBigInteger('contact_id');
            $t->string('order_id', 100);
            $t->string('name', 100);
            $t->string('financial_status', 40);
            $t->string('currency', 3);
            $t->decimal('total', 15, 2);
            $t->decimal('refunded', 15, 2)->default(0);
            $t->decimal('outstanding', 15, 2)->nullable();
            $t->timestamp('ordered_at');
            $t->timestamp('cancelled_at')->nullable();
            $t->timestamps();
            $t->unique(['store_id', 'order_id'], 'marketing_order_store_unique');
            $t->index(['store_id', 'contact_id', 'ordered_at'], 'marketing_order_contact_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_order_snapshots');
        Schema::table('marketing_contacts', fn (Blueprint $t) => $t->dropColumn('first_subscribed_at'));
    }
};
