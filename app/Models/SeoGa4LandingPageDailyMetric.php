<?php

namespace App\Models;

use App\Models\Concerns\ScopesToOrganizationStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'organization_id', 'store_id', 'metric_date', 'landing_page_hash', 'landing_page', 'channel_group',
    'sessions', 'active_users', 'new_users', 'engagement_duration', 'key_events', 'total_revenue',
    'bounce_rate', 'session_key_event_rate', 'synced_at',
])]
class SeoGa4LandingPageDailyMetric extends Model
{
    use ScopesToOrganizationStore;

    protected function casts(): array
    {
        return [
            'metric_date' => 'date', 'sessions' => 'integer', 'active_users' => 'integer', 'new_users' => 'integer',
            'engagement_duration' => 'decimal:6', 'key_events' => 'decimal:6', 'total_revenue' => 'decimal:6',
            'bounce_rate' => 'decimal:8', 'session_key_event_rate' => 'decimal:8', 'synced_at' => 'datetime',
        ];
    }
}
