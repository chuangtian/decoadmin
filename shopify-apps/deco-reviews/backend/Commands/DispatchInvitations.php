<?php

namespace DecoReviews\Commands;

use DecoReviews\Jobs\ProcessInvitation;
use DecoReviews\Models\Invitation;
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
        Invitation::whereIn('status', ['verification_required', 'waiting_fulfillment', 'scheduled'])
            ->where(fn ($q) => $q->whereNull('due_at')->orWhere('due_at', '<=', now()))
            ->where('expires_at', '>', now())->where('updated_at', '<', now()->subMinutes(5))
            ->orderBy('updated_at')->limit(20)->get()->each(function ($invite) {
                $invite->touch();
                ProcessInvitation::dispatch((int) $invite->organization_id, (int) $invite->store_id, $invite->uuid);
            });
        // A killed SMTP process is uncertain, not retryable.
        Invitation::where('status', 'sending')->where('updated_at', '<', now()->subMinutes(10))->update(['status' => 'held', 'error_code' => 'DELIVERY_RESULT_REQUIRES_REVIEW']);

        return self::SUCCESS;
    }
}
