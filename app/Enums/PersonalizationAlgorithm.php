<?php

namespace App\Enums;

enum PersonalizationAlgorithm: string
{
    case Manual = 'manual';
    case BestSeller = 'best_seller';
    case NewArrivals = 'new_arrivals';
    case FrequentlyBoughtTogether = 'frequently_bought_together';
    case RecentlyViewed = 'recently_viewed';
    case SimilarProducts = 'similar_products';
}
