<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_requests', function (Blueprint $table): void {
            $table->timestamp('accepted_at')->nullable()->after('reviewed_at');
        });

        DB::table('personal_request_progress_logs')
            ->where('action', 'accepted')
            ->selectRaw('personal_request_id, MIN(created_at) as accepted_at')
            ->groupBy('personal_request_id')
            ->orderBy('personal_request_id')
            ->get()
            ->each(function (object $row): void {
                DB::table('personal_requests')
                    ->where('id', $row->personal_request_id)
                    ->where('kind', 'technical')
                    ->whereNull('accepted_at')
                    ->update(['accepted_at' => $row->accepted_at]);
            });
    }

    public function down(): void
    {
        Schema::table('personal_requests', function (Blueprint $table): void {
            $table->dropColumn('accepted_at');
        });
    }
};
