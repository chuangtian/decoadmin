<?php

namespace DecoMarketing\Services;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Store;
use DecoMarketing\Models\Contact;
use DecoMarketing\Models\OrderSnapshot;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SharedData
{
    /** Reuse shared snapshots; marketing owns consent and workflow history, never customer master data. */
    public function sync(Store $store, int $limit = 500, array $kinds = ['customers', 'orders']): array
    {
        app(Guard::class)->store($store);
        return Cache::lock('marketing:sync:'.$store->id, 180)->block(1, function () use ($store, $limit, $kinds) {
            $settings = app(Catalog::class)->settings($store);
            $counts = [];
            foreach (array_intersect_key(['customers' => Customer::class, 'orders' => Order::class], array_flip($kinds)) as $kind => $class) {
                $state = $settings->fresh()->sync_state ?? [];
                $key = 'shared_'.$kind;
                $checkpoint = $state[$key] ?? [];
                $until = $checkpoint['until'] ?? now()->format('Y-m-d H:i:s');
                $q = $class::query()->where('organization_id', $store->organization_id)->where('store_id', $store->id)->where('updated_at', '<=', $until);
                if (isset($checkpoint['cursor_at'])) {
                    $q->where(fn ($q) => $q->where('updated_at', '>', $checkpoint['cursor_at'])->orWhere(fn ($q) => $q->where('updated_at', $checkpoint['cursor_at'])->where('id', '>', $checkpoint['cursor_id'])));
                } elseif (!empty($checkpoint['since'])) {
                    $q->where('updated_at', '>=', $checkpoint['since']);
                }
                $rows = $q->orderBy('updated_at')->orderBy('id')->limit(max(1, min(1000, $limit)))->get();
                DB::transaction(function () use ($rows, $kind, $store) {
                    foreach ($rows as $row) {
                        if ($kind === 'customers') {
                            $this->customer($store, $row);
                        } else {
                            $this->order($store, $row);
                        }
                    }
                });
                $more = $rows->count() === max(1, min(1000, $limit));
                $last = $rows->last();
                $state[$key] = $more ? ['until' => $until, 'cursor_at' => $last->updated_at->format('Y-m-d H:i:s'), 'cursor_id' => $last->id] : ['since' => \Carbon\CarbonImmutable::parse($until)->subMinutes(5)->format('Y-m-d H:i:s'), 'checked_at' => now()->toIso8601String()];
                $settings->update(['sync_state' => $state]);
                $counts[$kind] = ['read' => $rows->count(), 'pending' => $more];
            }
            return $counts;
        });
    }

    private function customer(Store $store, Customer $row): void
    {
        if (!filter_var($row->email, FILTER_VALIDATE_EMAIL)) return;
        $email = strtolower(trim($row->email));
        $contact = Contact::forStore($store)->firstOrNew(['email_hash' => Contacts::hash($email)]);
        $contact->fill(['organization_id' => $store->organization_id, 'store_id' => $store->id,
            'email_encrypted' => $email, 'name_encrypted' => trim($row->first_name.' '.$row->last_name),
            'customer_id' => 'gid://shopify/Customer/'.$row->shopify_customer_id, 'orders_count' => $row->orders_count]);
        if (!$contact->exists) $contact->fill(['source' => 'shopify', 'consent' => 'not_subscribed']);
        $contact->save(); // Shared Customer does not contain marketing consent; preserve existing consent exactly.
    }

    private function order(Store $store, Order $row): void
    {
        $contact = Contact::forStore($store)->where('customer_id', 'gid://shopify/Customer/'.$row->shopify_customer_id)->first();
        if (!$contact && filter_var($row->email, FILTER_VALIDATE_EMAIL)) $contact = Contact::forStore($store)->where('email_hash', Contacts::hash($row->email))->first();
        $snapshot = OrderSnapshot::forStore($store)->firstOrNew(['order_id' => 'gid://shopify/Order/'.$row->shopify_order_id]);
        $snapshot->fill(['organization_id' => $store->organization_id, 'store_id' => $store->id,
            'contact_id' => $contact?->id ?? $snapshot->contact_id, 'name' => $row->order_number,
            'financial_status' => strtoupper((string) $row->financial_status), 'currency' => $row->currency,
            'total' => $row->total_price, 'refunded' => $row->refund_total ?? 0,
            'ordered_at' => $row->created_at_shopify, 'cancelled_at' => $row->cancelled_at]);
        $snapshot->save(); // Outstanding payment is not inferred from the order total.
    }
}
