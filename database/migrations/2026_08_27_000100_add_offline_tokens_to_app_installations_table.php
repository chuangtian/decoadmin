<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_installations', function (Blueprint $table) {
            $table->text('access_token_encrypted')->nullable()->after('granted_scopes');
            $table->text('refresh_token_encrypted')->nullable()->after('access_token_encrypted');
            $table->string('token_type', 30)->nullable()->after('refresh_token_encrypted');
            $table->timestamp('access_token_expires_at')->nullable()->index()->after('token_type');
            $table->timestamp('refresh_token_expires_at')->nullable()->after('access_token_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('app_installations', function (Blueprint $table) {
            $table->dropIndex(['access_token_expires_at']);
            $table->dropColumn([
                'access_token_encrypted',
                'refresh_token_encrypted',
                'token_type',
                'access_token_expires_at',
                'refresh_token_expires_at',
            ]);
        });
    }
};
