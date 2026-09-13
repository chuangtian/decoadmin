<?php

namespace DecoReviews\Commands;

use App\Models\Store;
use DecoReviews\Jobs\ProcessInvitation;
use DecoReviews\Models\Invitation;
use DecoReviews\Services\InvitationService;
use DecoReviews\Services\ReviewService;
use Illuminate\Console\Command;

class DispatchInvitations extends Command
{
    protected $signature = 'deco-reviews:dispatch';

    protected $description = 'Dispatch bounded, scoped review invitation verification';

    public function handle(): int
    {
        if (config('deco_reviews.automation_stores', []) === []) {
            return self::SUCCESS;
        }
        $stores = Store::whereIn('shopify_domain', config('deco_reviews.automation_stores', []))->where('status', 'active')
            ->whereHas('organization', fn ($q) => $q->where('status', 'active'))->limit(50)->get();
        foreach ($stores as $store) {
            app(InvitationService::class)->discover($store);
            $settings = app(ReviewService::class)->settings($store);
            if (! $settings['invites_enabled'] || (! $settings['reminders_enabled'] && ! $settings['media_reminders_enabled'])) {
                continue;
            }
            Invitation::where('organization_id', $store->organization_id)->where('store_id', $store->id)->where('status', 'sent')
                ->where('expires_at', '>', now())->where(function ($query) use ($settings) {
                    if ($settings['reminders_enabled']) {
                        $query->orWhere(fn ($candidate) => $candidate->whereNull('reminder_sent_at')
                            ->where('sent_at', '<=', now()->subDays($settings['reminder_days'])));
                    }
                    if ($settings['media_reminders_enabled']) {
                        $query->orWhere(fn ($candidate) => $candidate->whereNotNull('reminder_sent_at')->whereNull('media_reminder_sent_at')
                            ->where('reminder_sent_at', '<=', now()->subDays($settings['media_reminder_days'])));
                    }
                })->where('updated_at', '<', now()->subMinutes(5))
                ->orderBy('updated_at')->limit(20)->get()->each(function ($invite) {
                    $invite->touch();
                    ProcessInvitation::dispatch((int) $invite->organization_id, (int) $invite->store_id, $invite->uuid);
                });
        }
        Invitation::whereIn('status', ['verification_required', 'waiting_fulfillment', 'scheduled'])
            ->whereIn('store_id', $stores->pluck('id'))
            ->where(fn ($q) => $q->whereNull('due_at')->orWhere('due_at', '<=', now()))
            ->where('expires_at', '>', now())->where('updated_at', '<', now()->subMinutes(5))
            ->orderBy('updated_at')->limit(20)->get()->each(function ($invite) {
                $invite->touch();
                ProcessInvitation::dispatch((int) $invite->organization_id, (int) $invite->store_id, $invite->uuid);
            });
        // A killed SMTP process is uncertain, not retryable.
        Invitation::whereIn('store_id', $stores->pluck('id'))->where('status', 'sending')->where('updated_at', '<', now()->subMinutes(10))->update(['status' => 'held', 'error_code' => 'DELIVERY_RESULT_REQUIRES_REVIEW']);
        Invitation::whereIn('store_id', $stores->pluck('id'))->where('status', 'sending_reminder')->where('updated_at', '<', now()->subMinutes(10))->update(['status' => 'reminder_held', 'error_code' => 'DELIVERY_RESULT_REQUIRES_REVIEW']);
        Invitation::whereIn('store_id', $stores->pluck('id'))->where('status', 'sending_media_reminder')->where('updated_at', '<', now()->subMinutes(10))->update(['status' => 'media_reminder_held', 'error_code' => 'DELIVERY_RESULT_REQUIRES_REVIEW']);

        return self::SUCCESS;
    }
}
