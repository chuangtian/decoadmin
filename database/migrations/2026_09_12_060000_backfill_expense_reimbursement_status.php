<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('personal_requests')
            ->where('kind', 'expense')
            ->where('status', 'approved')
            ->whereNull('payment_status')
            ->update(['payment_status' => 'pending']);
    }

    public function down(): void
    {
        // Reimbursement status is financial history and must not be discarded on rollback.
    }
};
