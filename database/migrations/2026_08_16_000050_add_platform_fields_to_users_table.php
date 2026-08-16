<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('status', 30)->default('active')->index()->after('password');
            $table->string('timezone', 100)->default('UTC')->after('status');
            $table->string('locale', 12)->default('en')->after('timezone');
            $table->string('avatar_url', 2048)->nullable()->after('locale');
            $table->timestamp('last_login_at')->nullable()->index()->after('avatar_url');
            $table->json('metadata')->nullable()->after('last_login_at');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'status',
                'timezone',
                'locale',
                'avatar_url',
                'last_login_at',
                'metadata',
                'deleted_at',
            ]);
        });
    }
};
