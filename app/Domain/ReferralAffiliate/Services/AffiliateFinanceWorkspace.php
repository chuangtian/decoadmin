<?php

namespace App\Domain\ReferralAffiliate\Services;

use App\Domain\ReferralAffiliate\Models\AffiliateConversion;
use App\Domain\ReferralAffiliate\Models\AffiliateLedgerEntry;
use App\Domain\ReferralAffiliate\Models\AffiliatePayoutBatch;
use App\Domain\ReferralAffiliate\Models\AffiliateProgramMembership;
use App\Domain\ReferralAffiliate\Models\AffiliateReward;
use App\Domain\ReferralAffiliate\Models\AffiliateRiskFlag;
use App\Domain\ReferralAffiliate\Support\Money;
use App\Models\Organization;
use App\Models\Store;
use App\Models\User;

class AffiliateFinanceWorkspace
{
    public const PERMISSIONS = ['conversions' => 'affiliate.conversions.view', 'commissions' => 'affiliate.commissions.view',
        'payouts' => 'affiliate.payouts.view', 'risks' => 'affiliate.fraud.view', 'reports' => 'affiliate.dashboard.view', 'rewards' => 'affiliate.promoters.view'];

    public function page(Organization $org, Store $store, User $actor, string $section, ?string $status = null): array
    {
        abort_unless(isset(self::PERMISSIONS[$section]), 404);
        app(AffiliateShopGuard::class)->actor($org, $store, $actor, self::PERMISSIONS[$section]);
        $model = match ($section) {
            'conversions','reports' => AffiliateConversion::class,'commissions' => AffiliateLedgerEntry::class,'rewards' => AffiliateReward::class, 'payouts' => AffiliatePayoutBatch::class,'risks' => AffiliateRiskFlag::class
        };
        $query = $model::query()->where('organization_id', $org->id)->where('store_id', $store->id);
        if ($status) {
            $query->where('status', $status);
        }
        $rows = $query->latest('id')->paginate(30)->withQueryString()->through(function ($record) use ($section) {
            $base = ['public_id' => $record->public_id, 'status' => $record->status, 'created_at' => $record->created_at->toIso8601String()];

            return $base + match ($section) {
                'conversions','reports' => ['label' => $record->order_name, 'source' => $record->source, 'reason' => $record->reason,
                    'base' => Money::decimal($record->base_minor, $record->currency), 'amount' => Money::decimal($record->commission_minor, $record->currency),
                    'reversed' => Money::decimal($record->reversed_minor, $record->currency), 'currency' => $record->currency, 'is_test' => $record->is_test,
                    'details' => ['rules' => $record->rule_snapshot, 'attribution' => $record->attribution_snapshot]],
                'commissions' => ['label' => $record->type, 'amount' => Money::decimal($record->amount_minor, $record->currency),
                    'currency' => $record->currency, 'reason' => $record->reason, 'available_at' => $record->available_at?->toIso8601String()],
                'payouts' => ['label' => $record->public_id, 'amount' => Money::decimal($record->total_minor, $record->currency),
                    'currency' => $record->currency, 'reference' => $record->external_reference, 'has_proof' => (bool) $record->proof_path, 'paid_at' => $record->paid_at?->toIso8601String()],
                'rewards' => ['label' => $record->code, 'amount' => match (data_get($record->rule_snapshot, 'type')) {
                    'percentage' => (data_get($record->rule_snapshot, 'basis_points', 0) / 100).'%', 'free_shipping' => '免邮', default => Money::decimal((int) data_get($record->rule_snapshot, 'amount_minor', 0), (string) data_get($record->rule_snapshot, 'currency', 'USD'))
                }, 'currency' => data_get($record->rule_snapshot, 'type') === 'fixed' ? data_get($record->rule_snapshot, 'currency', 'USD') : '', 'reason' => $record->last_error ?? ($record->threshold ? '累计推荐 '.$record->threshold.' 单奖励' : '好友订单奖励'), 'available_at' => $record->available_at?->toIso8601String()],
                'risks' => ['label' => $record->rule, 'reason' => $record->review_reason, 'details' => $record->evidence],
            };
        });
        $permissions = [];
        foreach (['conversions.override', 'commissions.adjust', 'commissions.approve', 'payouts.create', 'payouts.confirm', 'fraud.review', 'reports.export'] as $p) {
            $permissions[$p] = $actor->hasPermission('affiliate.'.$p, $org, $store);
        }
        $permissions['rewards.retry'] = $actor->hasPermission('affiliate.programs.manage', $org, $store);
        $members = ($permissions['commissions.adjust'] || $permissions['conversions.override']) ? AffiliateProgramMembership::query()->forOrganization($org)->forStore($store)
            ->with('promoter:id,display_name', 'program:id,name')->limit(200)->get()->map(fn ($m) => ['public_id' => $m->public_id, 'name' => $m->promoter->display_name.' / '.$m->program->name])->all() : [];
        $totals = [];
        if ($section === 'reports') {
            $totals = app(AffiliateReportService::class)->metrics($org, $store, $actor);
        }

        return ['organization' => $org->only('id', 'name'), 'store' => $store->only('id', 'name', 'currency'), 'section' => $section,
            'rows' => $rows, 'permissions' => $permissions, 'memberships' => $members, 'totals' => $totals, 'filters' => ['status' => $status]];
    }

    public function batch(Organization $org, Store $store, User $actor, string $publicId): AffiliatePayoutBatch
    {
        app(AffiliateShopGuard::class)->actor($org, $store, $actor, 'affiliate.payouts.view');

        return AffiliatePayoutBatch::query()->where('organization_id', $org->id)->where('store_id', $store->id)->where('public_id', $publicId)->firstOrFail();
    }
}
