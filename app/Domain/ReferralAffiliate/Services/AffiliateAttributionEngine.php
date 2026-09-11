<?php

namespace App\Domain\ReferralAffiliate\Services;

use App\Domain\ReferralAffiliate\Models\AffiliateCoupon;
use App\Domain\ReferralAffiliate\Models\AffiliateProgramMembership;
use App\Domain\ReferralAffiliate\Models\AffiliateStoreSetting;
use App\Models\AuditLog;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

class AffiliateAttributionEngine
{
    public function resolve(Store $store, array $order): array
    {
        app(AffiliateShopGuard::class)->store($store);
        $at = CarbonImmutable::parse($order['ordered_at']);
        $candidates = [];
        $settings = AffiliateStoreSetting::query()->where('organization_id', $store->organization_id)->where('store_id', $store->id)->first();
        if (! $settings || (! $this->valueAt($settings, 'affiliate_enabled', $at) && ! $this->valueAt($settings, 'customer_referral_enabled', $at))) {
            return ['membership' => null, 'reason' => 'feature_disabled', 'source' => 'none', 'candidates' => [], 'risks' => []];
        }
        $coupons = AffiliateCoupon::query()->where('organization_id', $store->organization_id)->where('store_id', $store->id)
            ->whereIn('normalized_code', array_map('strtoupper', $order['discount_codes']))->with('membership.program', 'membership.promoter')->get();
        foreach ($coupons as $coupon) {
            $historical = AuditLog::query()->where('organization_id', $store->organization_id)->where('store_id', $store->id)
                ->where('subject_type', AffiliateCoupon::class)->where('subject_id', $coupon->id)
                ->where('action', 'affiliate_coupon_synced')->where('created_at', '<=', $at)->orderByDesc('id')->first();
            $status = $historical ? data_get($historical->metadata, 'status')
                : ($coupon->last_synced_at?->lte($at) ? $coupon->status : null);
            if (in_array($status, ['active', 'scheduled'], true) && $this->eligible($coupon->membership, $store, $at, $order['currency'], $settings)) {
                $candidates[] = ['membership' => $coupon->membership, 'source' => 'coupon', 'coupon_id' => $coupon->id, 'confidence' => 100];
            }
        }
        $owners = collect($candidates)->pluck('membership.id')->unique();
        if ($owners->count() > 1) {
            return ['membership' => null, 'reason' => 'multiple_coupon_conflict', 'source' => 'coupon',
                'candidates' => $this->safeCandidates($candidates), 'risks' => ['multiple_coupon_conflict']];
        }
        $signals = array_unique(array_filter([$order['first_tracking_token'] ?? null, $order['last_tracking_token'] ?? null, $order['tracking_token'] ?? null]));
        $clicks = collect();
        foreach ($signals as $token) {
            $click = is_string($token) ? app(AffiliateTrackingTokenService::class)->verify($store, $token, $at) : null;
            if ($click && $this->eligible($click->membership, $store, $at, $order['currency'], $settings)) {
                $clicks->push($click);
            }
        }
        $clicks = $clicks->unique('id')->sortBy('occurred_at')->values();
        if ($clicks->isNotEmpty()) {
            $first = $clicks->first();
            $last = $clicks->last();
            $model = $this->valueAt($last->membership->program, 'attribution_model', $at);
            $selected = $model === 'first_click' ? $first : $last;
            $candidates[] = ['membership' => $selected->membership, 'source' => 'signed_cart_token', 'click_id' => $selected->id, 'confidence' => 95];
        }
        $winner = $candidates[0] ?? null;
        $membership = $winner['membership'] ?? null;
        $risks = [];
        $sourceClick = isset($winner['click_id'])
            ? $clicks->firstWhere('id', $winner['click_id'])
            : $clicks->filter(fn ($click) => $membership && (int) $click->membership_id === (int) $membership->id)->last();
        $evidence = [];
        if ($membership) {
            if ($membership->program->type->value === 'advocate') {
                $eligible = data_get($order, 'customer_eligibility.eligible');
                if ($eligible !== true) {
                    $risks[] = $eligible === false ? 'not_new_customer' : 'customer_history_requires_review';
                }
            }
            if ($membership->shopify_customer_id && ($order['customer_id'] ?? null) === $membership->shopify_customer_id) {
                $risks[] = 'self_customer_id';
            }
            if (($order['customer_email_hash'] ?? null) && hash_equals($membership->promoter->email_hash, $order['customer_email_hash'])) {
                $risks[] = 'same_email_hash';
            }
        }

        if ($membership) {
            $evidence = app(AffiliateRiskEngine::class)->evaluate($store, $membership, $order, $sourceClick);
            $risks = array_values(array_unique(array_merge($risks, array_keys($evidence))));
        }

        return ['membership' => $membership, 'source_click_id' => $sourceClick?->id, 'risk_evidence' => $evidence, 'source' => $winner['source'] ?? 'none',
            'reason' => $winner ? ($winner['source'] === 'coupon' ? 'coupon_wins' : 'signed_cart_token') : 'no_eligible_signal',
            'candidates' => $this->safeCandidates($candidates), 'risks' => $risks];
    }

    private function safeCandidates(array $candidates): array
    {
        return array_map(fn ($candidate) => array_replace($candidate, ['membership' => $candidate['membership']->public_id]), $candidates);
    }

    private function eligible(?AffiliateProgramMembership $membership, Store $store, CarbonImmutable $at, string $currency, AffiliateStoreSetting $settings): bool
    {
        if (! $membership || (int) $membership->store_id !== (int) $store->id || (int) $membership->organization_id !== (int) $store->organization_id) {
            return false;
        }
        $membership->loadMissing('program', 'promoter');
        $program = $membership->program;
        $starts = $program ? $this->valueAt($program, 'starts_at', $at) : null;
        $ends = $program ? $this->valueAt($program, 'ends_at', $at) : null;

        return $program && $membership->promoter && (int) $program->store_id === (int) $store->id
            && (int) $membership->promoter->organization_id === (int) $store->organization_id
            && $this->valueAt($settings, $program->type->value === 'advocate' ? 'customer_referral_enabled' : 'affiliate_enabled', $at)
            && $program->currency === $currency
            && $this->valueAt($membership, 'status', $at) === 'approved'
            && $this->valueAt($program, 'status', $at) === 'active'
            && $this->valueAt($membership->promoter, 'status', $at) === 'active'
            && (! $starts || CarbonImmutable::parse($starts)->lte($at))
            && (! $ends || CarbonImmutable::parse($ends)->gt($at));
    }

    public function valueAt(Model $record, string $field, CarbonImmutable $at): mixed
    {
        if ($record->created_at->gt($at)) {
            return null;
        }
        $query = AuditLog::query()->where('organization_id', $record->organization_id)
            ->where('subject_type', $record::class)->where('subject_id', $record->id)
            ->whereJsonContainsKey('new_values->'.$field);
        $before = (clone $query)->where('created_at', '<=', $at)->orderByDesc('id')->first();
        if ($before) {
            return data_get($before->new_values, $field);
        }
        $after = (clone $query)->where('created_at', '>', $at)->orderBy('id')->first();
        if ($after) {
            return data_get($after->old_values, $field);
        }
        $value = $record->getAttribute($field);

        return $value instanceof \BackedEnum ? $value->value : $value;
    }
}
