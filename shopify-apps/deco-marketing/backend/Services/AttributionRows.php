<?php

namespace DecoMarketing\Services;

use App\Models\Store;
use DecoMarketing\Models\Attribution;
use DecoMarketing\Models\Contact;
use DecoMarketing\Models\Delivery;
use DecoMarketing\Models\Enrollment;
use DecoMarketing\Models\OrderSnapshot;

class AttributionRows
{
    public function page(Store $store, int $days)
    {
        app(Guard::class)->store($store);
        $rows = Attribution::forStore($store)->where('ordered_at', '>=', now()->subDays($days))->latest('ordered_at')->paginate(25)->withQueryString();
        $deliveries = Delivery::forStore($store)->whereIn('id', $rows->pluck('delivery_id'))->get()->keyBy('id');
        $contacts = Contact::forStore($store)->whereIn('id', $deliveries->pluck('contact_id'))->get()->keyBy('id');
        $enrollments = Enrollment::forStore($store)->whereIn('id', $deliveries->pluck('enrollment_id')->filter())->get()->keyBy('id');
        $orders = OrderSnapshot::forStore($store)->whereIn('order_id', $rows->pluck('order_id'))->get()->keyBy('order_id');
        $pending = OrderSnapshot::forStore($store)->whereIn('contact_id', $contacts->keys())->whereNull('cancelled_at')->whereIn('financial_status', ['PENDING', 'PARTIALLY_PAID'])
            ->selectRaw('contact_id, currency, COUNT(*) as count, SUM(outstanding) as amount')->groupBy('contact_id', 'currency')->get()->groupBy('contact_id');

        return $rows->through(function ($row) use ($deliveries, $contacts, $enrollments, $orders, $pending) {
            $delivery = $deliveries->get($row->delivery_id);
            return ['uuid' => $row->uuid, 'order_id' => $row->order_id, 'order_name' => $orders->get($row->order_id)?->name ?? $row->order_id,
                'revenue' => $row->revenue, 'currency' => $row->currency, 'status' => $row->status, 'ordered_at' => $row->ordered_at?->toIso8601String(),
                'email' => $contacts->get($delivery?->contact_id)?->email_encrypted, 'subject' => $delivery?->payload_encrypted['subject'] ?? '',
                'flow' => $enrollments->get($delivery?->enrollment_id)?->flow_key ?? $delivery?->kind,
                'click_minutes' => $delivery?->clicked_at ? max(0, (int) $delivery->clicked_at->diffInMinutes($row->ordered_at)) : null,
                'pending' => ($pending->get($delivery?->contact_id) ?? collect())->map(fn ($p) => ['count' => (int) $p->count, 'amount' => $p->amount, 'currency' => $p->currency])->values(),
            ];
        });
    }
}
