<?php

namespace App\Enums;

enum PersonalizationAlgorithm: string
{
    case Manual = 'manual';
    case NextLlm = 'next_llm';
    case FreeShippingUpsell = 'free_shipping_upsell';
    case SimilarProducts = 'similar_products';
    case SubstituteProducts = 'substitute_products';
    case BestSeller = 'best_seller';
    case NewArrivals = 'new_arrivals';
    case FrequentlyBoughtTogether = 'frequently_bought_together';
    case FrequentlyViewedTogether = 'frequently_viewed_together';
    case ComplementaryProducts = 'complementary_products';
    case RecentlyViewed = 'recently_viewed';
    case CompleteTheLook = 'complete_the_look';
    case SameProductUpsell = 'same_product_upsell';
    case AllProducts = 'all_products';
}
