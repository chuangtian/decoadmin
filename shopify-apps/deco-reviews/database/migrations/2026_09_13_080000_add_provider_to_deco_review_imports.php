<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deco_review_imports', function (Blueprint $table) {
            $table->string('provider', 32)->default('custom')->after('digest');
        });
    }

    public function down(): void
    {
        Schema::table('deco_review_imports', function (Blueprint $table) {
            $table->dropColumn('provider');
        });
    }
};
