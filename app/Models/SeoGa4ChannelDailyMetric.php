<?php

namespace App\Models;

use App\Models\Concerns\ScopesToOrganizationStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['organization_id', 'store_id', 'metric_date', 'channel_group', 'total_revenue', 'sessions', 'synced_at'])]
class SeoGa4ChannelDailyMetric extends Model
{
    use ScopesToOrganizationStore;

    protected function casts(): array
    {
        return ['metric_date' => 'date', 'total_revenue' => 'decimal:6', 'sessions' => 'integer', 'synced_at' => 'datetime'];
    }
}
