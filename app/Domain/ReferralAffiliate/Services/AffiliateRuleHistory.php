<?php

namespace App\Domain\ReferralAffiliate\Services;

use App\Domain\ReferralAffiliate\Models\AffiliateProgram;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class AffiliateRuleHistory
{
    public function baseline(AffiliateProgram $program): void
    {
        if (! DB::table('affiliate_rule_versions')->where('program_id', $program->id)->exists()) {
            $this->record($program, $program->created_at);
        }
    }

    public function record(AffiliateProgram $program, ?CarbonInterface $at = null): void
    {
        DB::table('affiliate_rule_versions')->insert(['organization_id' => $program->organization_id, 'store_id' => $program->store_id, 'program_id' => $program->id,
            'effective_at' => $at ?? now(), 'snapshot' => json_encode(['hold_days' => $program->hold_days, 'settings' => $program->settings,
                'rules' => $program->rules()->orderBy('id')->get()->toArray()], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function at(AffiliateProgram $program, CarbonInterface $at): ?array
    {
        $row = DB::table('affiliate_rule_versions')->where('organization_id', $program->organization_id)->where('store_id', $program->store_id)
            ->where('program_id', $program->id)->where('effective_at', '<=', $at)->orderByDesc('effective_at')->orderByDesc('id')->first();

        return $row ? json_decode($row->snapshot, true, 512, JSON_THROW_ON_ERROR) : null;
    }
}
