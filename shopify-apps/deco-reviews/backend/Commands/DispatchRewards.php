<?php

namespace DecoReviews\Commands;

use App\Models\Store;
use DecoReviews\Models\Reward;
use DecoReviews\Services\RewardService;
use Illuminate\Console\Command;

class DispatchRewards extends Command
{
    protected $signature = 'deco-reviews:dispatch-rewards';

    protected $description = 'Issue bounded review rewards';

    public function handle(): int
    {
        if (! config('deco_reviews.reward_writes_enabled')) {
            return self::SUCCESS;
        }
        $stores = Store::whereIn('shopify_domain', config('deco_reviews.automation_stores', []))->where('status', 'active')->limit(50)->get();
        Reward::whereIn('store_id', $stores->pluck('id'))->where('status', 'scheduled')->where('due_at', '<=', now())
            ->orderBy('due_at')->limit(20)->get()->each(fn ($reward) => app(RewardService::class)
            ->process((int) $reward->organization_id, (int) $reward->store_id, $reward->uuid));
        Reward::whereIn('store_id', $stores->pluck('id'))->where('status', 'issuing')->where('updated_at', '<', now()->subMinutes(10))
            ->update(['status' => 'held', 'due_at' => null, 'error_code' => 'SHOPIFY_RESULT_REQUIRES_REVIEW']);

        return self::SUCCESS;
    }
}
