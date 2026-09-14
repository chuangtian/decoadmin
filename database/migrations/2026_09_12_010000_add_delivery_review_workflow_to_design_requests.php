<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('design_requests', function (Blueprint $table): void {
            $table->timestamp('accepted_at')->nullable()->after('designer_id');
            $table->timestamp('delivery_submitted_at')->nullable()->after('actual_delivery_date');
            $table->timestamp('reviewed_at')->nullable()->after('delivery_submitted_at');
            $table->timestamp('completed_at')->nullable()->after('reviewed_at');
            $table->index(
                ['organization_id', 'designer_id', 'status', 'delivery_submitted_at'],
                'design_requests_designer_workflow'
            );
        });

        DB::table('design_requests')
            ->where('status', 'completed')
            ->whereNotNull('actual_delivery_date')
            ->select(['id', 'actual_delivery_date'])
            ->orderBy('id')
            ->chunkById(500, function ($requests): void {
                foreach ($requests as $request) {
                    $completedAt = $request->actual_delivery_date.' 00:00:00';
                    DB::table('design_requests')->where('id', $request->id)->update([
                        'delivery_submitted_at' => $completedAt,
                        'reviewed_at' => $completedAt,
                        'completed_at' => $completedAt,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('design_requests', function (Blueprint $table): void {
            $table->dropIndex('design_requests_designer_workflow');
            $table->dropColumn(['accepted_at', 'delivery_submitted_at', 'reviewed_at', 'completed_at']);
        });
    }
};
