<?php

namespace DecoReviews\Services;

use App\Models\Store;
use Carbon\CarbonImmutable;
use DecoReviews\Models\Invitation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class InvitationProcessor
{
    public function process(int $organizationId, int $storeId, string $uuid): void
    {
        $store = Store::where('organization_id', $organizationId)->whereKey($storeId)->where('status', 'active')->whereHas('organization', fn ($q) => $q->where('status', 'active'))->first();
        if (! $store || ! in_array($store->shopify_domain, config('deco_reviews.automation_stores', []), true)) {
            return;
        }
        $settings = app(ReviewService::class)->settings($store);
        if (! $settings['invites_enabled']) {
            return;
        }
        $lock = Cache::lock('deco-reviews:invite:'.$organizationId.':'.$storeId.':'.$uuid, 120);
        if (! $lock->get()) {
            return;
        }
        try {
            $invite = Invitation::where('organization_id', $organizationId)->where('store_id', $storeId)->where('uuid', $uuid)->first();
            $requestReminder = $invite && $invite->status === 'sent' && $invite->sent_at && ! $invite->reminder_sent_at && $settings['reminders_enabled']
                && $invite->sent_at->copy()->addDays($settings['reminder_days'])->lte(now());
            $mediaReminder = $invite && $invite->status === 'sent' && $invite->reminder_sent_at && ! $invite->media_reminder_sent_at
                && $settings['media_reminders_enabled'] && $invite->reminder_sent_at->copy()->addDays($settings['media_reminder_days'])->lte(now());
            $followupKind = $requestReminder ? 'reminder' : ($mediaReminder ? 'media_reminder' : null);
            if (! $invite || (! $followupKind && (! in_array($invite->status, ['verification_required', 'waiting_fulfillment', 'scheduled']) || $invite->sent_at))) {
                return;
            }
            // A concurrent unsubscribe/cancel/completion must not be overwritten after an API read.
            $transition = fn (array $values) => Invitation::where('organization_id', $organizationId)->where('store_id', $storeId)
                ->whereKey($invite->id)->where('status', $invite->status)->update($values);
            if ($invite->expires_at->isPast()) {
                $transition(['status' => 'expired', 'due_at' => null]);

                return;
            }
            if (app(InvitationService::class)->suppressed($store, $invite->email_hash)) {
                $transition(['status' => 'unsubscribed', 'due_at' => null]);

                return;
            }
            try {
                $snapshot = app(ShopifyClient::class)->order($store, (string) $invite->order->shopify_order_id);
            } catch (\Throwable) {
                $transition(['error_code' => 'ORDER_VERIFICATION_UNAVAILABLE']);

                return;
            }
            $order = $snapshot['order'] ?? null;
            if (! $order || ! empty($order['cancelledAt']) || ($order['displayFinancialStatus'] ?? '') !== 'PAID') {
                $transition(['status' => 'cancelled', 'error_code' => 'ORDER_NOT_ELIGIBLE', 'due_at' => null]);

                return;
            }
            $email = strtolower(trim((string) ($order['email'] ?? '')));
            if (! hash_equals(app(ReviewService::class)->emailHash($store, $email), $invite->email_hash)) {
                $transition(['status' => 'verification_required', 'error_code' => 'ORDER_EMAIL_CHANGED']);

                return;
            }
            if ($settings['marketing_only'] && (data_get($order, 'customer.defaultEmailAddress.marketingState') !== 'SUBSCRIBED'
                || strtolower((string) data_get($order, 'customer.defaultEmailAddress.emailAddress')) !== $email)) {
                $transition(['status' => 'verification_required', 'error_code' => 'CONSENT_REQUIRED']);

                return;
            }
            $expected = preg_replace('#^gid://shopify/Product/#', '', (string) $invite->product?->shopify_product_id);
            if (! is_array(data_get($order, 'lineItems.nodes')) || data_get($order, 'lineItems.pageInfo.hasNextPage', true) || count($order['fulfillments'] ?? []) >= 100) {
                $transition(['error_code' => 'ORDER_PAGE_INCOMPLETE']);

                return;
            }
            $orderedQuantity = 0;
            foreach ($order['lineItems']['nodes'] as $item) {
                if (preg_replace('#^gid://shopify/Product/#', '', (string) data_get($item, 'product.id')) === $expected) {
                    $orderedQuantity += max(0, (int) ($item['currentQuantity'] ?? 0));
                }
            }
            $fulfilledAt = null;
            $fulfilledQuantity = 0;
            foreach ($order['fulfillments'] ?? [] as $fulfillment) {
                if (($fulfillment['status'] ?? '') !== 'SUCCESS') {
                    continue;
                }
                if (data_get($fulfillment, 'fulfillmentLineItems.pageInfo.hasNextPage')) {
                    $transition(['error_code' => 'FULFILLMENT_PAGE_INCOMPLETE']);

                    return;
                }
                foreach (data_get($fulfillment, 'fulfillmentLineItems.nodes', []) as $item) {
                    $productId = preg_replace('#^gid://shopify/Product/#', '', (string) data_get($item, 'lineItem.product.id'));
                    if ($productId !== '' && $productId === $expected && ($item['quantity'] ?? 0) > 0) {
                        $fulfilledQuantity += (int) $item['quantity'];
                        $time = CarbonImmutable::parse($fulfillment['createdAt']);
                        $fulfilledAt = $fulfilledAt === null || $time->gt($fulfilledAt) ? $time : $fulfilledAt;
                    }
                }
            }
            if (! $fulfilledAt || $orderedQuantity < 1 || $fulfilledQuantity < $orderedQuantity) {
                $transition(['status' => 'waiting_fulfillment', 'due_at' => null, 'error_code' => null]);

                return;
            }
            $domestic = data_get($order, 'shippingAddress.countryCodeV2') && data_get($order, 'shippingAddress.countryCodeV2') === data_get($snapshot, 'shop.shopAddress.countryCodeV2');
            $due = $fulfilledAt->addDays($settings[$domestic ? 'domestic_delay_days' : 'international_delay_days']);
            if ($followupKind === 'reminder') {
                $due = $invite->sent_at->copy()->addDays($settings['reminder_days']);
            } elseif ($followupKind === 'media_reminder') {
                $due = $invite->reminder_sent_at->copy()->addDays($settings['media_reminder_days']);
            }
            $sendingState = match ($followupKind) {
                'reminder' => 'sending_reminder', 'media_reminder' => 'sending_media_reminder', default => 'sending',
            };
            DB::transaction(function () use ($store, $invite, $due, $followupKind, $sendingState) {
                $fresh = Invitation::where('organization_id', $store->organization_id)->where('store_id', $store->id)->whereKey($invite->id)->lockForUpdate()->firstOrFail();
                $followupInvalid = $followupKind === 'reminder'
                    ? ($fresh->status !== 'sent' || $fresh->reminder_sent_at)
                    : ($followupKind === 'media_reminder' && ($fresh->status !== 'sent' || ! $fresh->reminder_sent_at || $fresh->media_reminder_sent_at));
                if ($followupInvalid || (! $followupKind && (! in_array($fresh->status, ['verification_required', 'waiting_fulfillment', 'scheduled']) || $fresh->sent_at))) {
                    return;
                }
                $fresh->update(['status' => $followupKind ? 'sent' : 'scheduled', 'due_at' => $due, 'error_code' => null]);
                if ($due->isFuture() || ! app(InvitationDelivery::class)->allowed($store, $fresh)) {
                    return;
                }
                // Persist intent before performing the external side effect.
                $fresh->update(['status' => $sendingState, 'attempts' => $fresh->attempts + 1]);
            });
            $invite->refresh();
            if ($invite->status !== $sendingState) {
                return;
            }
            try {
                $sent = app(InvitationDelivery::class)->send($store, $invite);
            } catch (\Throwable) {
                $sent = false;
            }
            $sentAtField = match ($followupKind) {
                'reminder' => 'reminder_sent_at', 'media_reminder' => 'media_reminder_sent_at', default => 'sent_at',
            };
            $heldState = match ($followupKind) {
                'reminder' => 'reminder_held', 'media_reminder' => 'media_reminder_held', default => 'held',
            };
            Invitation::whereKey($invite->id)->where('status', $sendingState)->update($sent
                ? ['status' => 'sent', $sentAtField => now(), 'due_at' => null, 'error_code' => null]
                : ['status' => $heldState, 'due_at' => null, 'error_code' => 'DELIVERY_RESULT_REQUIRES_REVIEW']);
            $action = $followupKind ? 'invitation.'.$followupKind.($sent ? '_sent' : '_held') : 'invitation.'.($sent ? 'sent' : 'held');
            app(ReviewService::class)->audit($store, null, $action, null, ['invitation' => $uuid]);
        } finally {
            $lock->release();
        }
    }
}
