<?php

namespace App\Models;

use App\Models\Concerns\ScopesToOrganizationStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id', 'store_id', 'meta_ad_account_id', 'level', 'entity_id',
    'account_external_id', 'meta_campaign_id', 'meta_ad_set_id', 'meta_ad_id',
    'date_start', 'date_stop', 'granularity', 'spend', 'impressions', 'reach', 'clicks',
    'inline_link_clicks', 'frequency', 'purchases', 'purchase_value', 'add_to_cart',
    'initiate_checkout', 'synced_at',
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
            'impressions' => 'integer',
            'reach' => 'integer',
            'clicks' => 'integer',
            'inline_link_clicks' => 'integer',
            'synced_at' => 'datetime',
        ];
    }
}
