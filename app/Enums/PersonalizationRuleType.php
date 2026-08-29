<?php

namespace App\Enums;

enum PersonalizationRuleType: string
{
    case IncludeTags = 'include_tags';
    case ExcludeTags = 'exclude_tags';
    case MinimumPrice = 'minimum_price';
    case MaximumPrice = 'maximum_price';
    case MinimumInventory = 'minimum_inventory';
    case InStockOnly = 'in_stock_only';
    case IncludeCollections = 'include_collections';
    case ExcludeCollections = 'exclude_collections';
    case ExcludeVendors = 'exclude_vendors';
    case ExcludePurchaseOptions = 'exclude_purchase_options';
    case ExcludeCartProducts = 'exclude_cart_products';
    case ExcludePurchasedProducts = 'exclude_purchased_products';
}
