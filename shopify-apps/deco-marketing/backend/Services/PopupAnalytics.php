<?php

namespace DecoMarketing\Services;

use App\Models\Store;
use Carbon\CarbonImmutable;
use DecoMarketing\Models\Contact;
use DecoMarketing\Models\PopupEvent;
use Illuminate\Support\Facades\DB;

class PopupAnalytics
{
    public function report(Store $store, int $days = 30): array
    {
        app(Guard::class)->store($store);
        abort_unless(in_array($days, [7, 30, 90], true), 422);
        $end = CarbonImmutable::now('UTC')->startOfDay();
        $start = $end->subDays($days - 1);
        $visitors = PopupEvent::forStore($store)->whereBetween('day', [$start->toDateString(), $end->toDateString()])
            ->selectRaw("day, visitor_hash, MAX(CASE WHEN type = 'impression' THEN 1 ELSE 0 END) AS exposed, MAX(CASE WHEN type = 'submit' THEN 1 ELSE 0 END) AS submitted, MAX(CASE WHEN type = 'close' THEN 1 ELSE 0 END) AS closed")
            ->groupBy('day', 'visitor_hash');
        $daily = DB::query()->fromSub($visitors, 'visitors')->selectRaw('day, SUM(exposed) AS impressions, SUM(submitted) AS submissions, SUM(closed) AS closes, SUM(CASE WHEN exposed = 1 AND submitted = 0 AND closed = 0 THEN 1 ELSE 0 END) AS inactive')
            ->groupBy('day')->orderByDesc('day')->get()->map(function ($row) {
                $views = (int) $row->impressions;

                return ['day' => $row->day, 'impressions' => $views, 'submissions' => (int) $row->submissions, 'closes' => (int) $row->closes, 'inactive' => (int) $row->inactive,
                    'submit_rate' => $views ? round((int) $row->submissions * 100 / $views, 2) : 0,
                    'close_rate' => $views ? round((int) $row->closes * 100 / $views, 2) : 0];
            });
        $views = (int) $daily->sum('impressions');
        $submitted = (int) $daily->sum('submissions');
        $closed = (int) $daily->sum('closes');
        $inactive = (int) $daily->sum('inactive');
        $contacts = Contact::forStore($store)->whereIn('source', ['storefront', 'storefront_popup']);

        return ['days' => $days, 'start' => $start->toDateString(), 'end' => $end->toDateString(), 'timezone' => 'UTC',
            'subscribers' => (clone $contacts)->count(), 'new_subscribers' => (clone $contacts)->whereBetween('created_at', [$start, $end->addDay()->subMicrosecond()])->count(),
            'currently_subscribed' => (clone $contacts)->where('consent', 'subscribed')->where('suppressed', false)->count(),
            'impressions' => $views, 'submissions' => $submitted, 'closes' => $closed, 'inactive' => $inactive,
            'submit_rate' => $views ? round($submitted * 100 / $views, 2) : 0,
            'close_rate' => $views ? round($closed * 100 / $views, 2) : 0,
            'inactive_rate' => $views ? round($inactive * 100 / $views, 2) : 0,
            'daily' => $daily->filter(fn ($row) => $row['impressions'] > 0)->values()->all()];
    }
}
