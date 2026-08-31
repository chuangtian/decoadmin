<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['organization_id', 'store_id', 'metric_date', 'placement', 'component_key', 'strategy_key', 'strategy_version_key', 'currency', 'impressions', 'clicks', 'add_to_carts', 'orders', 'attributed_revenue'])]
class PersonalizationDailyMetric extends Model
{
    protected function casts(): array
    {
        return [
            'metric_date' => 'date:Y-m-d',
            'impressions' => 'integer',
            'clicks' => 'integer',
            'add_to_carts' => 'integer',
            'orders' => 'integer',
            'attributed_revenue' => 'decimal:4',
        ];
    }
}
