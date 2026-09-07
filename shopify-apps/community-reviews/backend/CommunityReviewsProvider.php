<?php

namespace CommunityReviews;

use Illuminate\Support\ServiceProvider;

class CommunityReviewsProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/community_reviews.php', 'community_reviews');
    }

    public function boot(): void
    {
        foreach ([\App\Models\ModelAssetFolder::class, \App\Models\ModelAssetImage::class,
            \App\Models\ReputationMention::class, \App\Models\ReputationMentionProductMatch::class, \App\Models\ReputationSyncRun::class,
            \App\Models\Product::class, Models\ModelLink::class, Models\Settings::class,
            Models\Installation::class] as $model) {
            $model::observe(Observers\FeedChanged::class);
        }
        // Bulk folder clearing uses a query delete; its audit record is the commit signal.
        \App\Models\AuditLog::created(function ($audit) {
            if ($audit->store_id && (str_starts_with($audit->action, 'model_asset_') || str_starts_with($audit->action, 'reputation_'))) {
                \Illuminate\Support\Facades\DB::afterCommit(fn () => app(Services\FeedCache::class)->invalidate((int) $audit->organization_id, (int) $audit->store_id));
            }
        });
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../views', 'community-reviews');
        $this->loadRoutesFrom(__DIR__.'/../routes.php');
    }
}
