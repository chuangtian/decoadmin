<?php

namespace DecoReviews\Commands;

use App\Models\Store;
use DecoReviews\Models\EmailDelivery;
use DecoReviews\Services\ReviewEmailService;
use Illuminate\Console\Command;

class DispatchReviewEmails extends Command
{
    protected $signature = 'deco-reviews:dispatch-emails';

    protected $description = 'Dispatch bounded review lifecycle emails';

    public function handle(): int
    {
        $stores = Store::whereIn('shopify_domain', config('deco_reviews.automation_stores', []))->where('status', 'active')
            ->whereHas('organization', fn ($query) => $query->where('status', 'active'))->limit(50)->get();
        EmailDelivery::whereIn('store_id', $stores->pluck('id'))->where('status', 'scheduled')
            ->where(fn ($query) => $query->whereNull('due_at')->orWhere('due_at', '<=', now()))
            ->orderBy('due_at')->limit(20)->get()->each(fn ($delivery) => app(ReviewEmailService::class)
                ->process((int) $delivery->organization_id, (int) $delivery->store_id, $delivery->uuid));
        EmailDelivery::whereIn('store_id', $stores->pluck('id'))->where('status', 'sending')
            ->where('updated_at', '<', now()->subMinutes(10))->update(['status' => 'held', 'error_code' => 'DELIVERY_RESULT_REQUIRES_REVIEW']);

        return self::SUCCESS;
    }
}
