<?php

namespace App\Models;

use App\Models\Concerns\ScopesToOrganizationStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id', 'store_id', 'meta_ad_account_id', 'level', 'entity_id',
    'account_external_id', 'account_name', 'meta_campaign_id', 'campaign_name',
    'meta_ad_set_id', 'ad_set_name', 'meta_ad_id', 'ad_name', 'date_start', 'date_stop',
    'granularity', 'hourly_range', 'hour_start_at', 'hour_end_at',
    'spend', 'impressions', 'reach', 'clicks', 'unique_clicks', 'inline_link_clicks', 'ctr',
    'unique_ctr', 'cpc', 'cpm', 'cpp', 'frequency', 'purchases', 'purchase_value',
    'add_to_cart', 'initiate_checkout', 'leads', 'landing_page_views', 'cost_per_purchase',
    'purchase_roas', 'outbound_clicks', 'actions', 'action_values', 'cost_per_action_type',
    'purchase_roas_breakdown', 'website_purchase_roas', 'raw_payload', 'synced_at',
])]
class MetaAdInsight extends Model
{
    use ScopesToOrganizationStore;

    public function account(): BelongsTo
    {
        return $this->belongsTo(MetaAdAccount::class, 'meta_ad_account_id');
    }

    protected function casts(): array
    {
        return [
            'date_start' => 'date',
            'date_stop' => 'date',
            'hour_start_at' => 'datetime',
            'hour_end_at' => 'datetime',
            'impressions' => 'integer',
            'reach' => 'integer',
            'clicks' => 'integer',
            'unique_clicks' => 'integer',
            'inline_link_clicks' => 'integer',
            'outbound_clicks' => 'array',
            'actions' => 'array',
            'action_values' => 'array',
            'cost_per_action_type' => 'array',
            'purchase_roas_breakdown' => 'array',
            'website_purchase_roas' => 'array',
            'raw_payload' => 'array',
            'synced_at' => 'datetime',
        ];
    }
}
