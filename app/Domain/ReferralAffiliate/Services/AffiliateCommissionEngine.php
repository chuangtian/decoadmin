<?php

namespace App\Domain\ReferralAffiliate\Services;

use App\Domain\ReferralAffiliate\Models\AffiliateProgramMembership;
use App\Domain\ReferralAffiliate\Models\AffiliateProgramRule;
use App\Domain\ReferralAffiliate\Support\Money;
use Carbon\CarbonImmutable;

class AffiliateCommissionEngine
{
    public function refundTotals(array $lines, array $snapshot, int $baseMinor, array $cumulative): array
    {
        $target = 0;
        $refundedBase = 0;
        foreach ($lines as $line) {
            $values = $cumulative[$line['shopify_line_id']] ?? ['base_minor' => 0, 'quantity' => 0];
            $base = min($line['base_minor'], $values['base_minor']);
            $quantity = min($line['quantity'], $values['quantity']);
            $refundedBase += $base;
            $perItem = data_get($line, 'rule_snapshot.rule.commission_type') === 'fixed' && data_get($line, 'rule_snapshot.rule.settings.fixed_mode') === 'item';
            $target += $perItem ? Money::proportional($line['commission_minor'], $quantity, max(1, $line['quantity'])) : Money::proportional($line['commission_minor'], $base, max(1, $line['base_minor']));
        }
        foreach ($snapshot['fixed_order'] ?? [] as $fixed) {
            $target += ($snapshot['fixed_refund_policy'] ?? 'full_refund_only') === 'proportional' ? Money::proportional($fixed['amount_minor'], $refundedBase, max(1, $baseMinor)) : ($refundedBase >= $baseMinor ? $fixed['amount_minor'] : 0);
        }

        return ['commission_minor' => $target, 'base_minor' => min($baseMinor, $refundedBase)];
    }

    /** Input consists of normalized, tax-exclusive, discount-allocated order lines. */
    public function calculate(AffiliateProgramMembership $membership, array $lines, ?CarbonImmutable $at = null): array
    {
        $membership->loadMissing('program.rules');
        $history = $at ? app(AffiliateRuleHistory::class)->at($membership->program, $at) : null;
        $rules = $history ? collect($history['rules'])->map(function ($attributes) {
            $rule = new AffiliateProgramRule;
            $rule->forceFill($attributes);

            return $rule;
        }) : $membership->program->rules;
        $rules = $rules->where('enabled', true);
        $override = $at ? app(AffiliateAttributionEngine::class)->valueAt($membership, 'commission_override', $at) : $membership->commission_override;
        $tier = $at ? app(AffiliateAttributionEngine::class)->valueAt($membership, 'tier_key', $at) : $membership->tier_key;
        $result = [];
        $fixed = [];
        foreach ($lines as $line) {
            $matches = $rules->filter(fn ($rule) => match ($rule->scope) {
                'variant' => $rule->scope_reference === ($line['variant_id'] ?? null),
                'product' => $rule->scope_reference === ($line['product_id'] ?? null),
                'collection' => in_array($rule->scope_reference, $line['collection_ids'] ?? [], true),
                'tier' => $rule->scope_reference === $tier,
                'program' => true,
                default => false,
            })->sortBy(fn ($rule) => [array_search($rule->scope, ['tier', 'variant', 'product', 'collection', 'program'], true), $rule->priority, $rule->id]);
            $match = $matches->first();
            $rule = $override ?: ($match ? $match->only(['id', 'scope', 'commission_type', 'rate_basis_points', 'amount_minor', 'settings']) : null);
            $base = max(0, (int) $line['base_minor']);
            $excluded = (bool) ($line['is_gift_card'] ?? false) || (bool) ($line['is_tip'] ?? false)
                || $matches->contains(fn ($candidate) => (bool) data_get($candidate->settings, 'exclude', false));
            if ($excluded) {
                $base = 0;
            }
            $commission = 0;
            if ($base > 0 && $rule) {
                if ($rule['commission_type'] === 'percentage') {
                    $bps = (int) ($rule['rate_basis_points'] ?? 0);
                    abort_unless($bps >= 0 && $bps <= 10000, 422);
                    $commission = Money::proportional($base, $bps, 10000);
                } elseif ($rule['commission_type'] === 'fixed') {
                    $amount = (int) ($rule['amount_minor'] ?? 0);
                    abort_unless($amount >= 0, 422);
                    if (data_get($rule, 'settings.fixed_mode', 'order') === 'item') {
                        $commission = Money::proportional($amount, (int) $line['quantity'], 1);
                    } else {
                        $key = (string) ($rule['id'] ?? 'membership_override');
                        $fixed[$key] = ['amount_minor' => $amount, 'rule' => $rule];
                    }
                }
            }
            $result[] = ['shopify_line_id' => $line['id'], 'quantity' => (int) $line['quantity'],
                'base_minor' => $base, 'commission_minor' => $commission,
                'rule_snapshot' => ['rule' => $rule, 'excluded' => $excluded,
                    'product_id' => $line['product_id'] ?? null, 'variant_id' => $line['variant_id'] ?? null,
                    'presentment' => $line['presentment'] ?? null]];
        }

        if ($membership->program->type->value === 'advocate') {
            foreach ($result as &$line) {
                $line['commission_minor'] = 0;
            }
            unset($line);
            $fixed = [];
        }

        return ['lines' => $result, 'base_minor' => array_sum(array_column($result, 'base_minor')),
            'commission_minor' => array_sum(array_column($result, 'commission_minor')) + array_sum(array_column($fixed, 'amount_minor')),
            'snapshot' => ['engine_version' => '1', 'currency' => $membership->program->currency,
                'hold_days' => $history['hold_days'] ?? $membership->program->hold_days,
                'reward' => data_get($history['settings'] ?? $membership->program->settings, 'reward'),
                'milestones' => data_get($history['settings'] ?? $membership->program->settings, 'milestones', []), 'fixed_order' => array_values($fixed),
                'fixed_refund_policy' => data_get($history['settings'] ?? $membership->program->settings, 'fixed_refund_policy', 'full_refund_only')]];
    }
}
