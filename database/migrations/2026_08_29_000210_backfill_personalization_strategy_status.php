<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Strategies activated before versioned publishing already have live
        // components but no published_version_id. Preserve that live state in
        // the new merchant-facing status without fabricating version history.
        DB::table('personalization_recommendation_strategies')
            ->where('enabled', true)
            ->whereNull('published_version_id')
            ->where('status', 'draft')
            ->update(['status' => 'enabled']);
    }

    public function down(): void
    {
        DB::table('personalization_recommendation_strategies')
            ->where('enabled', true)
            ->whereNull('published_version_id')
            ->where('status', 'enabled')
            ->update(['status' => 'draft']);
    }
};
