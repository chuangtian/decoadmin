<?php

namespace DecoMarketing\Services;

use App\Models\Store;
use Carbon\CarbonImmutable;
use DecoMarketing\Models\Contact;
use DecoMarketing\Models\Delivery;
use DecoMarketing\Models\Event;
use Illuminate\Support\Facades\DB;

class Events
{
    public function resend(Store $store, Event $event): void
    {
        app(Guard::class)->store($store);
        abort_unless($event->store_id === $store->id && $event->organization_id === $store->organization_id, 403);
        if ($event->processed_at) {
            return;
        }
        $payload = $event->payload_encrypted;
        $delivery = Delivery::forStore($store)->whereNotNull('provider_id')->where('provider_id', $payload['provider_id'] ?? '')->first();
        if (! $delivery && is_string($payload['delivery'] ?? null)) {
            $delivery = Delivery::forStore($store)->where('uuid', $payload['delivery'])->whereIn('status', ['sending', 'uncertain', 'held'])->first();
        }
        if (! $delivery) {
            return;
        } // Kept for reconciliation when a send response has not arrived yet.
        DB::transaction(function () use ($event, $delivery, $payload) {
            $delivery = Delivery::whereKey($delivery->id)->lockForUpdate()->firstOrFail();
            $at = CarbonImmutable::parse($payload['occurred_at'] ?? now());
            $values = [];
            switch ($event->type) {
                case 'email.sent': case 'email.delivered': case 'email.opened': case 'email.clicked':
                    $values['sent_at'] = $delivery->sent_at ?? $at;
                    if (! in_array($delivery->status, ['bounced', 'complained'])) {
                        $values['status'] = $event->type === 'email.delivered' || $delivery->delivered_at ? 'delivered' : 'sent';
                    }
                    if ($event->type === 'email.delivered') {
                        $values['delivered_at'] = $delivery->delivered_at ?? $at;
                    }
                    if ($event->type === 'email.opened') {
                        $values['opened_at'] = $delivery->opened_at ?? $at;
                    }
                    if ($event->type === 'email.clicked') {
                        $values['clicked_at'] = $delivery->clicked_at ?? $at;
                    }
                    break;
                case 'email.bounced': case 'email.complained':
                    $values['status'] = $event->type === 'email.complained' ? 'complained' : 'bounced';
                    $values['sent_at'] = $delivery->sent_at ?? $at;
                    app(Contacts::class)->suppress($delivery->contact, $values['status']);
                    break;
            }
            if ($values) {
                $values['provider_id'] = $delivery->provider_id ?? $payload['provider_id'];
                $values['lease_until'] = null;
                $delivery->update($values);
                $contact = $delivery->contact;
                if (! $contact->last_sent_at || $contact->last_sent_at->lt($at)) {
                    $contact->update(['last_sent_at' => $at]);
                }
                if ($delivery->enrollment && $delivery->enrollment->status === 'held' && $delivery->enrollment->stop_reason === 'uncertain_requires_review') {
                    $delivery->enrollment->update(['status' => 'active', 'next_at' => now(), 'stop_reason' => null]);
                }
            }
            $event->update(['processed_at' => now()]);
        });
    }

    public function process(Store $store): void
    {
        app(Guard::class)->store($store);
        foreach (Event::forStore($store)->whereNull('processed_at')->where(fn ($q) => $q->whereNull('retry_at')->orWhere('retry_at', '<=', now()))->orderBy('id')->limit(5)->get() as $event) {
            try {
                if ($event->provider === 'resend') {
                    $this->resend($store, $event);

                    continue;
                }
                $p = $event->payload_encrypted;
                if (str_starts_with($event->type, 'customers/')) {
                    $id = $p['resource_id'] ?? 'gid://shopify/Customer/'.($p['numeric_id'] ?? '');
                    if ($event->type === 'customers/delete') {
                        foreach (Contact::forStore($store)->where('customer_id', $id)->get() as $c) {
                            app(Contacts::class)->suppress($c, 'customer_deleted');
                        }
                    } else {
                        app(Shopify::class)->syncCustomer($store, $id);
                    }
                } elseif (str_starts_with($event->type, 'orders/') || str_starts_with($event->type, 'refunds/') || str_starts_with($event->type, 'fulfillments/')) {
                    $id = str_starts_with($event->type, 'orders/') ? ($p['resource_id'] ?? 'gid://shopify/Order/'.$p['numeric_id']) : 'gid://shopify/Order/'.($p['order_id'] ?? '');
                    app(Shopify::class)->syncOrder($store, $id);
                } elseif ($event->type === 'inventory_levels/update') {
                    app(Shopify::class)->syncWaitlist($store);
                }
                $event->update(['processed_at' => now()]);
            } catch (\Throwable) {
                $event->update(['attempts' => $event->attempts + 1, 'retry_at' => now()->addMinutes(min(60, 5 * ($event->attempts + 1)))]);
            }
        }
    }
}
