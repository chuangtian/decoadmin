<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_alerts', function (Blueprint $table) {
            $table->unsignedSmallInteger('delivery_attempts')->default(0)->after('delivery_error');
            $table->timestamp('last_delivery_at')->nullable()->after('notified_at');
        });
    }

    public function down(): void
    {
        Schema::table('store_alerts', function (Blueprint $table) {
            $table->dropColumn(['delivery_attempts', 'last_delivery_at']);
        });
    }
};
