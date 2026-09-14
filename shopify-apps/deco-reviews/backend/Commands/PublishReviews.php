<?php

namespace DecoReviews\Commands;

use App\Models\Store;
use DecoReviews\Models\Review;
use DecoReviews\Services\ReviewService;
use DecoReviews\Services\RewardService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PublishReviews extends Command
{
    protected $signature = 'deco-reviews:publish';

    protected $description = 'Publish due reviews using their captured publication policy, independently of rating';

    public function handle(ReviewService $service): int
    {
        $count = 0;
        Review::where('status', 'pending')->whereNotNull('publish_at')->where('publish_at', '<=', now())->orderBy('id')->limit(500)->get(['id', 'organization_id', 'store_id'])->each(function ($candidate) use ($service, &$count) {
            DB::transaction(function () use ($candidate, $service, &$count) {
                $store = Store::where('organization_id', $candidate->organization_id)->whereKey($candidate->store_id)->where('status', 'active')->whereHas('organization', fn ($q) => $q->where('status', 'active'))->first();
                if (! $store) {
                    return;
                }
                $review = $service->scoped($store)->whereKey($candidate->id)->where('status', 'pending')->where('publish_at', '<=', now())->lockForUpdate()->first();
                if (! $review) {
                    return;
                }
                $review->update(['status' => 'published', 'published_at' => now(), 'publish_at' => null]);
                app(RewardService::class)->schedule($store, $review);
                $service->audit($store, null, 'review.auto_published', $review->id);
                $count++;
            });
        });
        $this->info("Published {$count} reviews.");

        return self::SUCCESS;
    }
}
