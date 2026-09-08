<?php

namespace App\Domain\ReferralAffiliate\Services;

use App\Domain\ReferralAffiliate\Models\AffiliateProgram;
use App\Domain\ReferralAffiliate\Models\AffiliateProgramMembership;
use App\Domain\ReferralAffiliate\Models\AffiliateStoreSetting;
use App\Domain\ReferralAffiliate\Support\Money;
use App\Models\Organization;
use App\Models\Store;
use App\Models\User;

class AffiliateWorkspaceService
{
    /** @return array<string, mixed> */
    public function dashboard(Organization $organization, Store $store, User $actor, string $section): array
    {
        app(AffiliateShopGuard::class)->actor($organization, $store, $actor, match ($section) {
            'programs' => 'affiliate.programs.view',
            'promoters' => 'affiliate.promoters.view',
            default => 'affiliate.dashboard.view',
        });

        $settings = AffiliateStoreSetting::query()
            ->forOrganization($organization)->forStore($store)->first();
        $programs = AffiliateProgram::query()
            ->forOrganization($organization)->forStore($store)
            ->with('rules')->withCount('memberships')->latest()->limit(100)->get();
        $memberships = AffiliateProgramMembership::query()
            ->forOrganization($organization)->forStore($store)
            ->with([
                'program:id,public_id,name',
                'promoter:id,public_id,display_name,email_encrypted,type,status',
                'link:id,public_id,membership_id,referral_code,status',
                'coupon:id,membership_id,code,status,last_error',
            ])
            ->latest()->limit(100)->get();

        return [
            'organization' => ['id' => $organization->id, 'name' => $organization->name],
            'currency_decimals' => Money::decimals(strtoupper($store->currency ?: 'USD')),
            'store' => ['id' => $store->id, 'name' => $store->name, 'currency' => strtoupper((string) ($store->currency ?: 'USD'))],
            'section' => $section,
            'settings' => $settings?->only(['affiliate_enabled', 'customer_referral_enabled']) ?? [
                'affiliate_enabled' => false,
                'customer_referral_enabled' => false,
            ],
            'stats' => [
                'programs' => AffiliateProgram::query()->forOrganization($organization)->forStore($store)->count(),
                'active_programs' => AffiliateProgram::query()->forOrganization($organization)->forStore($store)->where('status', 'active')->count(),
                'promoters' => AffiliateProgramMembership::query()->forOrganization($organization)->forStore($store)->distinct()->count('promoter_id'),
                'pending_memberships' => AffiliateProgramMembership::query()->forOrganization($organization)->forStore($store)->where('status', 'pending')->count(),
            ],
            'programs' => ($actor->hasPermission('affiliate.programs.view', $organization, $store) ? $programs : collect())->map(fn (AffiliateProgram $program): array => [
                'public_id' => $program->public_id,
                'name' => $program->name,
                'type' => $program->type->value,
                'status' => $program->status->value,
                'attribution_model' => $program->attribution_model,
                'attribution_window_days' => $program->attribution_window_days,
                'hold_days' => $program->hold_days,
                'currency' => $program->currency,
                'reward' => data_get($program->settings, 'reward'), 'milestones' => data_get($program->settings, 'milestones', []),
                'coupon_enabled' => $program->coupon_enabled,
                'customer_discount_type' => $program->customer_discount_type,
                'customer_discount_rate_basis_points' => $program->customer_discount_rate_basis_points,
                'customer_discount_amount_minor' => $program->customer_discount_amount_minor,
                'default_rule' => $program->rules->firstWhere('scope', 'program')?->only(['commission_type', 'rate_basis_points', 'amount_minor']),
                'rules' => $program->rules->where('scope', '!=', 'program')->map(fn ($r) => ['label' => data_get($r->settings, 'label', $r->scope_reference), 'scope' => $r->scope, 'reference' => $r->scope_reference, 'type' => $r->commission_type, 'basis_points' => $r->rate_basis_points, 'amount_minor' => $r->amount_minor, 'exclude' => (bool) data_get($r->settings, 'exclude', false), 'fixed_mode' => data_get($r->settings, 'fixed_mode', 'order')])->values(),
                'memberships_count' => $program->memberships_count,
                'created_at' => $program->created_at?->toIso8601String(),
            ])->values(),
            'memberships' => ($actor->hasPermission('affiliate.promoters.view', $organization, $store) ? $memberships : collect())->map(fn (AffiliateProgramMembership $membership): array => [
                'public_id' => $membership->public_id,
                'tier_key' => $membership->tier_key, 'commission_override' => $membership->commission_override, 'labels' => $membership->labels ?? [],
                'admin_notes' => $membership->admin_notes, 'application' => $membership->application_encrypted, 'rejection_reason' => $membership->rejection_reason,
                'status' => $membership->status->value,
                'program' => $membership->program?->only(['public_id', 'name']),
                'promoter' => [
                    'public_id' => $membership->promoter->public_id,
                    'display_name' => $membership->promoter->display_name,
                    'email' => $membership->promoter->email_encrypted,
                    'type' => $membership->promoter->type,
                    'status' => $membership->promoter->status,
                ],
                'link' => $membership->link ? [
                    'url' => url('/r/'.$membership->link->public_id),
                    'code' => $membership->link->referral_code,
                    'status' => $membership->link->status,
                ] : null,
                'coupon' => $membership->coupon?->only(['code', 'status', 'last_error']),
                'created_at' => $membership->created_at?->toIso8601String(),
            ])->values(),
            'permissions' => [
                'managePrograms' => $actor->hasPermission('affiliate.programs.manage', $organization, $store),
                'managePromoters' => $actor->hasPermission('affiliate.promoters.manage', $organization, $store),
                'manageSettings' => $actor->hasPermission('affiliate.settings.manage', $organization, $store),
            ],
        ];
    }
}
