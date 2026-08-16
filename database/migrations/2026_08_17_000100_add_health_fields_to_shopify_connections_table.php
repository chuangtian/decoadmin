<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopify_connections', function (Blueprint $table) {
            $table->text('last_error')->nullable()->after('last_verified_at');
            $table->timestamp('last_error_at')->nullable()->after('last_error');
        });

        DB::table('shopify_connections')->where('status', 'active')->update(['status' => 'connected']);
        DB::table('shopify_connections')->where('status', 'error')->update(['status' => 'warning']);
        DB::table('shopify_connections')->whereIn('status', ['inactive', 'uninstalled'])->update(['status' => 'disconnected']);

        Schema::table('shopify_connections', function (Blueprint $table) {
            $table->string('status', 30)->default('connected')->change();
        });
    }

    public function down(): void
    {
        DB::table('shopify_connections')->where('status', 'connected')->update(['status' => 'active']);
        DB::table('shopify_connections')->whereIn('status', ['warning', 'invalid'])->update(['status' => 'error']);
        DB::table('shopify_connections')->where('status', 'disconnected')->update(['status' => 'inactive']);

        Schema::table('shopify_connections', function (Blueprint $table) {
            $table->string('status', 30)->default('active')->change();
            $table->dropColumn(['last_error', 'last_error_at']);
        });
    }
};
