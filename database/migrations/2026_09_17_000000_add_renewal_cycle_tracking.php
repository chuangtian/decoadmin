<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_requests', function (Blueprint $table): void {
            $table->unsignedTinyInteger('renewal_anchor_day')->nullable()->after('billing_cycle');
            $table->boolean('renewal_anchor_month_end')->default(false)->after('renewal_anchor_day');
        });

        DB::table('personal_requests')
            ->where('category', 'software')
            ->whereNotNull('paid_on')
            ->select(['id', 'paid_on'])
            ->orderBy('id')
            ->chunkById(200, function ($items): void {
                foreach ($items as $item) {
                    $initialPaidOn = DB::table('personal_request_payments')
                        ->where('personal_request_id', $item->id)
                        ->where('type', 'initial_payment')
                        ->orderBy('id')
                        ->value('paid_on');
                    $paidOn = CarbonImmutable::parse((string) ($initialPaidOn ?: $item->paid_on));
                    DB::table('personal_requests')->where('id', $item->id)->update([
                        'renewal_anchor_day' => $paidOn->day,
                        'renewal_anchor_month_end' => $paidOn->isLastOfMonth(),
                    ]);
                }
            });

        Schema::table('personal_request_payments', function (Blueprint $table): void {
            $table->date('renewal_due_on')->nullable()->after('paid_on');
        });

        DB::table('personal_request_payments')
            ->whereIn('type', ['manual_renewal', 'automatic_renewal'])
            ->update(['renewal_due_on' => DB::raw('paid_on')]);

        Schema::table('personal_request_payments', function (Blueprint $table): void {
            $table->unique(['personal_request_id', 'renewal_due_on'], 'personal_request_payments_request_cycle_unique');
            $table->index(['organization_id', 'renewal_due_on'], 'personal_request_payments_org_due_index');
        });

        Schema::table('personal_request_payments', function (Blueprint $table): void {
            $table->dropUnique('personal_request_payments_request_date_unique');
        });
    }

    public function down(): void
    {
        Schema::table('personal_request_payments', function (Blueprint $table): void {
            $table->unique(['personal_request_id', 'paid_on'], 'personal_request_payments_request_date_unique');
        });

        Schema::table('personal_request_payments', function (Blueprint $table): void {
            $table->dropUnique('personal_request_payments_request_cycle_unique');
            $table->dropIndex('personal_request_payments_org_due_index');
            $table->dropColumn('renewal_due_on');
        });

        Schema::table('personal_requests', function (Blueprint $table): void {
            $table->dropColumn(['renewal_anchor_day', 'renewal_anchor_month_end']);
        });
    }
};
