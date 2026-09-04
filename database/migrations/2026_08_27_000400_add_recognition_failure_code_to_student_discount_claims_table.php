<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_discount_claims', function (Blueprint $table): void {
            $table->string('recognition_failure_code', 40)->nullable()->after('model_name');
        });
    }

    public function down(): void
    {
        Schema::table('student_discount_claims', function (Blueprint $table): void {
            $table->dropColumn('recognition_failure_code');
        });
    }
};
