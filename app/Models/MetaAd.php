<?php

namespace App\Models;

use App\Models\Concerns\ScopesToOrganizationStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id', 'store_id', 'meta_ad_account_id', 'meta_ad_id', 'meta_campaign_id',
    'meta_ad_set_id', 'meta_creative_id', 'name', 'status', 'effective_status', 'tracking_specs',
    'conversion_specs', 'source_created_at', 'source_updated_at', 'raw_payload', 'last_seen_at',
    'synced_at',
])]
class MetaAd extends Model
{
    use ScopesToOrganizationStore;

    public function account(): BelongsTo
    {
        return $this->belongsTo(MetaAdAccount::class, 'meta_ad_account_id');
    }

    protected function casts(): array
    {
        return [
            'tracking_specs' => 'array',
            'conversion_specs' => 'array',
            'source_created_at' => 'datetime',
            'source_updated_at' => 'datetime',
            'raw_payload' => 'array',
            'last_seen_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }
}
