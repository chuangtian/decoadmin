<?php

namespace App\Domain\ReferralAffiliate\Services;

use App\Domain\ReferralAffiliate\Models\AffiliateProgram;
use App\Domain\ReferralAffiliate\Models\AffiliateProgramMembership;
use App\Domain\ReferralAffiliate\Models\AffiliatePromoter;
use App\Domain\ReferralAffiliate\Models\AffiliateStoreSetting;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AffiliateManagementService
{
    public function __construct(private AffiliateAssetService $assets) {}

    public function transitionProgram(Organization $organization, Store $store, User $actor, string $publicId, string $action): AffiliateProgram
    {
        return DB::transaction(function () use ($organization, $store, $actor, $publicId, $action): AffiliateProgram {
            $program = AffiliateProgram::query()->forOrganization($organization)->forStore($store)
                ->where('public_id', $publicId)->lockForUpdate()->firstOrFail();
            $from = $program->status->value;
            $to = match ($action) {
                'activate' => in_array($from, ['draft', 'paused'], true) ? 'active' : null,
                'pause' => $from === 'active' ? 'paused' : null,
                default => null,
            };
            abort_unless($to !== null, 409, '当前计划状态不允许执行此操作。');
            $program->forceFill(['status' => $to, 'updated_by' => $actor->id])->save();
            $this->audit($organization, $store, $actor, 'affiliate_program_status_changed', $program, ['status' => $from], ['status' => $to]);

            return $program;
        });
    }

    public function transitionMembership(Organization $organization, Store $store, User $actor, string $publicId, string $action): AffiliateProgramMembership
    {
        return DB::transaction(function () use ($organization, $store, $actor, $publicId, $action): AffiliateProgramMembership {
            $membership = AffiliateProgramMembership::query()->forOrganization($organization)->forStore($store)
                ->where('public_id', $publicId)->lockForUpdate()->firstOrFail();
            $from = $membership->status->value;
            $to = match ($action) {
                'approve' => in_array($from, ['pending', 'waitlisted', 'suspended'], true) ? 'approved' : null,
                'suspend' => $from === 'approved' ? 'suspended' : null,
                default => null,
            };
            abort_unless($to !== null, 409, '当前推广者状态不允许执行此操作。');
            $membership->forceFill([
                'status' => $to,
                'approved_at' => $to === 'approved' ? now() : $membership->approved_at,
                'approved_by' => $to === 'approved' ? $actor->id : $membership->approved_by,
                'suspended_at' => $to === 'suspended' ? now() : null,
            ])->save();
            if ($to === 'approved') {
                $this->assets->provisionDefaults($membership);
            } else {
                $membership->link()->update(['status' => 'disabled']);
                $membership->coupon()->whereIn('status', ['provisioning', 'active'])->update(['status' => 'disable_pending']);
            }
            $this->audit($organization, $store, $actor, 'affiliate_membership_status_changed', $membership, ['status' => $from], ['status' => $to]);

            return $membership;
        });
    }

    /** @param array<string, mixed> $values */
    public function updateSettings(Organization $organization, Store $store, User $actor, array $values): AffiliateStoreSetting
    {
        return DB::transaction(function () use ($organization, $store, $actor, $values): AffiliateStoreSetting {
            $settings = AffiliateStoreSetting::query()->firstOrNew([
                'organization_id' => $organization->id,
                'store_id' => $store->id,
            ]);
            $old = $settings->exists ? $settings->only(['affiliate_enabled', 'customer_referral_enabled']) : null;
            $settings->fill([
                'affiliate_enabled' => (bool) $values['affiliate_enabled'],
                'customer_referral_enabled' => (bool) $values['customer_referral_enabled'],
                'updated_by' => $actor->id,
            ])->save();
            $this->audit($organization, $store, $actor, 'affiliate_settings_updated', $settings, $old, $settings->only(['affiliate_enabled', 'customer_referral_enabled']));

            return $settings;
        });
    }

    /** @param array<string, mixed> $values */
    public function createProgram(Organization $organization, Store $store, User $actor, array $values): AffiliateProgram
    {
        return DB::transaction(function () use ($organization, $store, $actor, $values): AffiliateProgram {
            $program = AffiliateProgram::query()->create([
                'organization_id' => $organization->id,
                'store_id' => $store->id,
                'name' => trim((string) $values['name']),
                'type' => $values['type'],
                'status' => 'draft',
                'attribution_model' => $values['attribution_model'],
                'attribution_window_days' => (int) $values['attribution_window_days'],
                'hold_days' => (int) $values['hold_days'],
                'currency' => strtoupper((string) ($store->currency ?: 'USD')),
                'coupon_enabled' => (bool) $values['coupon_enabled'],
                'customer_discount_type' => $values['coupon_enabled'] ? $values['customer_discount_type'] : null,
                'customer_discount_rate_basis_points' => $values['coupon_enabled'] && $values['customer_discount_type'] === 'percentage' ? (int) $values['customer_discount_rate_basis_points'] : null,
                'customer_discount_amount_minor' => $values['coupon_enabled'] && $values['customer_discount_type'] === 'fixed' ? (int) $values['customer_discount_amount_minor'] : null,
                'settings' => ['customer_scope' => 'store'],
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
            $program->rules()->create([
                'organization_id' => $organization->id,
                'store_id' => $store->id,
                'scope' => 'program',
                'commission_type' => $values['commission_type'],
                'rate_basis_points' => $values['commission_type'] === 'percentage' ? (int) $values['rate_basis_points'] : null,
                'amount_minor' => $values['commission_type'] === 'fixed' ? (int) $values['amount_minor'] : null,
                'priority' => 100,
                'enabled' => true,
                'settings' => ['base' => 'net_product_subtotal'],
            ]);
            $this->audit($organization, $store, $actor, 'affiliate_program_created', $program, null, ['public_id' => $program->public_id, 'name' => $program->name]);

            return $program;
        });
    }

    /** @param array<string, mixed> $values */
    public function createPromoter(Organization $organization, Store $store, User $actor, array $values): AffiliatePromoter
    {
        return DB::transaction(function () use ($organization, $store, $actor, $values): AffiliatePromoter {
            $email = mb_strtolower(trim((string) $values['email']));
            $emailHash = hash_hmac('sha256', $organization->id.'|'.$email, (string) config('app.key'));
            $promoter = AffiliatePromoter::withTrashed()->firstOrNew([
                'organization_id' => $organization->id,
                'email_hash' => $emailHash,
            ]);
            $promoter->fill([
                'email_encrypted' => $email,
                'display_name' => trim((string) $values['display_name']),
                'type' => $values['type'],
                'status' => 'active',
                'created_by' => $promoter->created_by ?: $actor->id,
                'updated_by' => $actor->id,
            ]);
            $promoter->deleted_at = null;
            $promoter->save();

            $program = AffiliateProgram::query()->forOrganization($organization)->forStore($store)
                ->where('public_id', $values['program_public_id'])->firstOrFail();
            $membership = $program->memberships()->firstOrCreate([
                'promoter_id' => $promoter->id,
            ], [
                'organization_id' => $organization->id,
                'store_id' => $store->id,
                'status' => 'pending',
            ]);
            $this->audit($organization, $store, $actor, 'affiliate_promoter_added', $membership, null, ['promoter_public_id' => $promoter->public_id, 'program_public_id' => $program->public_id]);

            return $promoter;
        });
    }

    /** @param array<string, mixed>|null $old @param array<string, mixed> $new */
    private function audit(Organization $organization, Store $store, User $actor, string $action, object $subject, ?array $old, array $new): void
    {
        AuditLog::query()->create([
            'organization_id' => $organization->id, 'store_id' => $store->id, 'user_id' => $actor->id,
            'action' => $action, 'subject_type' => $subject::class, 'subject_id' => $subject->id,
            'old_values' => $old, 'new_values' => $new,
        ]);
    }
}
