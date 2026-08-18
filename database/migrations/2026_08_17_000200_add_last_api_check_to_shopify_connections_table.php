<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopify_connections', function (Blueprint $table) {
            $table->timestamp('last_api_check')->nullable()->after('api_version');
        });
    }

    public function down(): void
    {
        Schema::table('shopify_connections', function (Blueprint $table) {
            $table->dropColumn('last_api_check');
        });
    }
};
