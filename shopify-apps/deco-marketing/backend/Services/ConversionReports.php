<?php

namespace DecoMarketing\Services;

use App\Models\Store;
use DecoMarketing\Models\Contact;
use DecoMarketing\Models\Delivery;
use DecoMarketing\Models\Enrollment;
use DecoMarketing\Models\OrderSnapshot;

class ConversionReports
{
    public function report(Store $store): array
    {
        app(Guard::class)->store($store);
        $sentPayment = Delivery::forStore($store)->where('kind', 'automation')->where('sent_at', '>=', now()->subDays(30))
            ->whereHas('enrollment', fn ($q) => $q->where('flow_key', 'payment'))->pluck('enrollment_id');
        $covered = Enrollment::forStore($store)->whereIn('id', $sentPayment)->pluck('source_key');
        $clickedEnrollments = Delivery::forStore($store)->where('kind', 'automation')->whereNotNull('sent_at')->whereNotNull('clicked_at')
            ->whereIn('enrollment_id', $sentPayment)->pluck('enrollment_id');
        $clickedOrders = Enrollment::forStore($store)->whereIn('id', $clickedEnrollments)->pluck('source_key')->flip();
        $coverage = OrderSnapshot::forStore($store)->whereIn('order_id', $covered)->get()->groupBy('currency')->map(function ($rows, $currency) use ($clickedOrders) {
            $paid = $rows->whereNull('cancelled_at')->whereIn('financial_status', ['PAID', 'PARTIALLY_REFUNDED']);
            $pending = $rows->whereNull('cancelled_at')->whereIn('financial_status', ['PENDING', 'PARTIALLY_PAID']);

            $comparison = ['clicked' => ['people' => 0, 'converted' => 0], 'not_clicked' => ['people' => 0, 'converted' => 0]];
            foreach ($rows as $order) {
                $group = $clickedOrders->has($order->order_id) ? 'clicked' : 'not_clicked';
                $comparison[$group]['people']++;
                if (!$order->cancelled_at && in_array($order->financial_status, ['PAID', 'PARTIALLY_REFUNDED'], true)) $comparison[$group]['converted']++;
            }
            foreach ($comparison as &$group) $group['rate'] = $group['people'] ? round(100 * $group['converted'] / $group['people'], 2) : null;
            unset($group);
            return ['comparison' => $comparison, 'p_value' => $this->pValue($comparison['clicked'], $comparison['not_clicked']), 'currency' => $currency, 'covered' => $rows->count(), 'paid_orders' => $paid->count(), 'paid_amount' => round($paid->sum(fn ($r) => (float) $r->total - (float) $r->refunded), 2),
                'pending_orders' => $pending->count(), 'outstanding' => $pending->whereNull('outstanding')->isNotEmpty() ? null : round($pending->sum('outstanding'), 2)];
        })->values()->all();
        $groups = ['received' => ['people' => 0, 'converted' => 0, 'total_converted' => 0], 'not_received' => ['people' => 0, 'converted' => 0, 'total_converted' => 0]];
        Contact::forStore($store)->whereNotNull('first_subscribed_at')->where('first_subscribed_at', '<=', now()->subDays(14))->where('first_subscribed_at', '>=', now()->subDays(44))
            ->chunkById(500, function ($contacts) use ($store, &$groups) {
                $orders = OrderSnapshot::forStore($store)->whereIn('contact_id', $contacts->pluck('id'))->whereIn('financial_status', ['PAID', 'PARTIALLY_REFUNDED'])->whereNull('cancelled_at')->get()->groupBy('contact_id');
                $welcome = Delivery::forStore($store)->where('kind', 'automation')->whereNotNull('sent_at')->whereIn('contact_id', $contacts->pluck('id'))->whereHas('enrollment', fn ($q) => $q->where('flow_key', 'welcome'))->get(['contact_id', 'sent_at'])->groupBy('contact_id');
                foreach ($contacts as $c) {
                    $start = $c->first_subscribed_at;
                    $end = $start->copy()->addDays(14);
                    $received = $welcome->get($c->id, collect())->contains(fn ($d) => $d->sent_at->gte($start) && $d->sent_at->lt($end));
                    $g = $received ? 'received' : 'not_received';
                    $groups[$g]['people']++;
                    $paid = $orders->get($c->id, collect())->filter(fn ($o) => $o->ordered_at->gte($start) && $o->ordered_at->lt($end))->sortBy('ordered_at')->first();
                    if ($paid) {
                        $groups[$g]['total_converted']++;
                        if ($paid->ordered_at->gte($start->copy()->addDay())) {
                            $groups[$g]['converted']++;
                        }
                    }
                }
            });
        foreach ($groups as &$g) {
            $g['rate'] = $g['people'] ? round(100 * $g['converted'] / $g['people'], 2) : null;
            $g['total_rate'] = $g['people'] ? round(100 * $g['total_converted'] / $g['people'], 2) : null;
        }
        unset($g);

        return ['coverage' => $coverage, 'cohort' => $groups, 'difference_pp' => $groups['received']['rate'] !== null && $groups['not_received']['rate'] !== null ? round($groups['received']['rate'] - $groups['not_received']['rate'], 2) : null,
            'p_value' => $this->pValue($groups['received'], $groups['not_received']), 'cohort_start' => now()->subDays(44)->toDateString(), 'cohort_end' => now()->subDays(14)->toDateString()];
    }

    private function pValue(array $a, array $b): ?float
    {
        if (! $a['people'] || ! $b['people']) {
            return null;
        }
        $p = ($a['converted'] + $b['converted']) / ($a['people'] + $b['people']);
        // Normal approximation is not reported with sparse expected cells.
        if (min($a['people'] * $p, $b['people'] * $p, $a['people'] * (1 - $p), $b['people'] * (1 - $p)) < 5) {
            return null;
        }
        $z = abs($a['converted'] / $a['people'] - $b['converted'] / $b['people']) / sqrt($p * (1 - $p) * (1 / $a['people'] + 1 / $b['people']));
        $t = 1 / (1 + 0.2316419 * $z);
        $tail = exp(-$z * $z / 2) / sqrt(2 * M_PI) * ($t * (0.319381530 + $t * (-0.356563782 + $t * (1.781477937 + $t * (-1.821255978 + $t * 1.330274429)))));

        return round(min(1,2 * $tail),4);
    }
}
