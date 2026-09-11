<?php

namespace DecoMarketing\Services;

use App\Models\Store;
use DecoMarketing\Models\Campaign;
use DecoMarketing\Models\Delivery;
use DecoMarketing\Models\Enrollment;
use Illuminate\Support\Facades\DB;

class Campaigns
{
    public function expand(Store $store): void
    {
        app(Guard::class)->store($store);
        foreach (Campaign::forStore($store)->where('status', 'sending')->where('scheduled_at', '<=', now())->orderBy('id')->limit(10)->get() as $campaign) {
            if ($this->unhealthy($store, $campaign)) {
                $campaign->update(['status' => 'paused', 'stop_reason' => 'unsubscribe_limit']);

                continue;
            }
            if ($campaign->expanded_at) {
                $this->chooseWinner($store, $campaign);

                continue;
            }
            $contacts = app(Audiences::class)->query($store, $campaign->audience)->where('id', '>', $campaign->cursor)
                ->orderBy('id')->limit(200)->get();
            foreach ($contacts as $contact) {
                $bucket = hexdec(substr(hash('sha256', $campaign->uuid.'|'.$contact->uuid), 0, 6)) % 100;
                $variant = $campaign->variant_b ? ($bucket < $campaign->test_percent ? ($bucket % 2 ? 'B' : 'A') : 'hold') : 'A';
                $content = $variant === 'B' ? $campaign->variant_b : $campaign->content;
                Enrollment::firstOrCreate(['organization_id' => $store->organization_id, 'store_id' => $store->id, 'contact_id' => $contact->id, 'flow_key' => 'campaign', 'source_key' => $campaign->uuid], [
                    'campaign_id' => $campaign->id, 'variant' => $variant, 'status' => $variant === 'hold' ? 'waiting' : 'active',
                    'steps' => [['content' => $content, 'after_minutes' => 0]], 'next_at' => $campaign->scheduled_at,
                    'context_encrypted' => ['mode' => config('marketing.transport') === 'preview' ? 'preview' : 'live', 'occurred_at' => $campaign->scheduled_at->toIso8601String()],
                ]);
            }
            $campaign->update(['cursor' => $contacts->last()?->id ?? $campaign->cursor, 'expanded_at' => $contacts->count() < 200 ? now() : null]);
        }
    }

    public function unhealthy(Store $store, Campaign $campaign): bool
    {
        return app(Health::class)->campaign($store, $campaign)['exceeded'];
    }

    private function chooseWinner(Store $store, Campaign $campaign): void
    {
        if (! $campaign->variant_b || $campaign->winner) {
            return;
        }
        $sample = Enrollment::forStore($store)->where('campaign_id', $campaign->id)->whereIn('variant', ['A', 'B']);
        if ((clone $sample)->whereIn('status', ['active', 'held'])->exists()) {
            return;
        }
        if (! $campaign->test_ends_at) {
            $campaign->update(['test_ends_at' => now()->addHours(24)]);

            return;
        }
        if ($campaign->test_ends_at->isFuture()) {
            return;
        }
        $rates = [];
        foreach (['A', 'B'] as $variant) {
            $q = Delivery::forStore($store)->where('kind', 'automation')->whereNotNull('sent_at')->whereHas('enrollment', fn ($q) => $q->where('campaign_id', $campaign->id)->where('variant', $variant));
            $total = (clone $q)->count();
            $opens = (clone $q)->whereNotNull('human_opened_at')->count();
            $rates[$variant] = $total ? $opens / $total : 0;
        }
        // Compare filtered opens. With no classified observations, keep the holdout queued.
        if (max($rates) === 0) {
            return;
        }
        $winner = $rates['B'] > $rates['A'] ? 'B' : 'A';
        $content = $winner === 'B' ? $campaign->variant_b : $campaign->content;
        DB::transaction(function () use ($store, $campaign, $winner, $content) {
            $campaign->update(['winner' => $winner, 'winner_at' => now()]);
            Enrollment::forStore($store)->where('campaign_id', $campaign->id)->where('status', 'waiting')->where('variant', 'hold')->update([
                'variant' => $winner, 'status' => 'active', 'next_at' => now(), 'steps' => json_encode([['content' => $content, 'after_minutes' => 0]]),
            ]);
        });
    }

    public function complete(Store $store): void
    {
        app(Guard::class)->store($store);
        foreach (Campaign::forStore($store)->where('status', 'sending')->whereNotNull('expanded_at')->limit(20)->get() as $campaign) {
            if ($this->unhealthy($store, $campaign)) {
                $campaign->update(['status' => 'paused', 'stop_reason' => 'unsubscribe_limit']);

                continue;
            }
            if (! Enrollment::forStore($store)->where('campaign_id', $campaign->id)->whereIn('status', ['active', 'waiting', 'held'])->exists()) {
                $campaign->update(['status' => 'completed']);
            }
        }
    }
}
