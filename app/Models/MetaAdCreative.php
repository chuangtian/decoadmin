<?php

namespace App\Models;

use App\Models\Concerns\ScopesToOrganizationStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id', 'store_id', 'meta_ad_account_id', 'meta_creative_id', 'name', 'title',
    'body', 'call_to_action_type', 'object_story_id', 'image_url', 'thumbnail_url', 'url_tags',
    'asset_feed_spec', 'object_story_spec', 'raw_payload', 'last_seen_at', 'synced_at',
])]
class MetaAdCreative extends Model
{
    use ScopesToOrganizationStore;

    public function account(): BelongsTo
    {
        return $this->belongsTo(MetaAdAccount::class, 'meta_ad_account_id');
    }

    protected function casts(): array
    {
        return [
            'asset_feed_spec' => 'array',
            'object_story_spec' => 'array',
            'raw_payload' => 'array',
            'last_seen_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }
}
