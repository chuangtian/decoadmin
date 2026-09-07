<?php

namespace CommunityReviews\Observers;

use CommunityReviews\Services\FeedCache;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Database\Eloquent\Model;

class FeedChanged implements ShouldHandleEventsAfterCommit
{
    public function saved(Model $model): void { $this->invalidate($model); }
    public function deleted(Model $model): void { $this->invalidate($model); }

    private function invalidate(Model $model): void
    {
        if ($model->organization_id && $model->store_id) {
            app(FeedCache::class)->invalidate((int) $model->organization_id, (int) $model->store_id);
        }
    }
}
