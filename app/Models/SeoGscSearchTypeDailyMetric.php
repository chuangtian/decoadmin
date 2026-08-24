<?php

namespace App\Models;

use App\Models\Concerns\ScopesToOrganizationStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['organization_id', 'store_id', 'metric_date', 'search_type', 'clicks', 'impressions', 'average_position', 'synced_at'])]
class SeoGscSearchTypeDailyMetric extends Model
{
    use ScopesToOrganizationStore;

    protected function casts(): array
    {
        return ['metric_date' => 'date', 'clicks' => 'integer', 'impressions' => 'integer', 'average_position' => 'decimal:6', 'synced_at' => 'datetime'];
    }
}
