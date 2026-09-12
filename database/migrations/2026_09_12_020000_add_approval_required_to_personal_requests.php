<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_requests', function (Blueprint $table): void {
            $table->boolean('approval_required')->default(true)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('personal_requests', function (Blueprint $table): void {
            $table->dropColumn('approval_required');
        });
    }
};
