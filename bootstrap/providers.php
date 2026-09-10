<?php

use App\Providers\AppServiceProvider;
use App\Providers\HorizonServiceProvider;
use CommunityReviews\CommunityReviewsProvider;

return [
    AppServiceProvider::class,
    HorizonServiceProvider::class,
    CommunityReviewsProvider::class,
    \DecoMarketing\MarketingProvider::class,
];
