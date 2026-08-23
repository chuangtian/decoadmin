<?php

namespace App\Models;

use App\Models\Concerns\ScopesToOrganizationStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id', 'store_id', 'meta_ad_account_id', 'meta_ad_set_id', 'meta_campaign_id',
    'name', 'status', 'effective_status', 'optimization_goal', 'billing_event', 'bid_strategy',
    'daily_budget', 'lifetime_budget', 'budget_remaining', 'start_time', 'end_time',
    'source_created_at', 'source_updated_at', 'targeting', 'promoted_object', 'raw_payload',
    'last_seen_at', 'synced_at',
])]
class MetaAdSet extends Model
{
    use ScopesToOrganizationStore;

    public function account(): BelongsTo
    {
        return $this->belongsTo(MetaAdAccount::class, 'meta_ad_account_id');
    }

    protected function casts(): array
    {
        return [
            'start_time' => 'datetime',
            'end_time' => 'datetime',
            'source_created_at' => 'datetime',
            'source_updated_at' => 'datetime',
            'targeting' => 'array',
            'promoted_object' => 'array',
            'raw_payload' => 'array',
            'last_seen_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }
}
