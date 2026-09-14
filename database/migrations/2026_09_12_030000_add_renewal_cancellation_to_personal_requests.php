<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_requests', function (Blueprint $table): void {
            $table->string('renewal_status', 20)->default('active')->after('billing_cycle');
            $table->date('cancelled_on')->nullable()->after('next_renewal_on');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_on')->constrained('users')->nullOnDelete();
            $table->index(['organization_id', 'renewal_status', 'next_renewal_on'], 'personal_requests_renewal_schedule');
        });
    }

    public function down(): void
    {
        Schema::table('personal_requests', function (Blueprint $table): void {
            $table->dropIndex('personal_requests_renewal_schedule');
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['renewal_status', 'cancelled_on']);
        });
    }
};
