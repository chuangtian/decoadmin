<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('meta_ad_accounts')
            ->select(['id', 'meta_account_id'])
            ->orderBy('id')
            ->eachById(function (object $account): void {
                $externalId = (string) $account->meta_account_id;
                $canonical = str_starts_with($externalId, 'act_')
                    ? $externalId
                    : 'act_'.$externalId;

                DB::table('meta_ad_insights')
                    ->where('meta_ad_account_id', $account->id)
                    ->where('account_external_id', '!=', $canonical)
                    ->update([
                        'account_external_id' => $canonical,
                        'updated_at' => now(),
                    ]);
            });
    }

    public function down(): void
    {
        // Canonical account IDs cannot be safely converted back because the old
        // rows used a mix of numeric and act_ values.
    }
};
