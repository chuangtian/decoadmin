<?php

namespace DecoMarketing\Services;

use App\Models\Store;
use Carbon\CarbonImmutable;
use DecoMarketing\Models\Campaign;
use DecoMarketing\Models\Delivery;
use DecoMarketing\Models\Settings;

class Health
{
    /** Only signed email-link withdrawals are represented by unsubscribed_at. */
    public function campaign(Store $store, Campaign $campaign): array
    {
        app(Guard::class)->store($store);
        abort_unless($campaign->organization_id === $store->organization_id && $campaign->store_id === $store->id, 403);
        $q = Delivery::forStore($store)->where('kind', 'automation')->whereNotNull('sent_at')
            ->whereHas('enrollment', fn ($q) => $q->where('campaign_id', $campaign->id));
        $sent = (clone $q)->count();
        $unsubscribed = $this->withdrawals(clone $q, $store)->distinct()->count('marketing_deliveries.contact_id');

        return ['uuid' => $campaign->uuid, 'name' => $campaign->name, 'sent' => $sent, 'unsubscribed' => $unsubscribed,
            'rate' => $sent ? round(100 * $unsubscribed / $sent, 3) : 0,
            'eligible' => $sent >= 500, 'exceeded' => $sent >= 500 && $unsubscribed * 200 > $sent];
    }

    private function withdrawals($query, Store $store)
    {
        // Correlate by recipient: a later withdrawal via another marketing email still counts.
        // Database-specific date arithmetic is avoided by comparing the bounded cohort in PHP below.
        return $query->whereExists(function ($q) use ($store) {
            $q->selectRaw('1')->from('marketing_deliveries as withdrawal')
                ->where('withdrawal.organization_id', $store->organization_id)->where('withdrawal.store_id', $store->id)
                ->where('withdrawal.kind', 'automation')->whereNotNull('withdrawal.sent_at')
                ->whereColumn('withdrawal.contact_id', 'marketing_deliveries.contact_id')
                ->whereColumn('withdrawal.unsubscribed_at', '>=', 'marketing_deliveries.sent_at');
        });
    }

    public function report(Store $store): array
    {
        app(Guard::class)->store($store);
        $now = CarbonImmutable::now();
        $start = $now->setTimezone('America/Los_Angeles')->startOfDay()->subDay()->utc();
        $end = $start->setTimezone('America/Los_Angeles')->addDay()->utc();
        $q = Delivery::forStore($store)->where('kind', 'automation');
        $yesterday = (clone $q)->where('sent_at', '>=', $start)->where('sent_at', '<', $end);
        $sent = (clone $yesterday)->count();
        $recipients = (clone $yesterday)->distinct()->count('contact_id');
        $unsubscribed = [];
        // Batch recipient withdrawals; never issue one query per delivery.
        (clone $yesterday)->select(['id', 'contact_id', 'sent_at'])->chunkById(500, function ($deliveries) use ($q, &$unsubscribed) {
            $withdrawals = (clone $q)->whereIn('contact_id', $deliveries->pluck('contact_id'))->whereNotNull('sent_at')
                ->whereNotNull('unsubscribed_at')->get(['contact_id', 'unsubscribed_at'])->groupBy('contact_id');
            foreach ($deliveries as $delivery) {
                foreach ($withdrawals->get($delivery->contact_id, collect()) as $withdrawal) {
                    if ($withdrawal->unsubscribed_at->betweenIncluded($delivery->sent_at, $delivery->sent_at->copy()->addHours(72))) {
                        $unsubscribed[$delivery->contact_id] = true;
                        break;
                    }
                }
            }
        });
        $failed = (clone $q)->where('status', 'failed')->where('first_attempt_at', '>=', $start)->where('first_attempt_at', '<', $end)->count();
        $recent = (clone $q)->where('sent_at', '>=', $now->subDays(3));
        $recentSent = (clone $recent)->count();
        $settings = Settings::forStore($store)->first();

        return ['generated_at' => $now->toIso8601String(), 'timezone' => 'America/Los_Angeles', 'yesterday' => $start->setTimezone('America/Los_Angeles')->toDateString(),
            'sent' => $sent, 'failed' => $failed, 'recipients' => $recipients, 'unsubscribed' => count($unsubscribed),
            'failure_rate' => $sent + $failed ? round(100 * $failed / ($sent + $failed), 3) : null,
            'unsubscribe_rate' => $recipients ? round(100 * count($unsubscribed) / $recipients, 3) : null,
            'cohort_matures_at' => $end->addHours(72)->toIso8601String(),
            'recent_sent' => $recentSent, 'recent_raw_opened' => (clone $recent)->whereNotNull('opened_at')->count(),
            'recent_clicked' => (clone $recent)->whereNotNull('clicked_at')->count(), 'human_open_rate' => $recentSent && (clone $recent)->whereNotNull('open_classifier')->exists() ? round(100 * (clone $recent)->whereNotNull('human_opened_at')->count() / $recentSent, 2) : null,
            'human_open_eligible' => $recentSent >= 50,
            'campaigns' => Campaign::forStore($store)->where('status', 'sending')->orderBy('id')->get()->map(fn ($c) => $this->campaign($store, $c))->all(),
            'circuit_open' => app(Engine::class)->circuitOpen($store), 'daily_used' => $settings ? app(Engine::class)->dailyCount($store, $settings) : 0,
            'warmup' => app(Warmup::class)->report($store), 'scheduled' => (bool) config('marketing.scheduled')];
    }
}
