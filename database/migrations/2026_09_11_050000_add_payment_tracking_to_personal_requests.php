<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('personal_requests', 'renewal_date')) {
            Schema::table('personal_requests', function (Blueprint $table): void {
                $table->dropColumn('renewal_date');
            });
        }

        Schema::table('personal_requests', function (Blueprint $table): void {
            $table->string('payment_status', 30)->nullable()->after('billing_cycle');
            $table->foreignId('paid_by')->nullable()->after('payment_status')->constrained('users')->nullOnDelete();
            $table->date('paid_on')->nullable()->after('paid_by');
            $table->date('next_renewal_on')->nullable()->after('paid_on');
            $table->string('payment_reference', 180)->nullable()->after('next_renewal_on');
        });
    }

    public function down(): void
    {
        Schema::table('personal_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('paid_by');
            $table->dropColumn(['payment_status', 'paid_on', 'next_renewal_on', 'payment_reference']);
        });
    }
};
