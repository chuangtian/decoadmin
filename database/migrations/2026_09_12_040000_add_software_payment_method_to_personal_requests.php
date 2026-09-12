<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_requests', function (Blueprint $table): void {
            $table->string('software_payment_method')->nullable()->after('software_password');
        });
    }

    public function down(): void
    {
        Schema::table('personal_requests', function (Blueprint $table): void {
            $table->dropColumn('software_payment_method');
        });
    }
};
