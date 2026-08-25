<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopify_connections', function (Blueprint $table): void {
            $table->text('access_token_encrypted')->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('shopify_connections')
            ->whereNull('access_token_encrypted')
            ->update(['access_token_encrypted' => '']);

        Schema::table('shopify_connections', function (Blueprint $table): void {
            $table->text('access_token_encrypted')->nullable(false)->change();
        });
    }
};
