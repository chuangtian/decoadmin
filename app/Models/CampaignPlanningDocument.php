<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'organization_id', 'store_id', 'campaign_activity_id',
    'source_type', 'source_node_token', 'source_document_token', 'source_url',
    'title', 'source_revision_id', 'content_blocks', 'rendered_html', 'plain_text',
    'local_pdf_path', 'content_hash', 'sync_status', 'synced_at', 'last_error',
])]
class CampaignPlanningDocument extends Model
{
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function campaignActivity(): BelongsTo
    {
        return $this->belongsTo(CampaignActivity::class);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(CampaignPlanningAsset::class);
    }

    public function scopeForOrganization(Builder $query, Organization|int $organization): void
    {
        $query->where('organization_id', $organization instanceof Organization ? $organization->getKey() : $organization);
    }

    public function scopeForStore(Builder $query, Store|int $store): void
    {
        $query->where('store_id', $store instanceof Store ? $store->getKey() : $store);
    }

    protected function casts(): array
    {
        return [
            'content_blocks' => 'array',
            'synced_at' => 'datetime',
        ];
    }
}
