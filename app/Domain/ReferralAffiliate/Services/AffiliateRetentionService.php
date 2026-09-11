<?php

namespace App\Domain\ReferralAffiliate\Services;

use App\Domain\ReferralAffiliate\Models\AffiliateClick;
use App\Domain\ReferralAffiliate\Models\AffiliateNotificationIntent;
use App\Models\Store;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\DB;

class AffiliateRetentionService
{
    public function prune(Store $store): array
    {
        app(AffiliateShopGuard::class)->store($store);

        return DB::transaction(function () use ($store) {
            Store::query()->whereKey($store->id)->lockForUpdate()->firstOrFail();
            // Attribution windows are capped at 90 days. Keep a further 90 days for delayed reconciliation.
            $clicks = AffiliateClick::query()->forOrganization($store->organization_id)->forStore($store)->where('occurred_at', '<', now()->subDays(180))->orderBy('id')->limit(1000)->lockForUpdate()->get();
            foreach ($clicks->groupBy(fn ($c) => $c->membership_id.'|'.$c->occurred_at->toDateString()) as $group) {
                $first = $group->first();
                $key = ['organization_id' => $store->organization_id, 'store_id' => $store->id, 'membership_id' => $first->membership_id, 'day' => $first->occurred_at->toDateString()];
                $total = DB::table('affiliate_click_totals')->where($key)->first();
                DB::table('affiliate_click_totals')->updateOrInsert($key, ['clicks' => (int) ($total->clicks ?? 0) + $group->count()]);
            }
            AffiliateClick::query()->forOrganization($store->organization_id)->forStore($store)->whereIn('id', $clicks->pluck('id'))->delete();
            $counts = ['clicks' => $clicks->count()];
            foreach (['affiliate_portal_tokens', 'affiliate_invitations'] as $table) {
                $ids = DB::table($table)->where('organization_id', $store->organization_id)->where('store_id', $store->id)->where('expires_at', '<', now()->subDays(30))->orderBy('id')->limit(1000)->pluck('id');
                $counts[$table] = DB::table($table)->whereIn('id', $ids)->delete();
            }
            $messages = AffiliateNotificationIntent::query()->where('organization_id', $store->organization_id)->where('store_id', $store->id)->whereNull('redacted_at')->where(function ($q) {
                $q->where(fn ($q) => $q->whereIn('status', ['sent', 'suppressed'])->where('created_at', '<', now()->subDays(30)))
                    ->orWhere(fn ($q) => $q->where('event_key', 'portal.login')->where('created_at', '<', now()->subDay()));
            })->orderBy('id')->limit(1000)->get();
            foreach ($messages as $message) {
                $message->update(['message_encrypted' => [], 'redacted_at' => now(), 'status' => $message->status === 'sent' ? 'sent' : 'suppressed']);
            }
            $counts['messages'] = $messages->count();
            $events = WebhookEvent::query()->where('organization_id', $store->organization_id)->where('store_id', $store->id)->whereHas('app', fn ($q) => $q->where('handle', config('referral.active.handle')))
                ->where('status', 'processed')->whereNotNull('payload_encrypted')->where('processed_at', '<', now()->subDays(30))->orderBy('id')->limit(1000)->get();
            foreach ($events as $event) {
                $event->update(['payload_encrypted' => null, 'payload' => ['storage' => 'redacted'], 'payload_sha256' => null]);
            }
            $counts['webhook_payloads'] = $events->count();

            return $counts;
        });
    }
}
