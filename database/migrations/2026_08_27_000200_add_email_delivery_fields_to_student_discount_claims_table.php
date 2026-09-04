<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_discount_claims', function (Blueprint $table) {
            $table->timestamp('email_sent_at')->nullable()->after('rejection_reason');
            $table->timestamp('email_failed_at')->nullable()->after('email_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('student_discount_claims', function (Blueprint $table) {
            $table->dropColumn(['email_sent_at', 'email_failed_at']);
        });
    }
};
