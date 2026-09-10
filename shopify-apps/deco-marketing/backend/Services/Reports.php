<?php

namespace DecoMarketing\Services;

use App\Models\Store;
use DecoMarketing\Models\Attribution;
use DecoMarketing\Models\Campaign;
use DecoMarketing\Models\CheckoutSnapshot;
use DecoMarketing\Models\Contact;
use DecoMarketing\Models\Delivery;
use DecoMarketing\Models\Enrollment;
use DecoMarketing\Models\Flow;
use DecoMarketing\Models\Waitlist;

class Reports
{
    /** Explicit store scope on every query; test and preview messages never become production KPIs. */
    public function overview(Store $store): array
    {
        app(Guard::class)->store($store);
        $since = now()->subDays(30);
        $deliveries = Delivery::forStore($store)->where('kind', 'automation')->where('sent_at', '>=', $since);
        $totals = (clone $deliveries)->selectRaw('count(*) as sent, sum(case when opened_at is not null then 1 else 0 end) as opened, sum(case when human_opened_at is not null then 1 else 0 end) as human_opened, sum(case when clicked_at is not null then 1 else 0 end) as clicked')->first();
        $grouped = (clone $deliveries)->join('marketing_enrollments as e', 'e.id', '=', 'marketing_deliveries.enrollment_id')
            ->selectRaw('e.flow_key, count(*) as sent, sum(case when opened_at is not null then 1 else 0 end) as opened, sum(case when human_opened_at is not null then 1 else 0 end) as human_opened, sum(case when clicked_at is not null then 1 else 0 end) as clicked')->groupBy('e.flow_key')->get()->keyBy('flow_key');
        $activity = Enrollment::forStore($store)->where('status', 'active')->selectRaw('flow_key, count(*) as total')->groupBy('flow_key')->pluck('total', 'flow_key');
        $failures = Delivery::forStore($store)->where('kind', 'automation')->where('marketing_deliveries.created_at', '>=', $since)
            ->whereIn('marketing_deliveries.status', ['failed', 'held', 'uncertain'])->join('marketing_enrollments as e', 'e.id', '=', 'marketing_deliveries.enrollment_id')
            ->selectRaw('e.flow_key, count(*) as total')->groupBy('e.flow_key')->pluck('total', 'flow_key');
        $suppressed = Enrollment::forStore($store)->where('updated_at', '>=', $since)->whereIn('stop_reason', ['suppressed', 'unsubscribed', 'smart_sending'])->selectRaw('flow_key, count(*) as total')->groupBy('flow_key')->pluck('total', 'flow_key');
        $attributions = Attribution::forStore($store)->whereIn('delivery_id', Delivery::forStore($store)->where('kind', 'automation')->whereNotNull('sent_at')->select('id'))->where('marketing_attributions.ordered_at', '>=', $since)->whereIn('marketing_attributions.status', ['paid', 'partially_refunded']);
        $revenue = (clone $attributions)->selectRaw('currency, sum(revenue) as revenue, count(*) as orders')->groupBy('currency')->get()->map(fn ($row) => ['currency' => $row->currency, 'revenue' => (float) $row->revenue, 'orders' => (int) $row->orders])->all();
        $byFlow = (clone $attributions)->join('marketing_deliveries as d', 'd.id', '=', 'marketing_attributions.delivery_id')
            ->join('marketing_enrollments as e', 'e.id', '=', 'd.enrollment_id')->where('d.kind', 'automation')
            ->selectRaw('e.flow_key, marketing_attributions.currency, sum(marketing_attributions.revenue) as revenue, count(*) as orders')
            ->groupBy('e.flow_key', 'marketing_attributions.currency')->get()->map(fn ($row) => ['flow' => $row->flow_key, 'currency' => $row->currency, 'revenue' => (float) $row->revenue, 'orders' => (int) $row->orders])->all();
        $flows = Flow::forStore($store)->orderBy('id')->get()->map(function ($flow) use ($grouped, $activity, $failures, $suppressed) {
            $counts = $grouped[$flow->key] ?? null;

            return ['key' => $flow->key, 'name' => $flow->name, 'enabled' => $flow->enabled, 'steps' => count($flow->steps), 'active' => (int) ($activity[$flow->key] ?? 0), 'sent' => (int) ($counts?->sent ?? 0), 'opened' => (int) ($counts?->opened ?? 0), 'human_opened' => (int) ($counts?->human_opened ?? 0), 'clicked' => (int) ($counts?->clicked ?? 0), 'failed' => (int) ($failures[$flow->key] ?? 0), 'suppressed' => (int) ($suppressed[$flow->key] ?? 0)];
        })->all();
        $recent = Enrollment::forStore($store)->with('contact')->latest('id')->limit(10)->get()->map(fn ($e) => ['uuid' => $e->uuid, 'flow' => $e->flow_key, 'email' => $e->contact?->email_encrypted, 'source' => $e->source_key, 'step' => $e->step, 'steps' => count($e->steps), 'status' => $e->status, 'stop_reason' => $e->stop_reason, 'next_at' => $e->next_at?->toIso8601String()])->all();
        $sent = (int) $totals->sent;

        return ['since' => $since->toIso8601String(), 'contacts' => Contact::forStore($store)->count(), 'subscribed' => Contact::forStore($store)->where('consent', 'subscribed')->where('suppressed', false)->count(),
            'sent' => $sent, 'automation_sent' => $sent - (int) ($grouped->get('campaign')?->sent ?? 0), 'campaign_sent' => (int) ($grouped->get('campaign')?->sent ?? 0),
            'raw_open_rate' => $sent ? round((int) $totals->opened * 100 / $sent, 1) : 0, 'human_open_rate' => (clone $deliveries)->whereNotNull('open_classifier')->exists() && $sent ? round((int) $totals->human_opened * 100 / $sent, 1) : null,
            'click_rate' => $sent ? round((int) $totals->clicked * 100 / $sent, 1) : 0, 'revenue' => $revenue, 'attributed_orders' => array_sum(array_column($revenue, 'orders')), 'revenue_by_flow' => $byFlow,
            'abandoned_7d' => CheckoutSnapshot::forStore($store)->where('occurred_at', '>=', now()->subDays(7))->whereNull('completed_at')->count(),
            'waiting' => Waitlist::forStore($store)->where('status', 'waiting')->count(), 'flows' => $flows, 'recent' => $recent,
            'campaigns' => $this->campaignMetrics($store, Campaign::forStore($store)->where('started_at', '>=', $since)->pluck('id')->all(), $since),
            'open_classification_available' => true];
    }

    public function campaignMetrics(Store $store, array $ids, $since = null): array
    {
        app(Guard::class)->store($store);
        if (! $ids) {
            return [];
        }
        $totals = Enrollment::forStore($store)->whereIn('campaign_id', $ids)->selectRaw('campaign_id, count(*) as audience')->groupBy('campaign_id')->pluck('audience', 'campaign_id');
        $counts = Delivery::forStore($store)->where('kind', 'automation')->whereNotNull('sent_at')->when($since, fn ($q) => $q->where('sent_at', '>=', $since))
            ->join('marketing_enrollments as e', 'e.id', '=', 'marketing_deliveries.enrollment_id')->whereIn('e.campaign_id', $ids)
            ->selectRaw('e.campaign_id, count(*) as sent, sum(case when opened_at is not null then 1 else 0 end) as opened, sum(case when human_opened_at is not null then 1 else 0 end) as human_opened, sum(case when clicked_at is not null then 1 else 0 end) as clicked')->groupBy('e.campaign_id')->get()->keyBy('campaign_id');
        $campaigns = Campaign::forStore($store)->whereIn('id', $ids)->get();
        $sizes = [];
        foreach ($campaigns->pluck('audience')->unique() as $audience) {
            $sizes[$audience] = app(Audiences::class)->query($store, $audience)->count();
        }

        return $campaigns->mapWithKeys(fn ($c) => [$c->id => ['name' => $c->name, 'audience' => $c->started_at ? (int) ($totals[$c->id] ?? 0) : (int) ($sizes[$c->audience] ?? 0), 'sent' => (int) (($counts[$c->id] ?? null)?->sent ?? 0), 'opened' => (int) (($counts[$c->id] ?? null)?->opened ?? 0), 'clicked' => (int) (($counts[$c->id] ?? null)?->clicked ?? 0), 'human_opened' => (int) (($counts[$c->id] ?? null)?->human_opened ?? 0)]])->all();
    }
}
