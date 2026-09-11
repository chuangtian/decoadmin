<?php

namespace App\Domain\ReferralAffiliate\Services;

use App\Domain\ReferralAffiliate\Models\AffiliateProgram;
use App\Domain\ReferralAffiliate\Models\AffiliateProgramMembership;
use App\Domain\ReferralAffiliate\Models\AffiliatePromoter;
use App\Domain\ReferralAffiliate\Models\AffiliateStoreSetting;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductCollection;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AffiliateManagementService
{
    public function updateProgram(Organization $organization, Store $store, User $actor, string $publicId, array $values): AffiliateProgram
    {
        $this->guard->actor($organization, $store, $actor, 'affiliate.programs.manage');

        return DB::transaction(function () use ($organization, $store, $actor, $publicId, $values) {
            $program = AffiliateProgram::query()->forOrganization($organization)->forStore($store)->where('public_id', $publicId)->lockForUpdate()->firstOrFail();
            abort_if($program->status->value === 'archived', 409);
            abort_unless($program->type->value === $values['type'], 422, '创建后不能更改计划身份。');
            abort_if(($program->customer_discount_type === 'free_shipping') !== (($values['customer_discount_type'] ?? null) === 'free_shipping') && $program->memberships()->whereHas('coupon', fn ($q) => $q->whereNotNull('shopify_discount_id'))->exists(), 422, '已有优惠码时不能在免邮与商品优惠之间切换，请新建计划。');
            $values = $this->schedule($values, $program);
            $values['settings'] = $this->rewardSettings($organization, $store, $actor, $values, $program->settings ?? []);
            app(AffiliateRuleHistory::class)->baseline($program);
            $old = $program->only(['name', 'attribution_model', 'attribution_window_days', 'hold_days', 'coupon_enabled', 'customer_discount_type', 'customer_discount_rate_basis_points', 'customer_discount_amount_minor', 'starts_at', 'ends_at', 'settings']);
            $program->forceFill(array_intersect_key($values, array_flip(array_keys($old))) + ['updated_by' => $actor->id])->save();
            $rule = $program->rules()->where('scope', 'program')->where('scope_reference', '*')->firstOrFail();
            $oldRule = $rule->only(['commission_type', 'rate_basis_points', 'amount_minor']);
            $rule->update(['commission_type' => $values['commission_type'],
                'rate_basis_points' => $values['commission_type'] === 'percentage' ? $values['rate_basis_points'] : null,
                'amount_minor' => $values['commission_type'] === 'fixed' ? $values['amount_minor'] : null]);
            app(AffiliateRuleHistory::class)->record($program);
            $this->audit($organization, $store, $actor, 'affiliate_program_updated', $program, $old + ['default_rule' => $oldRule], $program->only(array_keys($old)) + ['default_rule' => $rule->only(array_keys($oldRule))]);
            app(AffiliateCouponDispatchService::class)->dispatch($store, programId: $program->id);

            return $program;
        });
    }

    public function replaceRules(Organization $organization, Store $store, User $actor, string $publicId, array $rules): void
    {
        $this->guard->actor($organization, $store, $actor, 'affiliate.programs.manage');
        DB::transaction(function () use ($organization, $store, $actor, $publicId, $rules) {
            $program = AffiliateProgram::query()->forOrganization($organization)->forStore($store)->where('public_id', $publicId)->lockForUpdate()->firstOrFail();
            abort_if($program->status->value === 'archived', 409);
            app(AffiliateRuleHistory::class)->baseline($program);
            $old = $program->rules()->where('scope', '!=', 'program')->get()->toArray();
            $catalog = [];
            foreach (['product' => [Product::class, 'shopify_product_id', 'Product'], 'variant' => [ProductVariant::class, 'shopify_variant_id', 'ProductVariant'], 'collection' => [ProductCollection::class, 'shopify_collection_id', 'Collection']] as $scope => [$model,$field,$type]) {
                $ids = collect($rules)->where('scope', $scope)->pluck('reference')->map(fn ($ref) => basename($ref))->all();
                $catalog[$scope] = app(AffiliateCatalogService::class)->scopedQuery($scope, $organization, $store)->whereIn($field, $ids)->get()->mapWithKeys(fn ($row) => ['gid://shopify/'.$type.'/'.$row->getAttribute($field) => $row->title])->all();
            }
            $program->rules()->where('scope', '!=', 'program')->delete();
            foreach ($rules as $index => $rule) {
                abort_unless($rule['scope'] === 'tier' || isset($catalog[$rule['scope']][$rule['reference']]), 422, '请选择本店已同步的商品或系列。');
                $program->rules()->create(['organization_id' => $organization->id, 'store_id' => $store->id,
                    'scope' => $rule['scope'], 'scope_reference' => $rule['reference'], 'priority' => $index,
                    'commission_type' => $rule['type'], 'rate_basis_points' => $rule['type'] === 'percentage' ? $rule['basis_points'] : null,
                    'amount_minor' => $rule['type'] === 'fixed' ? $rule['amount_minor'] : null, 'enabled' => true,
                    'settings' => ['label' => $catalog[$rule['scope']][$rule['reference']] ?? $rule['reference'], 'exclude' => (bool) ($rule['exclude'] ?? false), 'fixed_mode' => $rule['fixed_mode'] ?? 'order']]);
            }
            app(AffiliateRuleHistory::class)->record($program);
            $this->audit($organization, $store, $actor, 'affiliate_rules_updated', $program, ['rules' => $old], ['rules' => $rules]);
        });
    }

    public function updateMembership(Organization $org, Store $store, User $actor, string $publicId, array $values): void
    {
        $this->guard->actor($org, $store, $actor, 'affiliate.promoters.manage');
        DB::transaction(function () use ($org, $store, $actor, $publicId, $values) {
            $member = AffiliateProgramMembership::query()->forOrganization($org)->forStore($store)->where('public_id', $publicId)->lockForUpdate()->firstOrFail();
            $old = $member->only(['tier_key', 'commission_override', 'labels']);
            $member->forceFill(['tier_key' => $values['tier_key'] ?? null, 'commission_override' => $values['commission_override'] ?? null,
                'labels' => array_values(array_unique($values['labels'] ?? [])), 'admin_notes' => $values['admin_notes'] ?? null])->save();
            $this->audit($org, $store, $actor, 'affiliate_membership_updated', $member, $old, $member->only(array_keys($old)));
        });
    }

    public function __construct(private AffiliateAssetService $assets, private AffiliateShopGuard $guard) {}

    public function transitionProgram(Organization $organization, Store $store, User $actor, string $publicId, string $action): AffiliateProgram
    {
        $this->guard->actor($organization, $store, $actor, 'affiliate.programs.manage');

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
            app(AffiliateCouponDispatchService::class)->dispatch($store, programId: $program->id);

            return $program;
        });
    }

    public function transitionMembership(Organization $organization, Store $store, User $actor, string $publicId, string $action, ?string $reason = null): AffiliateProgramMembership
    {
        $this->guard->actor($organization, $store, $actor, 'affiliate.promoters.manage');

        $verifiedCustomer = null;
        if ($action === 'approve') {
            $candidate = AffiliateProgramMembership::query()->forOrganization($organization)->forStore($store)->where('public_id', $publicId)->with('program', 'promoter')->firstOrFail();
            if ($candidate->program->type->value === 'advocate') {
                $eligibility = app(AffiliateCustomerEligibilityService::class)->purchasedCustomer($store, $candidate->promoter->email_encrypted);
                abort_unless($eligibility['eligible'], 422, '仅已核验购买记录的顾客可以成为推荐人。');
                $verifiedCustomer = $eligibility['customer_id'];
            }
        }

        return DB::transaction(function () use ($organization, $store, $actor, $publicId, $action, $reason, $verifiedCustomer): AffiliateProgramMembership {
            $membership = AffiliateProgramMembership::query()->forOrganization($organization)->forStore($store)
                ->where('public_id', $publicId)->lockForUpdate()->firstOrFail();
            $from = $membership->status->value;
            $to = match ($action) {
                'approve' => in_array($from, ['pending', 'waitlisted', 'suspended', 'rejected'], true) ? 'approved' : null,
                'suspend' => $from === 'approved' ? 'suspended' : null,
                'reject' => in_array($from, ['pending', 'waitlisted'], true) ? 'rejected' : null,
                'waitlist' => $from === 'pending' ? 'waitlisted' : null,
                default => null,
            };
            abort_unless($to !== null, 409, '当前推广者状态不允许执行此操作。');
            abort_if($to === 'rejected' && mb_strlen(trim($reason ?? '')) < 3, 422, '请填写拒绝原因。');
            $membership->forceFill([
                'rejection_reason' => $to === 'rejected' ? trim($reason) : null,
                'shopify_customer_id' => $verifiedCustomer ?? $membership->shopify_customer_id,
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
            if (in_array($to, ['approved', 'rejected'], true)) {
                app(AffiliateNotificationService::class)->intent($membership, 'promoter.'.($to === 'approved' ? 'approved' : 'rejected'), 'membership:'.$membership->public_id.':'.$to.':'.$membership->updated_at->timestamp);
            }
            $this->audit($organization, $store, $actor, 'affiliate_membership_status_changed', $membership, ['status' => $from], ['status' => $to]);
            app(AffiliateCouponDispatchService::class)->dispatch($store, membershipId: $membership->id);

            return $membership;
        });
    }

    /** @param array<string, mixed> $values */
    public function updateSettings(Organization $organization, Store $store, User $actor, array $values): AffiliateStoreSetting
    {
        $this->guard->actor($organization, $store, $actor, 'affiliate.settings.manage');

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
            app(AffiliateCouponDispatchService::class)->dispatch($store);

            return $settings;
        });
    }

    /** @param array<string, mixed> $values */
    public function createProgram(Organization $organization, Store $store, User $actor, array $values): AffiliateProgram
    {
        $this->guard->actor($organization, $store, $actor, 'affiliate.programs.manage');

        $values = $this->schedule($values);

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
                'starts_at' => $values['starts_at'], 'ends_at' => $values['ends_at'],
                'currency' => strtoupper((string) ($store->currency ?: 'USD')),
                'coupon_enabled' => (bool) $values['coupon_enabled'],
                'customer_discount_type' => $values['coupon_enabled'] ? $values['customer_discount_type'] : null,
                'customer_discount_rate_basis_points' => $values['coupon_enabled'] && $values['customer_discount_type'] === 'percentage' ? (int) $values['customer_discount_rate_basis_points'] : null,
                'customer_discount_amount_minor' => $values['coupon_enabled'] && $values['customer_discount_type'] === 'fixed' ? (int) $values['customer_discount_amount_minor'] : null,
                'settings' => $this->rewardSettings($organization, $store, $actor, $values, ['customer_scope' => 'store']),
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
            app(AffiliateRuleHistory::class)->record($program);
            $this->audit($organization, $store, $actor, 'affiliate_program_created', $program, null, ['public_id' => $program->public_id, 'name' => $program->name]);

            return $program;
        });
    }

    /** @param array<string, mixed> $values */
    public function createPromoter(Organization $organization, Store $store, User $actor, array $values): AffiliatePromoter
    {
        $this->guard->actor($organization, $store, $actor, 'affiliate.promoters.manage');

        return DB::transaction(function () use ($organization, $store, $actor, $values): AffiliatePromoter {
            $email = mb_strtolower(trim((string) $values['email']));
            $emailHash = hash_hmac('sha256', $organization->id.'|'.$email, (string) config('app.key'));
            $promoter = AffiliatePromoter::withTrashed()->firstOrNew([
                'organization_id' => $organization->id,
                'email_hash' => $emailHash,
            ]);
            abort_if($promoter->exists && $promoter->trashed(), 409, '该组织推广者已归档，请由组织管理员处理。');
            if (! $promoter->exists) {
                $promoter->fill(['email_encrypted' => $email, 'display_name' => trim((string) $values['display_name']),
                    'type' => $values['type'], 'status' => 'active', 'created_by' => $actor->id, 'updated_by' => $actor->id])->save();
            }

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

    private function rewardSettings(Organization $org, Store $store, User $actor, array $values, array $existing): array
    {
        if ($values['type'] !== 'advocate') {
            return $existing;
        }
        if (array_key_exists('auto_invite', $values)) {
            Validator::make($values, ['auto_invite' => ['boolean']])->validate();
            $existing['auto_invite'] = (bool) $values['auto_invite'];
        }
        $rules = app(AffiliateRewardRules::class);
        $existing['reward'] = $rules->validate($org, $store, $actor, $values['reward'] ?? $existing['reward'] ?? []);
        $milestones = $values['milestones'] ?? $existing['milestones'] ?? [];
        Validator::make(['milestones' => $milestones], ['milestones' => ['array', 'max:20'], 'milestones.*.threshold' => ['required', 'integer', 'between:1,10000', 'distinct'], 'milestones.*.reward' => ['required', 'array']])->validate();
        $existing['milestones'] = array_map(fn ($m) => ['threshold' => (int) $m['threshold'], 'reward' => $rules->validate($org, $store, $actor, $m['reward'])], $milestones);

        return $existing;
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

    private function schedule(array $values, ?AffiliateProgram $program = null): array
    {
        $dates = ['starts_at' => $values['starts_at'] ?? null, 'ends_at' => $values['ends_at'] ?? null];
        foreach (array_keys($dates) as $key) {
            if (! array_key_exists($key, $values)) {
                $dates[$key] = $program?->getAttribute($key)?->toIso8601String();
            }
        }
        Validator::make($dates, ['starts_at' => ['nullable', 'date'], 'ends_at' => ['nullable', 'date']])->validate();
        foreach ($dates as $key => $value) {
            $dates[$key] = $value ? CarbonImmutable::parse($value)->utc() : null;
        }
        if ($dates['starts_at'] && $dates['ends_at'] && $dates['ends_at']->lte($dates['starts_at'])) {
            throw ValidationException::withMessages(['ends_at' => '结束时间必须晚于开始时间。']);
        }

        return array_merge($values, $dates);
    }
}
