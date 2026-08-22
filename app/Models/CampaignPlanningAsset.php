<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'campaign_planning_document_id', 'organization_id', 'store_id',
    'source_file_token_hash', 'asset_type', 'original_name',
    'local_disk', 'local_path', 'local_url', 'mime_type', 'file_size',
    'width', 'height', 'content_hash',
])]
class CampaignPlanningAsset extends Model
{
    public function planningDocument(): BelongsTo
    {
        return $this->belongsTo(CampaignPlanningDocument::class, 'campaign_planning_document_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
