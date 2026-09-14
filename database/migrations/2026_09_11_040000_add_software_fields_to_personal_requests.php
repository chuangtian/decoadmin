<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_requests', function (Blueprint $table): void {
            $table->string('software_url', 500)->nullable()->after('expense_date');
            $table->text('software_account')->nullable()->after('software_url');
            $table->text('software_password')->nullable()->after('software_account');
            $table->string('renewal_mode', 20)->nullable()->after('software_password');
            $table->string('billing_cycle', 20)->nullable()->after('renewal_mode');
        });
    }

    public function down(): void
    {
        Schema::table('personal_requests', function (Blueprint $table): void {
            $table->dropColumn(['software_url', 'software_account', 'software_password', 'renewal_mode', 'billing_cycle']);
        });
    }
};
