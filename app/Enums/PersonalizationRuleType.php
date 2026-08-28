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
}
